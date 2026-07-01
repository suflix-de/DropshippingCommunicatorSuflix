<?php

namespace DropshippingCommunicatorSuflix\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Plugin\Mail\Contracts\MailerContract;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Authorization\Services\AuthHelper;
use DropshippingCommunicatorSuflix\Services\TxtFileService;
use DropshippingCommunicatorSuflix\Services\PlaceholderService;

class ExportController extends Controller
{
    public function sendOrder(Request $request, Response $response, int $orderId): \Symfony\Component\HttpFoundation\Response
    {
        try {
            /** @var AuthHelper $authHelper */
            $authHelper = pluginApp(AuthHelper::class);

            /** @var OrderRepositoryContract $orderRepo */
            $orderRepo = pluginApp(OrderRepositoryContract::class);

            /** @var AddressRepositoryContract $addressRepo */
            $addressRepo = pluginApp(AddressRepositoryContract::class);

            // Auftrag mit Dokumenten laden
            $order = $authHelper->processUnguarded(function() use ($orderRepo, $orderId) {
                try {
                    return $orderRepo->findOrderById($orderId, ['documents', 'addresses', 'orderItems']);
                } catch (\Throwable $e) {
                    return $orderRepo->findOrderById($orderId);
                }
            });
            $orderArray = json_decode(json_encode($order), true);

            // ── Adressen separat laden ───────────────────────────────────────
            $addresses = [];
            foreach (($orderArray['addressRelations'] ?? []) as $relation) {
                $addrId = (int)($relation['addressId'] ?? 0);
                $typeId = (int)($relation['typeId'] ?? 0);
                if ($addrId > 0) {
                    try {
                        $addr = $authHelper->processUnguarded(fn() => $addressRepo->findAddressById($addrId));
                        if ($addr) {
                            $arr           = json_decode(json_encode($addr), true);
                            $arr['typeId'] = $typeId;
                            $addresses[]   = $arr;
                        }
                    } catch (\Throwable $e) {}
                }
            }

            // ── Ext. Varianten-IDs laden ─────────────────────────────────────
            $variationExtIds = [];
            $variationIds    = [];
            foreach (($orderArray['orderItems'] ?? []) as $item) {
                $vid = (string)($item['itemVariationId'] ?? '');
                if ($vid !== '' && $vid !== '0') $variationIds[] = $vid;
            }
            $variationIds = array_unique($variationIds);

            if (!empty($variationIds)) {
                try {
                    /** @var \Plenty\Modules\Item\Variation\Contracts\VariationRepositoryContract $varRepo */
                    $varRepo = pluginApp(\Plenty\Modules\Item\Variation\Contracts\VariationRepositoryContract::class);
                    foreach ($variationIds as $vid) {
                        $variation = $authHelper->processUnguarded(fn() => $varRepo->findById((int)$vid));
                        if ($variation) {
                            $varArr = json_decode(json_encode($variation), true);
                            // Externe Varianten-Nr. = number oder externalId
                            $extId  = (string)($varArr['number'] ?? $varArr['externalId'] ?? $varArr['model'] ?? '');
                            if ($extId !== '') $variationExtIds[$vid] = $extId;
                        }
                    }
                } catch (\Throwable $e) {}
            }

            // ── Dokumente laden ──────────────────────────────────────────────
            $documents = [];
            /** @var \Plenty\Modules\Document\Contracts\DocumentRepositoryContract $docRepo */
            $docRepo = pluginApp(\Plenty\Modules\Document\Contracts\DocumentRepositoryContract::class);

            // Dokumente aus Order-Array (wenn mit documents geladen)
            $orderArray2 = json_decode(json_encode($order), true);
            if (!empty($orderArray2['documents'])) {
                $documents = $orderArray2['documents'];
            }

            // Fallback: DocumentRepository direkt abfragen
            if (empty($documents)) {
                try {
                    $docs = $authHelper->processUnguarded(fn() => $docRepo->listDocuments(['orderId' => $orderId]));
                    if (!empty($docs)) $documents = is_array($docs) ? $docs : [];
                } catch (\Throwable $e) {}
            }

            if (empty($documents)) {
                try {
                    $docs = $authHelper->processUnguarded(fn() => $docRepo->listOrderDocuments($orderId));
                    if (!empty($docs)) $documents = is_array($docs) ? $docs : [];
                } catch (\Throwable $e) {}
            }

            if (empty($documents)) {
                try {
                    $docs = $authHelper->processUnguarded(fn() => $docRepo->findByOrderId($orderId));
                    if (!empty($docs)) $documents = is_array($docs) ? $docs : [];
                } catch (\Throwable $e) {}
            }

            /** @var ConfigRepository $config */
            $config = pluginApp(ConfigRepository::class);

            /** @var TxtFileService $txtService */
            $txtService = pluginApp(TxtFileService::class);

            /** @var PlaceholderService $placeholderService */
            $placeholderService = pluginApp(PlaceholderService::class);

            /** @var MailerContract $mailer */
            $mailer = pluginApp(MailerContract::class);

            $txtContent = $txtService->build($order, $addresses, $documents, $variationExtIds);
            if (empty($txtContent)) {
                $txtContent = 'k;fehler;' . $orderId . ';;;;;;;;;;;;;;' . "\r\n";
            }

            $txtFilename = $placeholderService->replace(
                (string)$config->get('DropshippingCommunicatorSuflix.txt.filename', 'Sanitaerfixx_Auftrag_[OrderId].txt'),
                $order
            );
            $subject = $placeholderService->replace(
                (string)$config->get('DropshippingCommunicatorSuflix.mail.subject', 'Neue Bestellung [OrderId]'),
                $order
            );
            $body = $placeholderService->replace(
                (string)$config->get('DropshippingCommunicatorSuflix.mail.body', 'Neue Bestellung'),
                $order
            );
            $recipients = $this->splitEmails((string)$config->get('DropshippingCommunicatorSuflix.mail.recipients', ''));
            $bcc        = $this->splitEmails((string)$config->get('DropshippingCommunicatorSuflix.mail.bcc', ''));

            if (empty($recipients)) {
                return $response->json(['success' => false, 'message' => 'Keine Empfänger konfiguriert.'], 500);
            }

            // ── TXT StorageObject (key = Dateiname!) ─────────────────────────
            $txtObject       = pluginApp(\Plenty\Modules\Cloud\Storage\Models\StorageObject::class);
            $txtObject->key  = $txtFilename;   // key wird als Dateiname verwendet!
            $txtObject->body = $txtContent;
            $attachments     = [$txtObject];

            // ── Lieferschein StorageObject ───────────────────────────────────
            foreach ($documents as $doc) {
                $docArr = is_array($doc) ? $doc : json_decode(json_encode($doc), true);
                if (($docArr['type'] ?? '') === 'deliveryNote' && !empty($docArr['content'])) {
                    $pdfNum        = (string)($docArr['numberWithPrefix'] ?? $docArr['number'] ?? $docArr['displayNumber'] ?? $orderId);
                    $pdfObject     = pluginApp(\Plenty\Modules\Cloud\Storage\Models\StorageObject::class);
                    $pdfObject->key  = 'delivery_note_' . $pdfNum . '.pdf';
                    $pdfObject->body = base64_decode($docArr['content']);
                    $attachments[]   = $pdfObject;
                    break;
                }
            }

            $mailer->sendHtml($body, $recipients, $subject, [], $bcc, null, $attachments);

            return $response->json([
                'success'     => true,
                'message'     => 'E-Mail versendet!',
                'attachments' => count($attachments),
                'txt_preview' => substr($txtContent, 0, 300),
                'docs_loaded' => count($documents),
            ]);

        } catch (\Throwable $e) {
            return $response->json(['success' => false, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 500);
        }
    }

    private function splitEmails(string $raw): array
    {
        $parts  = preg_split('/[,;]+/', $raw);
        $result = [];
        foreach ($parts as $email) {
            $email = trim($email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $result[] = $email;
        }
        return array_values(array_unique($result));
    }
}
