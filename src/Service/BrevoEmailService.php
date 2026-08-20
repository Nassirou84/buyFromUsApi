<?php

declare(strict_types=1);

namespace App\Service;

use Exception;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function is_array;
use function is_string;

use const FILTER_VALIDATE_EMAIL;

class BrevoEmailService
{
    private const BASE_URL = 'https://api.brevo.com/v3';

    private HttpClientInterface $client;
    private ?LoggerInterface $logger;
    private Address $sender;
    private MailerInterface $mailer;

    public function __construct(
        MailerInterface $mailer,
        string $apiKey,
        string $fromEmail,
        string $fromName = 'No Reply',
        ?LoggerInterface $logger = null,
    ) {
        $this->client = HttpClient::create([
            'headers' => [
                'api-key' => $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
        $this->logger = $logger;
        $this->sender = new Address($fromEmail, $fromName);
        $this->mailer = $mailer;
    }

    public function sendLocalTemplateEmail(
        string $to,
        string $subject,
        string $templatePath,
        array $templateData = [],
    ): void {
        $email = (new TemplatedEmail())
            ->from($this->sender)
            ->to($to)
            ->subject($subject)
            ->htmlTemplate($templatePath)
            ->context($templateData);

        $this->mailer->send($email);
    }

    public function sendTemplateEmail(int $templateId, array $to, array $params = []): bool
    {
        $payload = [
            'templateId' => $templateId,
            'to' => $this->formatRecipients($to),
            'params' => $params,
        ];

        try {
            $response = $this->client->request('POST', self::BASE_URL . '/smtp/email', [
                'json' => $payload,
            ]);

            $this->logger?->info('Email sent', [
                'template_id' => $templateId,
                'message_id' => $response->toArray()['messageId'] ?? null,
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger?->error('Email send failed', [
                'template_id' => $templateId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function sendEmail(array $data): array
    {
        $defaults = [
            'to' => [],
            'params' => [],
            'cc' => [],
            'bcc' => [],
            'replyTo' => null,
            'subject' => null,
            'tags' => [],
        ];

        $data = array_merge($defaults, $data);

        if (empty($data['templateId'])) {
            throw new InvalidArgumentException('templateId is required');
        }

        $formattedTo = $this->formatRecipients($data['to']);
        if (empty($formattedTo)) {
            throw new InvalidArgumentException('At least one valid recipient is required');
        }

        $payload = [
            'templateId' => $data['templateId'],
            'to' => $formattedTo,
            'params' => $data['params'],
        ];

        // Optional fields
        if (!empty($data['cc'])) {
            $formattedCc = $this->formatRecipients($data['cc']);
            if (!empty($formattedCc)) {
                $payload['cc'] = $formattedCc;
            }
        }

        if (!empty($data['bcc'])) {
            $formattedBcc = $this->formatRecipients($data['bcc']);
            if (!empty($formattedBcc)) {
                $payload['bcc'] = $formattedBcc;
            }
        }

        if ($data['replyTo']) {
            $payload['replyTo'] = $data['replyTo'];
        }

        if ($data['subject']) {
            $payload['subject'] = $data['subject'];
        }

        if (!empty($data['tags'])) {
            $payload['tags'] = $data['tags'];
        }

        try {
            $response = $this->client->request('POST', self::BASE_URL . '/smtp/email', [
                'json' => $payload,
            ]);

            return [
                'success' => true,
                'messageId' => $response->toArray()['messageId'] ?? null,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function formatRecipients($recipients): array
    {
        if (is_string($recipients)) {
            return [['email' => $recipients]];
        }

        $formatted = [];
        foreach ((array) $recipients as $key => $value) {
            if (is_string($key) && filter_var($key, FILTER_VALIDATE_EMAIL)) {
                $formatted[] = ['email' => $key, 'name' => $value];
            } elseif (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $formatted[] = ['email' => $value];
            } elseif (is_array($value) && isset($value['email'])) {
                $formatted[] = $value;
            }
        }

        return $formatted;
    }

    public function getTemplate(int $id): ?array
    {
        try {
            $response = $this->client->request('GET', self::BASE_URL . "/smtp/templates/{$id}");

            return $response->toArray();
        } catch (Exception $e) {
            return null;
        }
    }
}
