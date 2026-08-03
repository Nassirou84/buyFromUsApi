<?php

namespace App\Command;

use App\Service\BrevoEmailService;
use App\Service\EmailQueueService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:test-mailjet',
    description: 'Send a test email using BrevoEmailService',
)]
class SendEmailCommand extends Command
{
    protected static $defaultName = 'app:test-mailjet';
    private $emailService;
    private $mailQueueService;

    public function __construct(
        BrevoEmailService $brevoMailerService,
        EmailQueueService $mailQueueService
    ) {
        parent::__construct();
        $this->emailService = $brevoMailerService;
        $this->mailQueueService = $mailQueueService;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {

        $this->mailQueueService->addToQueue([
            'to' => 'sgahassim@gmail.com',
            'name' => 'Gahassim Sagne',
            'subject' => 'Test Queued Email',
            'templateId' => '10',
            'params' => [
                'company_name' => 'NSG GROUP CI',
                'website_name' => 'Kariaire',
                'job_title' => 'Développeur Full Stack',
                'start_date' => '2026-05-01',
                'job_location' => 'Abidjan, Côte d\'Ivoire',
                'full_name' => 'Gahassim Sagne',
                'contact_name' => 'Kader',
                'contact_email' => 'kader@kariaire.com',
                'contact_phone' => '+2250779896352',
                'salary' => '1,500,000 XOF',
                'salary_period' => 'Mois',
                'contract_type' => 'CDD',
            ]
        ]);

        // $this->emailService->sendEmail([
        //     'templateId' => 10, // Password reset template
        //     'to' => ['sgahassim@gmail.com' => 'Gahassim Sagne'],
        //     'from' => ['support@kariaire.com' => 'Kader From Kariaire'],
        //     'params' => [
        //         'company_name' => 'NSG GROUP CI',
        //         'website_name' => 'Kariaire',
        //         'job_title' => 'Développeur Full Stack',
        //         'start_date' => '2026-05-01',
        //         'job_location' => 'Abidjan, Côte d\'Ivoire',
        //         'full_name' => 'Gahassim Sagne',
        //         'contact_name' => 'Kader',
        //         'contact_email' => 'kader@kariaire.com',
        //         'contact_phone' => '+2250779896352',
        //         'salary' => '1,500,000 XOF',
        //         'salary_period' => 'Mois',
        //         'contract_type' => 'CDD',
        //     ],
        // ]);

        // $this->emailService->sendLocalTemplateEmail(
        //     'sgahassim@gmail.com',
        //     'Test Email from Brevo',
        //     'email/account_verify.html.twig',
        //     [
        //         'name' => 'Gahassim Sagne',
        //         'frontendURL' => 'https://kariaire.com',
        //     ]
        // );

        // $this->brevoSmsService->sendSms('+2250779896352', 'This is a test message from Brevo SMS service.');

        // $output->writeln('Email sent successfully!');
        return Command::SUCCESS;
    }
}