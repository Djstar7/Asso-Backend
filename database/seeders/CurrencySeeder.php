<?php

namespace Database\Seeders;

use App\Models\Currency;
use App\Models\ExchangeRate;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $currencies = [
            [
                'code' => 'XOF',
                'name' => 'Franc CFA (BCEAO)',
                'symbol' => 'FCFA',
                'countries' => ['Bénin', 'Burkina Faso', "Côte d'Ivoire", 'Guinée-Bissau', 'Mali', 'Niger', 'Sénégal', 'Togo'],
            ],
            [
                'code' => 'XAF',
                'name' => 'Franc CFA (BEAC)',
                'symbol' => 'FCFA',
                'countries' => ['Cameroun', 'Centrafrique', 'Congo', 'Gabon', 'Guinée équatoriale', 'Tchad'],
            ],
            [
                'code' => 'NGN',
                'name' => 'Naira nigérian',
                'symbol' => '₦',
                'countries' => ['Nigeria'],
            ],
            [
                'code' => 'GHS',
                'name' => 'Cedi ghanéen',
                'symbol' => 'GH₵',
                'countries' => ['Ghana'],
            ],
            [
                'code' => 'GNF',
                'name' => 'Franc guinéen',
                'symbol' => 'FG',
                'countries' => ['Guinée'],
            ],
            [
                'code' => 'MAD',
                'name' => 'Dirham marocain',
                'symbol' => 'DH',
                'countries' => ['Maroc'],
            ],
            [
                'code' => 'DZD',
                'name' => 'Dinar algérien',
                'symbol' => 'DA',
                'countries' => ['Algérie'],
            ],
            [
                'code' => 'TND',
                'name' => 'Dinar tunisien',
                'symbol' => 'DT',
                'countries' => ['Tunisie'],
            ],
            [
                'code' => 'CDF',
                'name' => 'Franc congolais',
                'symbol' => 'FC',
                'countries' => ['République démocratique du Congo'],
            ],
            [
                'code' => 'KES',
                'name' => 'Shilling kényan',
                'symbol' => 'KSh',
                'countries' => ['Kenya'],
            ],
            [
                'code' => 'ZAR',
                'name' => 'Rand sud-africain',
                'symbol' => 'R',
                'countries' => ['Afrique du Sud'],
            ],
            [
                'code' => 'EUR',
                'name' => 'Euro',
                'symbol' => '€',
                'countries' => ['France', 'Belgique', 'Allemagne', 'Espagne', 'Italie', 'Portugal', 'Pays-Bas', 'Luxembourg', 'Irlande', 'Autriche'],
            ],
            [
                'code' => 'USD',
                'name' => 'Dollar américain',
                'symbol' => '$',
                'countries' => ['États-Unis'],
            ],
            [
                'code' => 'CAD',
                'name' => 'Dollar canadien',
                'symbol' => 'C$',
                'countries' => ['Canada'],
            ],
            [
                'code' => 'GBP',
                'name' => 'Livre sterling',
                'symbol' => '£',
                'countries' => ['Royaume-Uni'],
            ],
            [
                'code' => 'CHF',
                'name' => 'Franc suisse',
                'symbol' => 'CHF',
                'countries' => ['Suisse'],
            ],
        ];

        foreach ($currencies as $data) {
            Currency::updateOrCreate(
                ['code' => $data['code']],
                array_merge($data, ['is_active' => true])
            );
        }

        // Taux de change indicatifs vers XOF (1 <devise> = X XOF).
        // Ajuste/branche un fournisseur temps réel plus tard si besoin.
        $ratesToXof = [
            'EUR' => 655.957,
            'USD' => 605.0,
            'GBP' => 765.0,
            'CAD' => 445.0,
            'CHF' => 680.0,
            'XAF' => 1.0,
            'NGN' => 0.40,
            'GHS' => 45.0,
            'MAD' => 60.0,
        ];

        foreach ($ratesToXof as $code => $toXof) {
            ExchangeRate::updateOrCreate(
                ['from_currency' => $code, 'to_currency' => 'XOF'],
                ['rate' => $toXof, 'is_active' => true]
            );
        }

        $this->command->info('   💱 ' . count($currencies) . ' devises et ' . count($ratesToXof) . ' taux de change insérés.');
    }
}
