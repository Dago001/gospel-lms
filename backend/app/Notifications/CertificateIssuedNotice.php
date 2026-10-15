<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CertificateIssuedNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $courseTitle, public string $number) {}

    public function via(object $n): array
    {
        return ['mail', 'database'];
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function toMail(object $n): MailMessage
    {
        return (new MailMessage)->subject('Your certificate: '.$this->courseTitle)->line('Your certificate '.$this->number.' is ready to download.')
            ->action('Download certificate', rtrim(config('app.frontend_url'), '/').'/student/certificates');
    }

    public function toArray(object $n): array
    {
        return ['type' => 'certificate.issued', 'title' => 'Certificate issued', 'body' => $this->courseTitle, 'number' => $this->number];
    }
}
