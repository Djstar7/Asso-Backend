<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AdminActionDigestMail extends Mailable
{
    public function __construct(public User $employee, public array $groups, public bool $reminder = false)
    {
        $this->locale($employee->preferredLocale());
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.admin_actions.'.($this->reminder ? 'reminder_subject' : 'subject')).' - ASSO');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.admin-action-digest');
    }
}
