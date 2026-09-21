<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class TransitionAlertMail extends Mailable
{
    /**
     * @param array{
     *     event: array{id: int, transition: string, occurred_at: string},
     *     monitor: array{id: int, name: string, last_check_in_at: string|null, next_due_at: string|null}
     * } $payload
     */
    public function __construct(public readonly array $payload) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                'Muster: %s %s (event %d)',
                $this->payload['monitor']['name'],
                $this->payload['event']['transition'],
                $this->payload['event']['id'],
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.transition-alert',
            with: ['payload' => $this->payload],
        );
    }
}
