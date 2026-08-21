<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create Admin users (idempotent : re-seed sans doublon)
        User::updateOrCreate(['email' => 'admin@asso.com'], [
            'first_name' => 'Admin',
            'last_name' => 'Principal',
            'email' => 'admin@asso.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'gender' => 'male',
            'phone' => '+229 97 12 34 56',
            'country' => 'Bénin',
            'address' => 'Cotonou, Bénin',
            'email_verified_at' => now(),
        ]);

        // Create Vendeurs (Sellers)
        $sellers = [
            [
                'first_name' => 'Amina',
                'last_name' => 'Kossou',
                'email' => 'amina.kossou@vendeur.com',
                'gender' => 'female',
                'phone' => '+229 97 45 67 89',
                'address' => 'Marché Dantokpa, Cotonou',
            ],
            [
                'first_name' => 'Ibrahim',
                'last_name' => 'Sanni',
                'email' => 'ibrahim.sanni@vendeur.com',
                'gender' => 'male',
                'phone' => '+229 96 78 90 12',
                'address' => 'Rue des Cheminots, Cotonou',
            ],
            [
                'first_name' => 'Fatima',
                'last_name' => 'Alassane',
                'email' => 'fatima.alassane@vendeur.com',
                'gender' => 'female',
                'phone' => '+229 95 23 45 67',
                'address' => 'Akpakpa, Cotonou',
            ],
            [
                'first_name' => 'Kofi',
                'last_name' => 'Mensah',
                'email' => 'kofi.mensah@vendeur.com',
                'gender' => 'male',
                'phone' => '+229 94 56 78 90',
                'address' => 'Ganhi, Cotonou',
            ],
            [
                'first_name' => 'Aïcha',
                'last_name' => 'Diallo',
                'email' => 'aicha.diallo@vendeur.com',
                'gender' => 'female',
                'phone' => '+229 93 67 89 01',
                'address' => 'Cadjehoun, Cotonou',
            ],
        ];

        foreach ($sellers as $seller) {
            User::updateOrCreate(['email' => $seller['email']], array_merge($seller, [
                'password' => Hash::make('password'),
                'role' => 'vendeur',
                'country' => 'Bénin',
                'email_verified_at' => now(),
            ]));
        }

        // Create Clients (Customers)
        $customers = [
            [
                'first_name' => 'Marie',
                'last_name' => 'Ahossou',
                'email' => 'marie.ahossou@client.com',
                'gender' => 'female',
                'phone' => '+229 97 11 22 33',
            ],
            [
                'first_name' => 'Jean',
                'last_name' => 'Baptiste',
                'email' => 'jean.baptiste@client.com',
                'gender' => 'male',
                'phone' => '+229 96 44 55 66',
            ],
            [
                'first_name' => 'Sophie',
                'last_name' => 'Gnacadja',
                'email' => 'sophie.gnacadja@client.com',
                'gender' => 'female',
                'phone' => '+229 95 77 88 99',
            ],
            [
                'first_name' => 'Pascal',
                'last_name' => 'Hounkpatin',
                'email' => 'pascal.hounkpatin@client.com',
                'gender' => 'male',
                'phone' => '+229 94 00 11 22',
            ],
            [
                'first_name' => 'Nadège',
                'last_name' => 'Dossou',
                'email' => 'nadege.dossou@client.com',
                'gender' => 'female',
                'phone' => '+229 93 33 44 55',
            ],
            [
                'first_name' => 'Rodrigue',
                'last_name' => 'Agbodji',
                'email' => 'rodrigue.agbodji@client.com',
                'gender' => 'male',
                'phone' => '+229 92 66 77 88',
            ],
            [
                'first_name' => 'Lucie',
                'last_name' => 'Keke',
                'email' => 'lucie.keke@client.com',
                'gender' => 'female',
                'phone' => '+229 91 99 00 11',
            ],
            [
                'first_name' => 'Étienne',
                'last_name' => 'Zannou',
                'email' => 'etienne.zannou@client.com',
                'gender' => 'male',
                'phone' => '+229 90 22 33 44',
            ],
        ];

        foreach ($customers as $customer) {
            User::updateOrCreate(['email' => $customer['email']], array_merge($customer, [
                'password' => Hash::make('password'),
                'role' => 'client',
                'country' => 'Bénin',
                'address' => 'Cotonou, Bénin',
                'email_verified_at' => now(),
            ]));
        }

        // Create Livreurs (Delivery persons)
        $deliveryPersons = [
            [
                'first_name' => 'Moussa',
                'last_name' => 'Traoré',
                'email' => 'moussa.traore@livreur.com',
                'gender' => 'male',
                'phone' => '+229 97 55 66 77',
            ],
            [
                'first_name' => 'Abdou',
                'last_name' => 'Senou',
                'email' => 'abdou.senou@livreur.com',
                'gender' => 'male',
                'phone' => '+229 96 88 99 00',
            ],
            [
                'first_name' => 'Ismaël',
                'last_name' => 'Koudjo',
                'email' => 'ismael.koudjo@livreur.com',
                'gender' => 'male',
                'phone' => '+229 95 11 22 33',
            ],
        ];

        foreach ($deliveryPersons as $deliveryPerson) {
            User::updateOrCreate(['email' => $deliveryPerson['email']], array_merge($deliveryPerson, [
                'password' => Hash::make('password'),
                'role' => 'livreur',
                'country' => 'Bénin',
                'address' => 'Cotonou, Bénin',
                'email_verified_at' => now(),
            ]));
        }
    }
}
