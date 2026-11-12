<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SharedInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $invoiceNumber,
        public string $customerName,
        public string $shareUrl
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Invoice {$this->invoiceNumber} is ready for review",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice-share',
        );
    }
}
