<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $customerName,
        public string $invoiceNumber,
        public string $reminderMessage,
        public string $balanceDue,
        public string $currency,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Payment reminder for invoice {$this->invoiceNumber}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-reminder',
        );
    }
}
