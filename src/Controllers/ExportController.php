<?php

namespace DropshippingCommunicatorSuflix\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use DropshippingCommunicatorSuflix\Services\TxtFileService;
use DropshippingCommunicatorSuflix\Services\PlaceholderService;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Mail\Templates\Contracts\Service\EmailService\EmailTemplatesSendServiceContract;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;

class ExportController extends Controller
{
    use Loggable;

    public function sendOrder(Request $request, Response $response, int $orderId): \Symfony\Component\HttpFoundation\Response
    {
        $this->getLogger(__METHOD__)->info('DropshippingCommunicatorSuflix::ExportController.start', [
            'orderId' => $orderId
        ]);

        try {
            /** @var OrderRepositoryContract $orderRepo */
            $orderRepo = pluginApp(OrderRepositoryContract::class);
            $order = $orderRepo->findOrderById($orderId);

            $this->getLogger(__METHOD__)->info('DropshippingCommunicatorSuflix::ExportController.orderLoaded', [
                'orderId' => $orderId
            ]);

            /** @var ConfigRepository $config */
            $config = pluginApp(ConfigRepository::class);

            /** @var TxtFileService $txtService */
            $txtService = pluginApp(TxtFileService::class);

            /** @var PlaceholderService $placeholderService */
            $placeholderService = pluginApp(PlaceholderService::class);

            /** @var EmailTemplatesSendServiceContract $mailService */
            $mailService = pluginApp(EmailTemplatesSendServiceContract::class);

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

            $this->getLogger(__METHOD__)->info('DropshippingCommunicatorSuflix::ExportController.configLoaded', [
                'recipients' => $recipients,
                'subject'    => $subject,
                'txtLength'  => strlen($txtContent),
            ]);

            if (empty($recipients)) {
                $this->getLogger(__METHOD__)->error('DropshippingCommunicatorSuflix::ExportController.noRecipients', []);
                return $response->json(['success' => false, 'message' => 'Keine Empfänger konfiguriert.'], 500);
            }

            $attachments = [[
                'fileName' => $txtFilename,
                'mimeType' => 'text/plain',
                'content'  => base64_encode($txtContent),
            ]];

            if (!empty($order->documents)) {
                foreach ($order->documents as $doc) {
                    if ((string)($doc->type ?? '') === 'deliveryNote' && !empty($doc->content)) {
                        $number = (string)($doc->number ?? $doc->displayNumber ?? $orderId);
                        $attachments[] = [
                            'fileName' => 'Lieferschein_' . $number . '.pdf',
                            'mimeType' => 'application/pdf',
                            'content'  => $doc->content,
                        ];
                        break;
                    }
                }
            }

            $payload = [
                'subject'     => $subject,
                'body'        => $body,
                'receivers'   => array_map(fn($email) => ['email' => $email], $recipients),
                'bcc'         => array_map(fn($email) => ['email' => $email], $bcc),
                'attachments' => $attachments,
            ];

            $this->getLogger(__METHOD__)->info('DropshippingCommunicatorSuflix::ExportController.sendingMail', [
                'payload_keys' => array_keys($payload),
                'receivers'    => $payload['receivers'],
            ]);

            $result = $mailService->sendPreview($payload);

            $this->getLogger(__METHOD__)->info('DropshippingCommunicatorSuflix::ExportController.mailSent', [
                'result' => $result,
            ]);

            return $response->json([
                'success'    => true,
                'message'    => 'E-Mail erfolgreich versendet.',
                'recipients' => $recipients,
                'result'     => $result,
            ]);

        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->error('DropshippingCommunicatorSuflix::ExportController.error', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
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
