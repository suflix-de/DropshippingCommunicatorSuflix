<?php

namespace DropshippingCommunicatorSuflix\Services;

use Plenty\Plugin\ConfigRepository;

class TxtFileService
{
    public function build($order): string
    {
        /** @var ConfigRepository $config */
        $config = pluginApp(ConfigRepository::class);

        $customerNo = (string)$config->get('DropshippingCommunicatorSuflix.supplier.customerNo', '11061');

        $deliveryAddress = $this->getAddressByType($order, [2]) ?: $this->getFirstAddress($order);

        $deliveryNoteNumber = $this->getDeliveryNoteNumber($order);
        $email              = $this->getEmail($deliveryAddress);

        $lines = [];

        // ── k-Zeile (Kundendaten) ────────────────────────────────────────────
        $lines[] = $this->csvLine([
            'k',
            $customerNo,
            (string)($order->id ?? ''),
            $this->getCompany($deliveryAddress),
            $this->getSalutation($deliveryAddress),
            $this->getFullName($deliveryAddress),
            $this->getAddressAddition($deliveryAddress),
            $this->getStreet($deliveryAddress),
            $this->getCountryIso($deliveryAddress),
            $this->val($deliveryAddress, 'postalCode'),
            $this->val($deliveryAddress, 'town'),
            $this->getPhone($deliveryAddress),
            $deliveryNoteNumber,
            $email,
        ]);

        // ── p-Zeilen (Artikeldaten) ──────────────────────────────────────────
        $bundleIds = $this->getBundleVariationIds($config);

        if (!empty($order->orderItems)) {
            foreach ($order->orderItems as $item) {
                if (!$this->isRelevantItem($item, $bundleIds)) {
                    continue;
                }

                $lines[] = $this->csvLine([
                    'p',
                    $this->getExternalId($item),
                    $this->getQuantity($item),
                    $this->getItemName($item),
                ]);
            }
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function csvLine(array $fields): string
    {
        $clean = [];
        foreach ($fields as $field) {
            $field = str_replace(["\r", "\n", ";"], [' ', ' ', ','], (string)$field);
            $clean[] = trim($field);
        }
        return implode(';', $clean) . ';';
    }

    private function getAddressByType($order, array $typeIds)
    {
        if (empty($order->addresses)) {
            return null;
        }
        foreach ($order->addresses as $address) {
            if (in_array((int)($address->typeId ?? 0), $typeIds, true)) {
                return $address;
            }
        }
        return null;
    }

    private function getFirstAddress($order)
    {
        if (!empty($order->addresses)) {
            foreach ($order->addresses as $address) {
                return $address;
            }
        }
        return null;
    }

    private function getCompany($address): string
    {
        return $this->val($address, 'name1');
    }

    private function getSalutation($address): string
    {
        $gender = strtolower($this->val($address, 'gender'));
        if ($gender === 'male')   return 'Herr';
        if ($gender === 'female') return 'Frau';
        return '';
    }

    private function getFullName($address): string
    {
        $name = trim($this->val($address, 'name2') . ' ' . $this->val($address, 'name3'));
        if ($name !== '') return $name;
        return trim($this->val($address, 'firstName') . ' ' . $this->val($address, 'lastName'));
    }

    private function getAddressAddition($address): string
    {
        return $this->val($address, 'address3');
    }

    private function getStreet($address): string
    {
        $street = trim($this->val($address, 'address1') . ' ' . $this->val($address, 'address2'));
        if ($street !== '') return $street;
        return trim($this->val($address, 'street') . ' ' . $this->val($address, 'houseNumber'));
    }

    private function getCountryIso($address): string
    {
        $iso = strtoupper($this->val($address, 'countryIso'));
        if ($iso !== '') return $iso;
        return strtoupper($this->val($address, 'countryCode')) ?: 'DE';
    }

    private function getPhone($address): string
    {
        if (!empty($address->options)) {
            foreach ($address->options as $option) {
                // typeId 4 = Telefon in PlentyONE
                if ((string)($option->typeId ?? '') === '4') {
                    return (string)($option->value ?? '');
                }
            }
        }
        return '';
    }

    private function getEmail($address): string
    {
        if (!empty($address->options)) {
            foreach ($address->options as $option) {
                $value = (string)($option->value ?? '');
                if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return $value;
                }
            }
        }
        return $this->val($address, 'email');
    }

    private function getDeliveryNoteNumber($order): string
    {
        if (!empty($order->documents)) {
            foreach ($order->documents as $doc) {
                if ((string)($doc->type ?? '') === 'deliveryNote') {
                    return (string)($doc->number ?? $doc->displayNumber ?? $doc->id ?? '');
                }
            }
        }
        return '';
    }

    private function getBundleVariationIds(ConfigRepository $config): array
    {
        $raw = (string)$config->get('DropshippingCommunicatorSuflix.items.bundleVariationIds', '');
        if (trim($raw) === '') return [];
        $ids = preg_split('/[;,\s]+/', $raw);
        return array_values(array_filter(array_map('trim', $ids)));
    }

    private function isRelevantItem($item, array $bundleIds): bool
    {
        // Nur Varianten-Positionen (typeId 1) exportieren
        $typeId = (string)($item->typeId ?? '1');
        if (!in_array($typeId, ['1', 'variation'], true)) {
            return false;
        }

        // Bundle-Elternartikel überspringen
        $variationId = (string)($item->itemVariationId ?? '');
        if (in_array($variationId, $bundleIds, true)) {
            return false;
        }

        return true;
    }

    private function getExternalId($item): string
    {
        $variation = $item->variation ?? null;
        foreach (['externalId', 'externalVariationId', 'number', 'model'] as $field) {
            $value = $this->val($variation, $field);
            if ($value !== '') return $value;
        }
        return (string)($item->itemVariationId ?? '');
    }

    private function getQuantity($item): string
    {
        $qty = (float)str_replace(',', '.', (string)($item->quantity ?? 1));
        if ($qty <= 0) $qty = 1;
        return floor($qty) == $qty ? (string)(int)$qty : rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.');
    }

    private function getItemName($item): string
    {
        return (string)($item->orderItemName ?? '');
    }

    private function val($object, string $key): string
    {
        if ($object === null) return '';
        if (is_array($object))  return (string)($object[$key] ?? '');
        if (is_object($object)) { $arr = json_decode(json_encode($object), true); return (string)($arr[$key] ?? ''); }
        return '';
    }
}
