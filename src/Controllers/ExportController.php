<?php

namespace DropshippingCommunicatorSuflix\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Plugin\Mail\Contracts\MailerContract;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Authorization\Services\AuthHelper;
use DropshippingCommunicatorSuflix\Services\TxtFileService;
use DropshippingCommunicatorSuflix\Services\PlaceholderService;

class ExportController extends Controller
{
    const PLUGIN_NAME = 'DropshippingCommunicatorSuflix';

    public function sendOrder(Request $request, Response $response, int $orderId): \Symfony\Component\HttpFoundation\Response
    {
        try {
            /** @var AuthHelper $authHelper */
            $authHelper = pluginApp(AuthHelper::class);

            /** @var OrderRepositoryContract $orderRepo */
            $orderRepo = pluginApp(OrderRepositoryContract::class);

            $order = $authHelper->processUnguarded(function() use ($orderRepo, $orderId) {
                return $orderRepo->findOrderById($orderId);
            });

            /** @var ConfigRepository $config */
            $config = pluginApp(ConfigRepository::class);

            /** @var TxtFileService $txtService */
            $txtService = pluginApp(TxtFileService::class);

            /** @var PlaceholderService $placeholderService */
            $placeholderService = pluginApp(PlaceholderService::class);

            /** @var MailerContract $mailer */
            $mailer = pluginApp(MailerContract::class);

            // ── Debug: Order-Struktur analysieren ───────────────────────────
            $orderArray = json_decode(json_encode($order), true);
            $debugInfo  = [
                'order_keys'           => array_keys($orderArray),
                'addresses_sample'     => array_slice($orderArray['addresses'] ?? [], 0, 1),
                'addressRelations'     => array_slice($orderArray['addressRelations'] ?? [], 0, 1),
                'relations'            => array_slice($orderArray['relations'] ?? [], 0, 1),
                'documents_count'      => count($orderArray['documents'] ?? []),
                'documents_types'      => array_column($orderArray['documents'] ?? [], 'type'),
            ];

            $txtContent = $txtService->build($order);
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

            // ── Lieferschein StorageObject (aus Order-Dokumenten) ────────────
            foreach (($orderArray['documents'] ?? []) as $doc) {
                if (($doc['type'] ?? '') === 'deliveryNote' && !empty($doc['content'])) {
                    $pdfNum    = (string)($doc['numberWithPrefix'] ?? $doc['number'] ?? $doc['displayNumber'] ?? $orderId);
                    $pdfObject = pluginApp(\Plenty\Modules\Cloud\Storage\Models\StorageObject::class);
                    $pdfObject->key      = 'ls_' . $orderId . '_' . time() . '.pdf';
                    $pdfObject->body     = base64_decode($doc['content']);
                    $pdfObject->filename = 'Lieferschein_' . $pdfNum . '.pdf';
                    $attachments[] = $pdfObject;
                    break;
                }
            }

            // ── E-Mail senden ────────────────────────────────────────────────
            $mailer->sendHtml($body, $recipients, $subject, [], $bcc, null, $attachments);

            return $response->json([
                'success'     => true,
                'message'     => 'E-Mail versendet!',
                'recipients'  => $recipients,
                'attachments' => count($attachments),
                'txt_preview' => substr($txtContent, 0, 300),
                'debug'       => $debugInfo,
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
