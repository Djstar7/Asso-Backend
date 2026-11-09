<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Rapport quotidien de sauvegarde, avec l'archive chiffrée en pièce jointe.
 * Envoyé directement (jamais en file d'attente) : la pièce jointe est un
 * fichier local et le rapport doit partir même si les workers sont arrêtés.
 */
class DatabaseBackupMail extends Mailable
{
    public function __construct(
        public array $report,
        public ?string $attachmentPath = null,
    ) {
        $this->locale('fr');
    }

    public function envelope(): Envelope
    {
        $subject = $this->report['status'] === 'success'
            ? "Sauvegarde ASSO du {$this->report['day']} — {$this->report['size']}"
            : "ÉCHEC de la sauvegarde ASSO du {$this->report['day']}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.database-backup');
    }

    public function attachments(): array
    {
        if (! $this->attachmentPath) {
            return [];
        }

        return [
            Attachment::fromPath($this->attachmentPath)
                ->as($this->report['file'])
                ->withMime('application/zip'),
        ];
    }
}
