<?php

namespace App\Mail;

use App\Models\Dispute;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Litige sur une commande en gros : ASSO est le vendeur, les employés en
 * charge des litiges sont prévenus par e-mail et agissent depuis le back-office.
 */
class DisputeStaffAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $employee,
        public Dispute $dispute,
        public string $title,
        public string $body,
    ) {
        $this->locale($employee->preferredLocale());
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title . ' - ASSO');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.dispute-staff-alert',
            with: ['disputeUrl' => route('admin.disputes.show', $this->dispute)],
        );
    }
}
