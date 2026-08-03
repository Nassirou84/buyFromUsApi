<?php

namespace App\Service;

use App\Entity\EmailQueue;
use Psr\Log\LoggerInterface;
use App\Service\BrevoEmailService;
use App\Repository\EmailQueueRepository;
use Doctrine\ORM\EntityManagerInterface;

class EmailQueueService
{

  public function __construct(
    private EntityManagerInterface $em,
    private BrevoEmailService $brevoService,
    private LoggerInterface $logger,
    private EmailQueueRepository $emailQueueRepository,
    private string $passwordResetTemplateId,
    private string $emailConfirmationTemplateId,
    private string $passwordChangedTemplateId,
    private string $websiteName,
    private string $frontendURL
  ) {
  }

  public function addToQueue(array $emailData): void
  {

    if (empty($emailData['to'])) {
      throw new \InvalidArgumentException('Recipient email is required to queue an email.');
    }
    if (empty($emailData['templateId'])) {
      throw new \InvalidArgumentException('Template ID is required to queue an email.');
    }
    if (empty($emailData['subject'])) {
      throw new \InvalidArgumentException('Email subject is required to queue an email.');
    }
    if (!filter_var($emailData['to'], FILTER_VALIDATE_EMAIL)) {
      throw new \InvalidArgumentException('Invalid recipient email format.');
    }

    $emailQueue = new EmailQueue();
    $emailQueue->setRecipientEmail($emailData['to']);
    $emailQueue->setRecipientName($emailData['name'] ?? '');
    $emailQueue->setSubject($emailData['subject']);
    $emailQueue->setTemplateId($emailData['templateId']);
    $emailQueue->setTemplateParams($emailData['params'] ?? []);
    $emailQueue->setPriority($emailData['priority'] ?? EmailQueue::PRIORITY_DEFAULT);

    // Schedule for immediate sending unless specified
    if (isset($emailData['sendAfter'])) {
      $emailQueue->setSendAfter($emailData['sendAfter']);
    }

    $this->em->persist($emailQueue);
    $this->em->flush();
  }

  public function processBatch(int $batchSize = 10): array
  {
    $results = [
      'sent' => 0,
      'failed' => 0,
      'skipped' => 0,
    ];

    // Get pending emails ready to send
    $pendingEmails = $this->emailQueueRepository->getEmailBatch($batchSize);

    foreach ($pendingEmails as $email) {
      $email->setStatus(EmailQueue::STATUS_PROCESSING);
      $email->setUpdatedAt(new \DateTime());
      $this->em->flush();

      try {
        $result = $this->sendEmail($email);

        if ($result['success']) {
          $email->setStatus(EmailQueue::STATUS_SENT);
          $email->setUpdatedAt(new \DateTime());
          $email->setMessageId($result['messageId'] ?? null);
          $this->logger->info('Email sent successfully', [
            'email_id' => hash('sha256', $email->getRecipientEmail()),
          ]);
          $results['sent']++;
        } else {
          $this->handleFailure($email, $result['error']);
          $results['failed']++;
        }
      } catch (\Exception $e) {
        $this->handleFailure($email, $e->getMessage());
        $results['failed']++;
      }

      $this->em->flush();
    }

    return $results;
  }

  private function sendEmail(EmailQueue $email): array
  {
    $emailData = [
      'templateId' => $email->getTemplateId(),
      'to' => [$email->getRecipientEmail() => $email->getRecipientName()],
      'params' => $email->getTemplateParams(),
      'subject' => $email->getSubject(),
    ];

    return $this->brevoService->sendEmail($emailData);
  }

  private function handleFailure(EmailQueue $email, string $error): void
  {
    $email->setAttemps($email->getAttemps() + 1);
    $email->setLastAttemptAt(new \DateTime());
    $email->setLastError($error);

    if ($email->getAttemps() >= $email->getMaxAttemps()) {
      $this->logger->error('Email failed permanently', [
        'email_id' => $email->getId(),
        'error' => $error,
        'attempts' => $email->getAttemps(),
      ]);
    } else {
      $email->setStatus(EmailQueue::STATUS_RETRYING);
      // Exponential backoff: 5, 15, 45 minutes
      $retryDelay = pow(3, $email->getAttemps() - 1) * 5;
      $nextAttempt = (new \DateTime())->modify("+{$retryDelay} minutes");
      if ($email->getAttemps() >= $email->getMaxAttemps()) {
        $email->setStatus(EmailQueue::STATUS_FAILED);
        $this->logger->error('Email failed permanently', [
          'email_id' => $email->getId(),
          'error' => $error,
          'attempts' => $email->getAttemps(),
        ]);
      } else {
        $email->setSendAfter($nextAttempt);
        $this->logger->warning('Email failed, will retry', [
          'email_id' => $email->getId(),
          'error' => $error,
          'attempts' => $email->getAttemps(),
          'next_attempt' => $nextAttempt->format('Y-m-d H:i:s'),
        ]);
      }
    }
  }

