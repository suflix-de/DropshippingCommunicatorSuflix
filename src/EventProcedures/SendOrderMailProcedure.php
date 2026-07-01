<?php

namespace DropshippingCommunicatorSuflix\EventProcedures;

use Exception;
use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Mail\Templates\Contracts\Service\EmailService\EmailTemplatesSendServiceContract;
use Plenty\Plugin\ConfigRepository;
use DropshippingCommunicatorSuflix\Services\TxtFileService;
use DropshippingCommunicatorSuflix\Services\PlaceholderService;

class SendOrderMailProcedure
{
    public function handle(EventProceduresTriggered $event): void
    {
        $orderId = (int)$event->getOrder()->id;

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

        // ── TXT erstellen ────────────────────────────────────────────────────
        $txtContent  = $txtService->build($order);
        $txtFilename = $placeholderService->replace(
            (string)$config->get('DropshippingCommunicatorSuflix.txt.filename', 'Auftrag_[OrderId].txt'),
            $order
        );

        // ── E-Mail-Daten ─────────────────────────────────────────────────────
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
            throw new Exception('Suflix DropshippingCommunicator: Keine Empfänger konfiguriert.');
        }

        // ── Anhänge aufbauen ─────────────────────────────────────────────────
        $attachments = [[
            'fileName' => $txtFilename,
            'mimeType' => 'text/plain',
            'content'  => base64_encode($txtContent),
        ]];

        // Lieferschein-PDF anhängen falls vorhanden
        $deliveryNote = $this->findDeliveryNote($order);
        if ($deliveryNote !== null) {
            $attachments[] = $deliveryNote;
        }

        // ── E-Mail versenden via PlentyONE EmailService ──────────────────────
        /** @var EmailTemplatesSendServiceContract $mailService */
        $mailService = pluginApp(EmailTemplatesSendServiceContract::class);

        $payload = [
            'subject'     => $subject,
            'body'        => $body,
            'receivers'   => array_map(fn($email) => ['email' => $email], $recipients),
            'bcc'         => array_map(fn($email) => ['email' => $email], $bcc),
            'attachments' => $attachments,
        ];

        $mailService->sendPreview($payload);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

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

    private function findDeliveryNote($order): ?array
    {
        if (empty($order->documents)) {
            return null;
        }
        foreach ($order->documents as $doc) {
            if ((string)($doc->type ?? '') === 'deliveryNote' && !empty($doc->content)) {
                $number = (string)($doc->number ?? $doc->displayNumber ?? $order->id);
                return [
                    'fileName' => 'Lieferschein_' . $number . '.pdf',
                    'mimeType' => 'application/pdf',
                    'content'  => $doc->content,
                ];
            }
        }
        return null;
    }
}
