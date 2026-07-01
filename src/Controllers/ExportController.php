<?php

namespace DropshippingCommunicatorSuflix\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Plugin\Mail\Contracts\MailerContract;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Plugin\Storage\Contracts\StorageRepositoryContract;
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

            /** @var StorageRepositoryContract $storage */
            $storage = pluginApp(StorageRepositoryContract::class);

            /** @var MailerContract $mailer */
            $mailer = pluginApp(MailerContract::class);

            // TXT generieren
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

            // TXT als GuzzleHttp Stream hochladen
            $txtKey    = 'export/' . $orderId . '_' . time() . '.txt';
            $txtStream = \GuzzleHttp\Psr7\Utils::streamFor($txtContent);
            $txtObject = $storage->uploadObject(self::PLUGIN_NAME, $txtKey, $txtStream);

            $attachments = [$txtObject];

            // Lieferschein anhängen falls vorhanden
            $orderArray = json_decode(json_encode($order), true);
            foreach (($orderArray['documents'] ?? []) as $doc) {
                if (($doc['type'] ?? '') === 'deliveryNote' && !empty($doc['content'])) {
                    $pdfKey    = 'export/ls_' . $orderId . '_' . time() . '.pdf';
                    $pdfStream = \GuzzleHttp\Psr7\Utils::streamFor(base64_decode($doc['content']));
                    $pdfObject = $storage->uploadObject(self::PLUGIN_NAME, $pdfKey, $pdfStream);
                    $attachments[] = $pdfObject;
                    break;
                }
            }

            // E-Mail mit Anhang senden
            $mailer->sendHtml($body, $recipients, $subject, [], $bcc, null, $attachments);

            return $response->json([
                'success'    => true,
                'message'    => 'E-Mail mit TXT-Anhang versendet!',
                'recipients' => $recipients,
                'subject'    => $subject,
                'txt'        => $txtFilename,
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
