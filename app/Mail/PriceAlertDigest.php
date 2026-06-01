<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PriceAlertDigest extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{
     *     familyName: string,
     *     productName: string,
     *     storeName: string,
     *     price: float,
     *     oldPrice: ?float,
     *     triggerType: string,
     *     productUrl: ?string
     * }>  $triggers
     */
    public function __construct(
        public readonly string $userName,
        public readonly array $triggers,
    ) {}

    public function envelope(): Envelope
    {
        $count = count($this->triggers);
        $subject = $count === 1
            ? 'Nauja akcija jūsų sekamai prekei'
            : "Naujos akcijos jūsų sekamoms prekėms ({$count})";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.price-alert-digest',
            with: [
                'userName' => $this->userName,
                'triggers' => $this->triggers,
            ],
        );
    }
}
