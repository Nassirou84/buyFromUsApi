<?php

declare(strict_types=1);

namespace App\Service;

use Exception;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Environment;

use function is_array;
use function is_string;

use const FILTER_VALIDATE_EMAIL;

class BrevoEmailService
{
    private const BASE_URL = 'https://api.brevo.com/v3';

    private HttpClientInterface $client;
    private ?LoggerInterface $logger;
    private Address $sender;
    private Environment $twig;

    public function __construct(
        Environment $twig,
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
            'timeout' => 5,
            'max_duration' => 10,
        ]);
        $this->logger = $logger;
        $this->sender = new Address($fromEmail, $fromName);
        $this->twig = $twig;
    }

    public function sendLocalTemplateEmail(
        string $to,
        string $subject,
        string $templatePath,
        array $templateData = [],
    ): void {
        // Sent through the Brevo API (same key as the other methods) rather than Symfony Mailer,
        // so it doesn't depend on MAILER_DSN.
        $response = $this->client->request('POST', self::BASE_URL . '/smtp/email', [
            'json' => [
                'sender' => ['email' => $this->sender->getAddress(), 'name' => $this->sender->getName()],
                'to' => $this->formatRecipients($to),
                'subject' => $subject,
                'htmlContent' => $this->twig->render($templatePath, $templateData),
            ],
        ]);

        // toArray() throws on non-2xx, so the messenger handler can retry/fail properly
        $this->logger?->info('Local template email sent', [
            'template' => $templatePath,
            'message_id' => $response->toArray()['messageId'] ?? null,
        ]);
    }

    public function sendTextEmail(string $to, string $subject, string $content): void
    {
        $response = $this->client->request('POST', self::BASE_URL . '/smtp/email', [
            'json' => [
                'sender' => ['email' => $this->sender->getAddress(), 'name' => $this->sender->getName()],
                'to' => $this->formatRecipients($to),
                'subject' => $subject,
                'textContent' => $content,
            ],
        ]);

        // toArray() throws on non-2xx, so the messenger handler can retry/fail properly
        $this->logger?->info('Text email sent', [
            'message_id' => $response->toArray()['messageId'] ?? null,
        ]);
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
            // Keep Brevo's own explanation (e.g. unauthorised IP, unverified sender), not just the status line
            $error = $e->getMessage();
            if ($e instanceof HttpExceptionInterface) {
                $error .= ' ' . $e->getResponse()->getContent(false);
            }

            $this->logger?->error('Email send failed', [
                'template_id' => $data['templateId'],
                'error' => $error,
            ]);

            return [
                'success' => false,
                'error' => $error,
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

    public function addContact(string $email, ?string $firstName = null, int $listId = 2): bool
    {
        $payload = [
            'email' => $email,
            'attributes' => ['FIRSTNAME' => $firstName ?? ''],
            'listIds' => [$listId],
            'updateEnabled' => true,
        ];

        try {
            $this->client->request('POST', self::BASE_URL . '/contacts', [
                'json' => $payload,
            ]);

            $this->logger?->info('Contact added', [
                'email' => $email,
                'list_id' => $listId,
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger?->error('Add contact failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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
