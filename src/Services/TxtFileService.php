<?php

namespace DropshippingCommunicatorSuflix\Services;

use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Authorization\Services\AuthHelper;

class TxtFileService
{
    public function build($order, array $addresses = [], array $documents = [], array $variationExtIds = []): string
    {
        /** @var ConfigRepository $config */
        $config = pluginApp(ConfigRepository::class);
        $customerNo = (string)$config->get('DropshippingCommunicatorSuflix.supplier.customerNo', '11061');

        $orderArray = is_array($order) ? $order : json_decode(json_encode($order), true);
        $orderId    = (string)($orderArray['id'] ?? '');

        // Lieferadresse (typeId 2), Fallback auf erste
        $deliveryAddress = null;
        foreach ($addresses as $addr) {
            if ((int)($addr['typeId'] ?? 0) === 2) { $deliveryAddress = $addr; break; }
        }
        if ($deliveryAddress === null && !empty($addresses)) {
            $deliveryAddress = $addresses[0];
        }

        $company = $salutation = $fullName = $addition = $street = $plz = $city = $phone = $email = '';
        $country = 'DE';

        if ($deliveryAddress !== null) {
            $company    = (string)($deliveryAddress['name1'] ?? '');
            $gender     = strtolower((string)($deliveryAddress['gender'] ?? ''));
            $salutation = $gender === 'male' ? 'Herr' : ($gender === 'female' ? 'Frau' : '');
            $fullName   = trim(($deliveryAddress['name2'] ?? '') . ' ' . ($deliveryAddress['name3'] ?? ''));
            $addition   = (string)($deliveryAddress['address3'] ?? '');
            $street     = trim(($deliveryAddress['address1'] ?? '') . ' ' . ($deliveryAddress['address2'] ?? ''));
            $country    = strtoupper((string)($deliveryAddress['countryIso'] ?? $deliveryAddress['countryCode'] ?? 'DE'));
            $plz        = (string)($deliveryAddress['postalCode'] ?? '');
            $city       = (string)($deliveryAddress['town'] ?? '');
            foreach (($deliveryAddress['options'] ?? []) as $opt) {
                $val = (string)($opt['value'] ?? '');
                if ((int)($opt['typeId'] ?? 0) === 4 && $val !== '') $phone = $val;
                if (filter_var($val, FILTER_VALIDATE_EMAIL)) $email = $val;
            }
        }

        // Lieferscheinnummer aus Dokumenten
        $deliveryNoteNum = '';
        foreach ($documents as $doc) {
            $docArr = is_array($doc) ? $doc : json_decode(json_encode($doc), true);
            if (($docArr['type'] ?? '') === 'deliveryNote') {
                $deliveryNoteNum = (string)($docArr['numberWithPrefix'] ?? $docArr['number'] ?? $docArr['displayNumber'] ?? '');
                break;
            }
        }

        $lines   = [];
        $lines[] = implode(';', ['k', $customerNo, $orderId, $company, $salutation, $fullName, $addition, $street, $country, $plz, $city, $phone, $deliveryNoteNum, $email, '']);

        // p-Zeilen
        $bundleIds = $this->getBundleVariationIds($config);
        foreach (($orderArray['orderItems'] ?? []) as $item) {
            $typeId      = (string)($item['typeId'] ?? '1');
            $variationId = (string)($item['itemVariationId'] ?? '');
            if (!in_array($typeId, ['1', '2'], true)) continue;
            if (in_array($variationId, $bundleIds, true)) continue;

            // Externe ID: aus variationExtIds, dann Properties typeId 26, dann Fallback
            $externeId = $variationExtIds[$variationId] ?? '';
            if ($externeId === '') {
                foreach (($item['properties'] ?? []) as $prop) {
                    if ((int)($prop['typeId'] ?? 0) === 26) { $externeId = (string)($prop['value'] ?? ''); break; }
                }
            }
            if ($externeId === '') $externeId = $variationId;

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
