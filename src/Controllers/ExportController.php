<?php

namespace DropshippingCommunicatorSuflix\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use DropshippingCommunicatorSuflix\Services\TxtFileService;
use DropshippingCommunicatorSuflix\Services\PlaceholderService;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Plugin\Mail\Contracts\MailerContract;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Plugin\Storage\Contracts\StorageRepositoryContract;

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

            /** @var StorageRepositoryContract $storage */
            $storage = pluginApp(StorageRepositoryContract::class);

            /** @var MailerContract $mailer */
            $mailer = pluginApp(MailerContract::class);

            $txtContent  = $txtService->build($order);
            $txtFilename = $placeholderService->replace(
                (string)$config->get('DropshippingCommunicatorSuflix.txt.filename', 'Auftrag_[OrderId].txt'),
                $order
            );
            $subject = $placeholderService->replace(
                (string)$config->get('DropshippingCommunicatorSuflix.mail.subject', 'Neue Bestellung [OrderId]'),
                $order
            );
            $body = $placeholderService->replace(
                (string)$config->get('DropshippingCommunicatorSuflix.mail.body', ''),
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

            // ── TXT als StorageObject hochladen ──────────────────────────────
            $attachments = [];
            $txtKey = 'export_' . $orderId . '_' . time() . '.txt';
            $txtObject = $storage->uploadObject(self::PLUGIN_NAME, $txtKey, $txtContent);
            $attachments[] = $txtObject;

            // Lieferschein anhängen falls vorhanden
            if (!empty($order->documents)) {
                foreach ($order->documents as $doc) {
                    if ((string)($doc->type ?? '') === 'deliveryNote' && !empty($doc->content)) {
                        $pdfKey    = 'lieferschein_' . $orderId . '_' . time() . '.pdf';
                        $pdfObject = $storage->uploadObject(self::PLUGIN_NAME, $pdfKey, base64_decode($doc->content));
                        $attachments[] = $pdfObject;
                        break;
                    }
                }
            }

            // ── E-Mail senden ────────────────────────────────────────────────
            // $recipients = Array of strings (plain email addresses)
            // $bcc        = Array of strings (plain email addresses)
            $mailer->sendHtml(
                $body,
                $recipients,
                $subject,
                [],
                $bcc,
                null,
                $attachments
            );

            return $response->json([
                'success'    => true,
                'message'    => 'E-Mail erfolgreich versendet.',
                'recipients' => $recipients,
                'subject'    => $subject,
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
