<?php

namespace App\Modules\Appeal\App\Mail;

use App\Modules\Appeal\App\Models\Appeal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Approval template for a ban appeal (C9).
 *
 * NOT triggered by default – AppealService only sends it when
 * MODULE_APPEALS_MAIL (modules.modules.appeal.mail_enabled) is enabled.
 */
class AppealApproved extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly Appeal $appeal)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your ban appeal was approved',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.appeal-approved',
            with: [
                'name' => $this->appeal->name,
                'reason' => $this->appeal->reason,
                'note' => $this->appeal->decision_note,
            ],
        );
    }
}