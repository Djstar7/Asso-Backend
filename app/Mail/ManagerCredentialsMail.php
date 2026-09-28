<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Identifiants de connexion au back-office envoyés à un gestionnaire, à sa
 * création ou quand l'admin régénère son mot de passe.
 *
 * Envoi synchrone : le mot de passe en clair ne doit pas transiter par la
 * file d'attente (table jobs).
 */
class ManagerCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $manager,
        public string $plainPassword,
        public bool $isReset = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isReset
                ? 'Vos nouveaux accès au tableau de bord - ASSO'
                : 'Vos accès au tableau de bord - ASSO',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.manager-credentials',
            with: [
                'loginUrl' => route('admin.manager.login'),
                'roleLabel' => $this->manager->backofficeRoleLabel(),
            ],
        );
    }
}
