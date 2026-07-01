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

        // Auftrag als Array normalisieren
        $orderArray = is_array($order) ? $order : json_decode(json_encode($order), true);

        $orderId    = (string)($orderArray['id'] ?? '');
        $addresses  = $orderArray['addresses'] ?? [];
        $orderItems = $orderArray['orderItems'] ?? [];
        $documents  = $orderArray['documents'] ?? [];

        // Lieferadresse finden (typeId 2)
        $deliveryAddress = null;
        foreach ($addresses as $address) {
            if ((int)($address['typeId'] ?? 0) === 2) {
                $deliveryAddress = $address;
                break;
            }
        }
        if ($deliveryAddress === null && !empty($addresses)) {
            $deliveryAddress = $addresses[0];
        }

        // Lieferscheinnummer
        $deliveryNoteNumber = '';
        foreach ($documents as $doc) {
            if (($doc['type'] ?? '') === 'deliveryNote') {
                $deliveryNoteNumber = (string)($doc['numberWithPrefix'] ?? $doc['number'] ?? $doc['displayNumber'] ?? '');
                break;
            }
        }

        // E-Mail aus Address-Options
        $email = '';
        foreach (($deliveryAddress['options'] ?? []) as $opt) {
            $val = (string)($opt['value'] ?? '');
            if (filter_var($val, FILTER_VALIDATE_EMAIL)) {
                $email = $val;
                break;
            }
        }
        if ($email === '') {
            $email = (string)($deliveryAddress['email'] ?? '');
        }

        // Telefon aus Address-Options
        $phone = '';
        foreach (($deliveryAddress['options'] ?? []) as $opt) {
            if ((int)($opt['typeId'] ?? 0) === 4) {
                $phone = (string)($opt['value'] ?? '');
                break;
            }
        }

        // Anrede
        $gender = strtolower((string)($deliveryAddress['gender'] ?? ''));
        $salutation = $gender === 'male' ? 'Herr' : ($gender === 'female' ? 'Frau' : '');

        // Name
        $name2 = (string)($deliveryAddress['name2'] ?? '');
        $name3 = (string)($deliveryAddress['name3'] ?? '');
        $fullName = trim($name2 . ' ' . $name3);
        if ($fullName === '') {
            $fullName = trim(
                ((string)($deliveryAddress['firstName'] ?? '')) . ' ' .
                ((string)($deliveryAddress['lastName'] ?? ''))
            );
        }

        // Straße
        $street = trim(
            ((string)($deliveryAddress['address1'] ?? $deliveryAddress['street'] ?? '')) . ' ' .
            ((string)($deliveryAddress['address2'] ?? $deliveryAddress['houseNumber'] ?? ''))
        );

        // Land
        $country = strtoupper((string)($deliveryAddress['countryIso'] ?? $deliveryAddress['countryIsoCode'] ?? $deliveryAddress['countryCode'] ?? 'DE'));

        $lines = [];

        // ── k-Zeile ──────────────────────────────────────────────────────────
        $lines[] = implode(';', [
            'k',
            $customerNo,
            $orderId,
            (string)($deliveryAddress['name1'] ?? $deliveryAddress['companyName'] ?? ''),
            $salutation,
            $fullName,
            (string)($deliveryAddress['address3'] ?? $deliveryAddress['additional'] ?? ''),
            $street,
            $country,
            (string)($deliveryAddress['postalCode'] ?? ''),
            (string)($deliveryAddress['town'] ?? $deliveryAddress['city'] ?? ''),
            $phone,
            $deliveryNoteNumber,
            $email,
            '',
        ]);

        // ── p-Zeilen ─────────────────────────────────────────────────────────
        $bundleIds = $this->getBundleVariationIds($config);

        foreach ($orderItems as $item) {
            $typeId      = (string)($item['typeId'] ?? '1');
            $variationId = (string)($item['itemVariationId'] ?? '');

            // Nur Varianten-Positionen (typeId 1) und Bundle-Kinder (2)
            if (!in_array($typeId, ['1', '2'], true)) {
                continue;
            }

            // Bundle-Eltern überspringen
            if (in_array($variationId, $bundleIds, true)) {
                continue;
            }

            // Externe ID aus Properties
            $externeId = '';
            foreach (($item['properties'] ?? []) as $prop) {
                if ((int)($prop['typeId'] ?? 0) === 26) {
                    $externeId = (string)($prop['value'] ?? '');
                    break;
                }
            }
            if ($externeId === '') {
                $externeId = (string)($item['itemVariationId'] ?? '');
            }

            $qty  = (int)round((float)str_replace(',', '.', (string)($item['quantity'] ?? 1)));
            $name = (string)($item['orderItemName'] ?? '');

            $lines[] = implode(';', [
                'p',
                $externeId,
                $qty,
                $name,
                '',
            ]);
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    private function getBundleVariationIds(ConfigRepository $config): array
    {
        $raw = (string)$config->get('DropshippingCommunicatorSuflix.items.bundleVariationIds', '');
        if (trim($raw) === '') return [];
        return array_values(array_filter(array_map('trim', preg_split('/[;,\s]+/', $raw))));
    }
}
