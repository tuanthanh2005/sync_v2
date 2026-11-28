<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class MailTestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:test {email : Recipient email address to send a live diagnostic test message}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Inspect active mail transport configuration (without exposing secrets) and send a live test email.';

    public function handle(): int
    {
        $recipient = trim((string) $this->argument('email'));

        if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Please provide a valid recipient email address.');

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default', 'log');
        $host = (string) config('mail.mailers.smtp.host', '');
        $port = (string) config('mail.mailers.smtp.port', '');
        $scheme = (string) (config('mail.mailers.smtp.scheme') ?? 'auto');
        $username = (string) config('mail.mailers.smtp.username', '');
        $fromAddress = (string) config('mail.from.address', '');
        $fromName = (string) config('mail.from.name', '');

        $this->info('Active Mail Configuration:');
        $this->line("  MAIL_MAILER       : {$mailer}");
        $this->line("  MAIL_HOST         : {$host}");
        $this->line("  MAIL_PORT         : {$port}");
        $this->line("  MAIL_SCHEME       : {$scheme}");
        $this->line('  MAIL_USERNAME     : '.($username !== '' ? $username : '(empty)'));
        $this->line("  MAIL_FROM_ADDRESS : {$fromAddress}");
        $this->line("  MAIL_FROM_NAME    : {$fromName}");

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->warn("MAIL_MAILER is currently '{$mailer}'. Emails are NOT delivered to real inboxes — they are only written to storage/logs/laravel.log!");
            $this->line('Update .env to MAIL_MAILER=smtp with your SMTP credentials and run: php artisan optimize:clear && php artisan optimize');

            return self::FAILURE;
        }

        try {
            $appName = (string) config('app.name', 'SysNoxis');
            Mail::raw(
                "This is a live SMTP delivery test from {$appName} (".config('app.url').') sent at '.now()->toIso8601String().'.',
                static function ($message) use ($recipient, $appName): void {
                    $message->to($recipient)
                        ->subject("[{$appName}] SMTP Delivery Test");
                },
            );

            $this->info("Test email accepted by transport [{$mailer}] for {$recipient}.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('SMTP delivery failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