  public function cleanupOldEmails(int $days = 30): int
  {
    $cutoffDate = (new \DateTime())->modify("-{$days} days");

    $result = $this->em->createQueryBuilder()
      ->delete(EmailQueue::class, 'eq')
      ->where('eq.status IN (:statuses)')
      ->andWhere('eq.createdAt < :cutoff')
      ->setParameter('statuses', ['sent', 'failed'])
      ->setParameter('cutoff', $cutoffDate)
      ->getQuery()
      ->execute();

    return $result;
  }

  public function sendEmailConfirmationEmail(
    string $to,
    string $name,
    string $confirmationLink,
    int $priority = EmailQueue::PRIORITY_HIGH
  ) {

    $emailQueue = new EmailQueue();
    $emailQueue->setRecipientEmail($to);
    $emailQueue->setRecipientName($name);
    $emailQueue->setSubject('Confirmation de votre Email');
    $emailQueue->setTemplateId($this->emailConfirmationTemplateId);
    $emailQueue->setTemplateParams([
      'full_name' => $name,
      'website_name' => $this->websiteName,
      'verification_link' => $confirmationLink,
    ]);
    $emailQueue->setPriority($priority);

    $emailQueue->setStatus(EmailQueue::STATUS_PROCESSING);
    $this->em->persist($emailQueue);
    $this->em->flush();

    $result = [];

    try {
      $result = $this->sendEmail($emailQueue);

      if ($result['success']) {
        $emailQueue->setStatus(EmailQueue::STATUS_SENT);
        $emailQueue->setUpdatedAt(new \DateTime());
        $emailQueue->setMessageId($result['messageId'] ?? null);
        $this->logger->info('Email sent successfully', [
          'email_id' => hash('sha256', $emailQueue->getRecipientEmail()),
          'recipient' => $emailQueue->getRecipientEmail(),
        ]);
      } else {
        $this->handleFailure($emailQueue, $result['error']);
      }
    } catch (\Exception $e) {
      $this->handleFailure($emailQueue, $e->getMessage());
    }
    $this->em->flush();
    return $result;
  }

  public function sendPasswordResetEmail(
    string $to,
    string $name,
    string $resetLink,
    int $priority = EmailQueue::PRIORITY_HIGH
  ) {

    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
      throw new \InvalidArgumentException('Invalid email address provided for password reset email.');
    }

    $params = [
      'full_name' => $name,
      'reset_password_link' => $this->frontendURL . $resetLink,
      'email' => $to,
      'website_name' => $this->websiteName
    ];

    $emailQueue = new EmailQueue();
    $emailQueue->setRecipientEmail($to);
    $emailQueue->setRecipientName($name);
    $emailQueue->setSubject('Réinitialisation de votre mot de passe');
    $emailQueue->setTemplateId($this->passwordResetTemplateId);
    $emailQueue->setTemplateParams($params);
    $emailQueue->setPriority($priority);

    $emailQueue->setStatus(EmailQueue::STATUS_PROCESSING);
    $this->em->persist($emailQueue);
    $this->em->flush();

    $result = [];

    try {
      $result = $this->sendEmail($emailQueue);

      if ($result['success']) {
        $emailQueue->setStatus(EmailQueue::STATUS_SENT);
        $emailQueue->setUpdatedAt(new \DateTime());
        $emailQueue->setMessageId($result['messageId'] ?? null);
        $this->logger->info('Email sent successfully', [
          'email_id' => hash('sha256', $emailQueue->getRecipientEmail()),
          'recipient' => $emailQueue->getRecipientEmail(),
        ]);
      } else {
        $this->handleFailure($emailQueue, $result['error']);
      }
    } catch (\Exception $e) {
      $this->handleFailure($emailQueue, $e->getMessage());
    }
    $this->em->flush();
    return $result;
  }

  public function queuePasswordChangedConfirmationEmail(
    string $to,
    string $name,
    int $priority = EmailQueue::PRIORITY_DEFAULT
  ): void {

    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
      throw new \InvalidArgumentException('Invalid email address provided for password changed confirmation email.');
    }

    $params = [
      'full_name' => $name,
      'website_name' => $this->websiteName,
      'change_date' => ($changeDateTime = new \DateTime())->format('d/m/Y'),
      'change_time' => $changeDateTime->format('H:i'),
      'email' => $to
    ];

    $this->addToQueue([
      'to' => $to,
      'name' => $name,
      'subject' => 'Confirmation de changement de mot de passe',
      'templateId' => $this->passwordChangedTemplateId,
      'params' => $params,
      'priority' => $priority,
    ]);
  }
}