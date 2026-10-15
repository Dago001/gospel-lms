<?php

namespace App\Notifications;

use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $orderNumber, public int $amountMinor, public string $currency, public string $receiptNumber) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Payment received - '.$this->orderNumber)
            ->greeting('Thank you, '.$notifiable->name)
            ->line('We received your payment of '.Money::format($this->amountMinor, $this->currency).' for order '.$this->orderNumber.'.')
            ->line('Your courses are now available in your dashboard. Receipt number: '.$this->receiptNumber.'.')
            ->action('Go to my courses', rtrim(config('app.frontend_url'), '/').'/student/courses');
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'payment.confirmed', 'title' => 'Payment received', 'body' => 'Order '.$this->orderNumber.' is confirmed. Your courses are ready.',
            'order_number' => $this->orderNumber, 'receipt_number' => $this->receiptNumber];
    }
}
