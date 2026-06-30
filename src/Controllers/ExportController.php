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
use Plenty\Modules\Plugin\Storage\Contracts\StorageRepositoryContract;

class ExportController extends Controller
{
    const PLUGIN_NAME = 'DropshippingCommunicatorSuflix';

    public function sendOrder(Request $request, Response $response, int $orderId): \Symfony\Component\HttpFoundation\Response
    {
        try {
            /** @var OrderRepositoryContract $orderRepo */
            $orderRepo = pluginApp(OrderRepositoryContract::class);
            $order = $orderRepo->findById($orderId, ['*'], [
                'amounts',
                'addresses',
                'orderItems.variation',
                'orderItems.properties',
                'properties',
                'documents',
            ]);

            /** @var ConfigRepository $config */
            $config = pluginApp(ConfigRepository::class);

            /** @var TxtFileService $txtService */
            $txtService = pluginApp(TxtFileService::class);

            /** @var PlaceholderService $placeholderService */
            $placeholderService = pluginApp(PlaceholderService::class);

            /** @var StorageRepositoryContract $storage */
            $storage = pluginApp(StorageRepositoryContract::class);

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

            // ── Anhänge als StorageObject hochladen ─────────────────────────────
            $attachments = [];

            $txtStorageKey = 'export_' . $orderId . '_' . time() . '.txt';
            $attachments[] = $storage->uploadObject(
                self::PLUGIN_NAME,
                $txtStorageKey,
                $txtContent
            );

            // Lieferschein anhängen falls vorhanden
            if (!empty($order->documents)) {
                foreach ($order->documents as $doc) {
                    if ((string)($doc->type ?? '') === 'deliveryNote' && !empty($doc->content)) {
                        $pdfStorageKey = 'lieferschein_' . $orderId . '_' . time() . '.pdf';
                        $attachments[] = $storage->uploadObject(
                            self::PLUGIN_NAME,
                            $pdfStorageKey,
                            base64_decode($doc->content)
                        );
                        break;
                    }
                }
            }

            // ── E-Mail über offizielle MailerContract-Methode senden ───────────
            /** @var MailerContract $mailer */
            $mailer = pluginApp(MailerContract::class);

            $mailer->sendHtml(
                $body,
                $recipients,
                $subject,
                [],
                $bcc,
                null,
                $attachments
            );

            return $response->json(['success' => true, 'message' => 'E-Mail erfolgreich versendet.']);

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
