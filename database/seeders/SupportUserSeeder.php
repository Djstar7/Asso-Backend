<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\User;
use App\Services\SupportService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Provisionne le COMPTE SUPPORT ASSO et enregistre son id dans les settings
 * (`support_user_id`) pour exposition mobile + messagerie admin.
 */
class SupportUserSeeder extends Seeder
{
    public function run(): void
    {
        $support = User::updateOrCreate(
            ['email' => SupportService::EMAIL],
            [
                'first_name' => 'Support',
                'last_name' => 'ASSO',
                'phone' => '00000000001',
                'role' => 'admin',
                'password' => bcrypt(Str::random(32)),
                'email_verified_at' => now(),
            ]
        );

        Setting::set('support_user_id', $support->id, 'integer', 'general', 'Compte support ASSO (messagerie client)');

        $this->command->info('✅ Compte support ASSO créé (id=' . $support->id . ')');
    }
}
