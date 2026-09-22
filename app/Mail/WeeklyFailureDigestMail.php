<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class WeeklyFailureDigestMail extends Mailable
{
    /**
     * @param  array{week_key: string, window_start: string, window_end: string, monitor_count: int, missed: list<array{id: int, monitor_name: string, command: string|null, occurred_at: string}>, non_zero_exit: list<array{id: int, monitor_name: string, command: string|null, occurred_at: string, exit_code: int|null}>}  $payload
     */
    public function __construct(
        public readonly string $recipient,
        public readonly array $payload,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Muster weekly failure digest: {$this->payload['week_key']}",
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.weekly-failure-digest-html',
            text: 'mail.weekly-failure-digest-text',
            with: [
                'recipient' => $this->recipient,
                'payload' => $this->payload,
            ],
        );
    }
}
