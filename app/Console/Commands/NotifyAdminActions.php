<?php

namespace App\Console\Commands;

use App\Services\AdminActionCatalog;
use App\Services\AdminActionMailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class NotifyAdminActions extends Command
{
    protected $signature = 'admin:notify-actions {--dry-run : Vérifie les destinataires et compte les tâches sans envoyer de mail}';

    protected $description = 'Notifie par email les équipes habilitées des actions en attente et les relance chaque jour';

    public function handle(AdminActionMailService $service, AdminActionCatalog $catalog): int
    {
        if ($this->option('dry-run')) {
            foreach ($service->recipients() as $user) {
                $count = array_sum(array_column($catalog->forUser($user), 'count'));
                $this->line("Compte #{$user->id} : {$count} tâche(s) accessible(s).");
            }

            return self::SUCCESS;
        }
        if (! config('admin_alerts.enabled')) {
            $this->info('Alertes administratives désactivées.');

            return self::SUCCESS;
        }
        if (! Schema::hasTable('admin_action_mail_states')) {
            $this->error('Exécutez php artisan migrate avant d’activer les alertes administratives.');

            return self::FAILURE;
        }
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $this->error('Configurez un transport email réel : log et array ne livrent pas de courrier.');

            return self::FAILURE;
        }
        $stats = $service->sendPending();
        $this->info("Envoyés : {$stats['sent']} ; inchangés : {$stats['skipped']} ; échecs : {$stats['failed']}.");

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
