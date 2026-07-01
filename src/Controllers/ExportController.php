<?php

namespace DropshippingCommunicatorSuflix\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Document\Contracts\DocumentRepositoryContract;
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

            /** @var DocumentRepositoryContract $documentRepo */
            $documentRepo = pluginApp(DocumentRepositoryContract::class);

            $order = $authHelper->processUnguarded(function() use ($orderRepo, $orderId) {
                return $orderRepo->findOrderById($orderId);
            });

            $orderArray = json_decode(json_encode($order), true);

            // ── Adressen separat laden ───────────────────────────────────────
            $addresses = [];
            foreach (($orderArray['addressRelations'] ?? []) as $relation) {
                $addressId = (int)($relation['addressId'] ?? 0);
                $typeId    = (int)($relation['typeId'] ?? 0);
                if ($addressId > 0) {
                    try {
                        $addr = $authHelper->processUnguarded(function() use ($addressRepo, $addressId) {
                            return $addressRepo->findAddressById($addressId);
                        });
                        if ($addr) {
                            $addrArray           = json_decode(json_encode($addr), true);
                            $addrArray['typeId'] = $typeId;
                            $addresses[]         = $addrArray;
                        }
                    } catch (\Throwable $e) {
                        // Adresse nicht gefunden – überspringen
                    }
                }
            }

            // ── Dokumente separat laden ──────────────────────────────────────
            $documents = [];
            try {
                $docs = $authHelper->processUnguarded(function() use ($documentRepo, $orderId) {
                    return $documentRepo->find($orderId, 'deliveryNote');
                });
                if ($docs) {
                    $documents = is_array($docs) ? $docs : [$docs];
                }
            } catch (\Throwable $e) {
                // Anderen Methodennamen versuchen
                try {
                    $docs = $authHelper->processUnguarded(function() use ($documentRepo, $orderId) {
                        return $documentRepo->getDocumentsByOrderId($orderId);
                    });
                    if ($docs) {
                        $documents = is_array($docs) ? $docs : [$docs];
                    }
                } catch (\Throwable $e2) {
                    // Dokumente nicht verfügbar
                }
            }

            /** @var ConfigRepository $config */
            $config = pluginApp(ConfigRepository::class);

            /** @var TxtFileService $txtService */
            $txtService = pluginApp(TxtFileService::class);

            /** @var PlaceholderService $placeholderService */
            $placeholderService = pluginApp(PlaceholderService::class);

            /** @var MailerContract $mailer */
            $mailer = pluginApp(MailerContract::class);

            // TXT mit Adressen generieren
            $txtContent = $txtService->build($order, $addresses);
            if (empty($txtContent)) {
                $txtContent = 'k;fehler;' . $orderId . ';;;;;;;;;;;;;;' . "\r\n";
            }

            $txtFilename = $placeholderService->replace(
                (string)$config->get('DropshippingCommunicatorSuflix.txt.filename', 'Auftrag_[OrderId].txt'),
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
            $recipients = $this->splitEmails(
                (string)$config->get('DropshippingCommunicatorSuflix.mail.recipients', '')
            );
            $bcc = $this->splitEmails(
                (string)$config->get('DropshippingCommunicatorSuflix.mail.bcc', '')
            );

            if (empty($recipients)) {
                return $response->json(['success' => false, 'message' => 'Keine Empfänger konfiguriert.'], 500);
            }

            // ── TXT StorageObject ────────────────────────────────────────────
            $txtObject           = pluginApp(\Plenty\Modules\Cloud\Storage\Models\StorageObject::class);
            $txtObject->key      = 'txt_' . $orderId . '_' . time() . '.txt';
            $txtObject->body     = $txtContent;
            $txtObject->filename = $txtFilename;
            $attachments = [$txtObject];

            // ── Lieferschein StorageObject ───────────────────────────────────
            $deliveryNoteNum = '';
            foreach ($documents as $doc) {
                $docArray = json_decode(json_encode($doc), true);
                $docType  = (string)($docArray['type'] ?? '');
                if ($docType === 'deliveryNote' && !empty($docArray['content'])) {
                    $deliveryNoteNum = (string)($docArray['numberWithPrefix'] ?? $docArray['number'] ?? $docArray['displayNumber'] ?? '');
                    $pdfObject           = pluginApp(\Plenty\Modules\Cloud\Storage\Models\StorageObject::class);
                    $pdfObject->key      = 'ls_' . $orderId . '_' . time() . '.pdf';
                    $pdfObject->body     = base64_decode($docArray['content']);
                    $pdfObject->filename = 'Lieferschein_' . $deliveryNoteNum . '.pdf';
                    $attachments[]       = $pdfObject;
                    break;
                }
            }

            // ── E-Mail senden ────────────────────────────────────────────────
            $mailer->sendHtml($body, $recipients, $subject, [], $bcc, null, $attachments);

            return $response->json([
                'success'          => true,
                'message'          => 'E-Mail mit Anhang(en) versendet!',
                'recipients'       => $recipients,
                'attachments'      => count($attachments),
                'txt_preview'      => substr($txtContent, 0, 300),
                'addresses_loaded' => count($addresses),
                'documents_loaded' => count($documents),
            ]);

        } catch (\Throwable $e) {
            return $response->json([
                'success' => false,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ], 500);
        }
    }

    private function splitEmails(string $raw): array
    {
        $parts = preg_split('/[,;]+/', $raw);
        $result = [];
        foreach ($parts as $email) {
            $email = trim($email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $result[] = $email;
            }
        }
        return array_values(array_unique($result));
    }
}
