<?php

namespace App\Services;

use App\Mail\AdminActionDigestMail;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AdminActionMailService
{
    public function __construct(private AdminActionCatalog $catalog) {}

    public function recipients(): Collection
    {
        $roles = config('admin_access.staff_roles', []);

        return User::whereNotNull('email')->where(function ($q) use ($roles) {
            $q->whereIn('role', $roles);
            foreach ($roles as $role) {
                $q->orWhereJsonContains('roles', $role);
            }
        })->get()->filter(fn (User $u) => $u->isBackofficeStaff() && ! $u->isTechnicalAccount()
            && filter_var($u->email, FILTER_VALIDATE_EMAIL))->values();
    }

    /** Un seul passage à la fois ; un échec SMTP reste réessayable au prochain passage. */
    public function sendPending(): array
    {
        return Cache::lock('admin-action-emails', 600)->get(function () {
            $stats = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
            foreach ($this->recipients() as $user) {
                try {
                    $groups = $this->catalog->forUser($user);
                    $state = DB::table('admin_action_mail_states')->where('user_id', $user->id)->first();
                    if ($groups === []) {
                        DB::table('admin_action_mail_states')->where('user_id', $user->id)->delete();
                        $stats['skipped']++;

                        continue;
                    }
                    $fingerprint = hash('sha256', json_encode(array_map(fn ($g) => $g['states'], $groups), JSON_THROW_ON_ERROR));
                    $changed = ! $state || $state->fingerprint !== $fingerprint;
                    $due = ! $state || \Illuminate\Support\Carbon::parse($state->sent_at)
                        ->lte(now()->subHours(config('admin_alerts.reminder_hours', 24)));
                    if (! $changed && ! $due) {
                        $stats['skipped']++;

                        continue;
                    }
                    // Le transport doit avoir accepté le mail avant de mémoriser l'envoi.
                    Mail::to($user->email)->send(new AdminActionDigestMail($user, $groups, ! $changed));
                    DB::table('admin_action_mail_states')->updateOrInsert(['user_id' => $user->id], [
                        'fingerprint' => $fingerprint, 'sent_at' => now(),
                        'created_at' => $state?->created_at ?? now(), 'updated_at' => now(),
                    ]);
                    $stats['sent']++;
                } catch (\Throwable $e) {
                    $stats['failed']++;
                    Log::error('[AdminActionMail] Envoi non abouti', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                }
            }

            return $stats;
        }) ?: ['sent' => 0, 'failed' => 0, 'skipped' => 0];
    }
}
