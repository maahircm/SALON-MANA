<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A plain notification email (reminders, confirmations, alerts). */
class SalonMessage extends Mailable
{
    public function __construct(public string $subjectLine, public string $messageBody) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.salon-message');
    }
}
