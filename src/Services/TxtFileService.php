<?php

namespace DropshippingCommunicatorSuflix\Services;

use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Authorization\Services\AuthHelper;

class TxtFileService
{
    public function build($order, array $addresses = []): string
    {
        /** @var ConfigRepository $config */
        $config = pluginApp(ConfigRepository::class);
        $customerNo = (string)$config->get('DropshippingCommunicatorSuflix.supplier.customerNo', '11061');

        $orderArray = is_array($order) ? $order : json_decode(json_encode($order), true);
        $orderId    = (string)($orderArray['id'] ?? '');

        // Lieferadresse finden (typeId 2 = Lieferadresse, typeId 1 = Rechnungsadresse)
        $deliveryAddress = null;

        // Aus übergebenen Adressen suchen
        foreach ($addresses as $addr) {
            if ((int)($addr['typeId'] ?? 0) === 2) {
                $deliveryAddress = $addr;
                break;
            }
        }
        // Fallback auf typeId 1
        if ($deliveryAddress === null) {
            foreach ($addresses as $addr) {
                $deliveryAddress = $addr;
                break;
            }
        }

        // Felder auslesen
        $company      = '';
        $salutation   = '';
        $fullName     = '';
        $addition     = '';
        $street       = '';
        $country      = 'DE';
        $plz          = '';
        $city         = '';
        $phone        = '';
        $email        = '';

        if ($deliveryAddress !== null) {
            $company    = (string)($deliveryAddress['name1'] ?? '');
            $gender     = strtolower((string)($deliveryAddress['gender'] ?? ''));
            $salutation = $gender === 'male' ? 'Herr' : ($gender === 'female' ? 'Frau' : '');
            $name2      = (string)($deliveryAddress['name2'] ?? '');
            $name3      = (string)($deliveryAddress['name3'] ?? '');
            $fullName   = trim($name2 . ' ' . $name3);
            $addition   = (string)($deliveryAddress['address3'] ?? '');
            $addr1      = (string)($deliveryAddress['address1'] ?? '');
            $addr2      = (string)($deliveryAddress['address2'] ?? '');
            $street     = trim($addr1 . ' ' . $addr2);
            $country    = strtoupper((string)($deliveryAddress['countryIso'] ?? $deliveryAddress['countryCode'] ?? 'DE'));
            $plz        = (string)($deliveryAddress['postalCode'] ?? '');
            $city       = (string)($deliveryAddress['town'] ?? '');

            foreach (($deliveryAddress['options'] ?? []) as $opt) {
                $typeId = (int)($opt['typeId'] ?? 0);
                $value  = (string)($opt['value'] ?? '');
                if ($typeId === 4 && $value !== '') $phone = $value;
                if ($typeId === 5 && $value !== '') $email = $value;
                if (filter_var($value, FILTER_VALIDATE_EMAIL)) $email = $value;
            }
        }

        // Lieferscheinnummer aus Auftrag
        $deliveryNoteNum = '';
        foreach (($orderArray['documents'] ?? []) as $doc) {
            if (($doc['type'] ?? '') === 'deliveryNote') {
                $deliveryNoteNum = (string)($doc['numberWithPrefix'] ?? $doc['number'] ?? '');
                break;
            }
        }

        $lines = [];

        // k-Zeile
        $lines[] = implode(';', [
            'k',
            $customerNo,
            $orderId,
            $company,
            $salutation,
            $fullName,
            $addition,
            $street,
            $country,
            $plz,
            $city,
            $phone,
            $deliveryNoteNum,
            $email,
            '',
        ]);

        // p-Zeilen
        $bundleIds  = $this->getBundleVariationIds($config);
        $orderItems = $orderArray['orderItems'] ?? [];

        foreach ($orderItems as $item) {
            $typeId      = (string)($item['typeId'] ?? '1');
            $variationId = (string)($item['itemVariationId'] ?? '');

            if (!in_array($typeId, ['1', '2'], true)) continue;
            if (in_array($variationId, $bundleIds, true)) continue;

            // Externe ID
            $externeId = '';
            foreach (($item['properties'] ?? []) as $prop) {
                if ((int)($prop['typeId'] ?? 0) === 26) {
                    $externeId = (string)($prop['value'] ?? '');
                    break;
                }
            }
            if ($externeId === '') $externeId = (string)($item['itemVariationId'] ?? '');

            $qty  = (int)round((float)str_replace(',', '.', (string)($item['quantity'] ?? 1)));
            $name = (string)($item['orderItemName'] ?? '');

            $lines[] = implode(';', ['p', $externeId, $qty, $name, '']);
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
