<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SystemUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Créer ou mettre à jour l'utilisateur système pour les messages automatiques
        User::updateOrCreate(
            ['email' => 'system@asso.app'],
            [
                'first_name' => 'ASSO',
                'last_name' => 'Système',
                'phone' => '00000000000',
                'role' => 'admin',
                'password' => bcrypt(Str::random(32)), // Mot de passe aléatoire sécurisé
                'email_verified_at' => now(),
            ]
        );

        $this->command->info('✅ Utilisateur système créé avec succès!');
    }
}
