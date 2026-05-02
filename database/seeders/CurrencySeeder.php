<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Currency;

class CurrencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $currencies = [
            // West African CFA Franc
            [
                'code' => 'XOF',
                'name' => 'West African CFA franc',
                'symbol' => 'FCFA',
                'countries' => ['Benin', 'Burkina Faso', 'Ivory Coast', "Côte d'Ivoire", 'Guinea-Bissau', 'Mali', 'Niger', 'Senegal', 'Togo'],
                'is_active' => true,
            ],
            // Central African CFA Franc
            [
                'code' => 'XAF',
                'name' => 'Central African CFA franc',
                'symbol' => 'FCFA',
                'countries' => ['Cameroon', 'Central African Republic', 'Chad', 'Republic of the Congo', 'Equatorial Guinea', 'Gabon'],
                'is_active' => true,
            ],
            // US Dollar
            [
                'code' => 'USD',
                'name' => 'United States Dollar',
                'symbol' => '$',
                'countries' => ['United States', 'United States of America', 'USA', 'Ecuador', 'El Salvador', 'Marshall Islands', 'Micronesia', 'Palau', 'Panama', 'Zimbabwe'],
                'is_active' => true,
            ],
            // Euro
            [
                'code' => 'EUR',
                'name' => 'Euro',
                'symbol' => '€',
                'countries' => ['Austria', 'Belgium', 'Croatia', 'Cyprus', 'Estonia', 'Finland', 'France', 'Germany', 'Greece', 'Ireland', 'Italy', 'Latvia', 'Lithuania', 'Luxembourg', 'Malta', 'Netherlands', 'Portugal', 'Slovakia', 'Slovenia', 'Spain'],
                'is_active' => true,
            ],
            // British Pound
            [
                'code' => 'GBP',
                'name' => 'British Pound Sterling',
                'symbol' => '£',
                'countries' => ['United Kingdom', 'England', 'Scotland', 'Wales', 'Northern Ireland', 'Jersey', 'Guernsey', 'Isle of Man'],
                'is_active' => true,
            ],
            // Canadian Dollar
            [
                'code' => 'CAD',
                'name' => 'Canadian Dollar',
                'symbol' => 'C$',
                'countries' => ['Canada'],
                'is_active' => true,
            ],
            // Australian Dollar
            [
                'code' => 'AUD',
                'name' => 'Australian Dollar',
                'symbol' => 'A$',
                'countries' => ['Australia', 'Kiribati', 'Nauru', 'Tuvalu'],
                'is_active' => true,
            ],
            // Japanese Yen
            [
                'code' => 'JPY',
                'name' => 'Japanese Yen',
                'symbol' => '¥',
                'countries' => ['Japan'],
                'is_active' => true,
            ],
            // Swiss Franc
            [
                'code' => 'CHF',
                'name' => 'Swiss Franc',
                'symbol' => 'CHF',
                'countries' => ['Switzerland', 'Liechtenstein'],
                'is_active' => true,
            ],
            // Chinese Yuan
            [
                'code' => 'CNY',
                'name' => 'Chinese Yuan',
                'symbol' => '¥',
                'countries' => ['China', "People's Republic of China"],
                'is_active' => true,
            ],
            // Indian Rupee
            [
                'code' => 'INR',
                'name' => 'Indian Rupee',
                'symbol' => '₹',
                'countries' => ['India', 'Bhutan'],
                'is_active' => true,
            ],
            // South African Rand
            [
                'code' => 'ZAR',
                'name' => 'South African Rand',
                'symbol' => 'R',
                'countries' => ['South Africa', 'Lesotho', 'Namibia', 'Eswatini'],
                'is_active' => true,
            ],
            // Nigerian Naira
            [
                'code' => 'NGN',
                'name' => 'Nigerian Naira',
                'symbol' => '₦',
                'countries' => ['Nigeria'],
                'is_active' => true,
            ],
            // Ghanaian Cedi
            [
                'code' => 'GHS',
                'name' => 'Ghanaian Cedi',
                'symbol' => 'GH₵',
                'countries' => ['Ghana'],
                'is_active' => true,
            ],
            // Kenyan Shilling
            [
                'code' => 'KES',
                'name' => 'Kenyan Shilling',
                'symbol' => 'KSh',
                'countries' => ['Kenya'],
                'is_active' => true,
            ],
            // Moroccan Dirham
            [
                'code' => 'MAD',
                'name' => 'Moroccan Dirham',
                'symbol' => 'DH',
                'countries' => ['Morocco', 'Western Sahara'],
                'is_active' => true,
            ],
            // Egyptian Pound
            [
                'code' => 'EGP',
                'name' => 'Egyptian Pound',
                'symbol' => 'E£',
                'countries' => ['Egypt'],
                'is_active' => true,
            ],
            // Brazilian Real
            [
                'code' => 'BRL',
                'name' => 'Brazilian Real',
                'symbol' => 'R$',
                'countries' => ['Brazil'],
                'is_active' => true,
            ],
            // Mexican Peso
            [
                'code' => 'MXN',
                'name' => 'Mexican Peso',
                'symbol' => 'Mex$',
                'countries' => ['Mexico'],
                'is_active' => true,
            ],
            // Russian Ruble
            [
                'code' => 'RUB',
                'name' => 'Russian Ruble',
                'symbol' => '₽',
                'countries' => ['Russia', 'Russian Federation'],
                'is_active' => true,
            ],
            // Turkish Lira
            [
                'code' => 'TRY',
                'name' => 'Turkish Lira',
                'symbol' => '₺',
                'countries' => ['Turkey', 'Türkiye'],
                'is_active' => true,
            ],
            // Saudi Riyal
            [
                'code' => 'SAR',
                'name' => 'Saudi Riyal',
                'symbol' => 'SR',
                'countries' => ['Saudi Arabia'],
                'is_active' => true,
            ],
            // UAE Dirham
            [
                'code' => 'AED',
                'name' => 'UAE Dirham',
                'symbol' => 'AED',
                'countries' => ['United Arab Emirates', 'UAE'],
                'is_active' => true,
            ],
            // Singapore Dollar
            [
                'code' => 'SGD',
                'name' => 'Singapore Dollar',
                'symbol' => 'S$',
                'countries' => ['Singapore', 'Brunei'],
                'is_active' => true,
            ],
            // Hong Kong Dollar
            [
                'code' => 'HKD',
                'name' => 'Hong Kong Dollar',
                'symbol' => 'HK$',
                'countries' => ['Hong Kong'],
                'is_active' => true,
            ],
            // South Korean Won
            [
                'code' => 'KRW',
                'name' => 'South Korean Won',
                'symbol' => '₩',
                'countries' => ['South Korea', 'Korea, Republic of'],
                'is_active' => true,
            ],
            // Thai Baht
            [
                'code' => 'THB',
                'name' => 'Thai Baht',
                'symbol' => '฿',
                'countries' => ['Thailand'],
                'is_active' => true,
            ],
            // Malaysian Ringgit
            [
                'code' => 'MYR',
                'name' => 'Malaysian Ringgit',
                'symbol' => 'RM',
                'countries' => ['Malaysia'],
                'is_active' => true,
            ],
            // Indonesian Rupiah
            [
                'code' => 'IDR',
                'name' => 'Indonesian Rupiah',
                'symbol' => 'Rp',
                'countries' => ['Indonesia'],
                'is_active' => true,
            ],
            // Philippine Peso
            [
                'code' => 'PHP',
                'name' => 'Philippine Peso',
                'symbol' => '₱',
                'countries' => ['Philippines'],
                'is_active' => true,
            ],
            // Vietnamese Dong
            [
                'code' => 'VND',
                'name' => 'Vietnamese Dong',
                'symbol' => '₫',
                'countries' => ['Vietnam', 'Viet Nam'],
                'is_active' => true,
            ],
            // New Zealand Dollar
            [
                'code' => 'NZD',
                'name' => 'New Zealand Dollar',
                'symbol' => 'NZ$',
                'countries' => ['New Zealand', 'Cook Islands', 'Niue', 'Pitcairn Islands', 'Tokelau'],
                'is_active' => true,
            ],
            // Argentine Peso
            [
                'code' => 'ARS',
                'name' => 'Argentine Peso',
                'symbol' => '$',
                'countries' => ['Argentina'],
                'is_active' => true,
            ],
            // Chilean Peso
            [
                'code' => 'CLP',
                'name' => 'Chilean Peso',
                'symbol' => '$',
                'countries' => ['Chile'],
                'is_active' => true,
            ],
            // Colombian Peso
            [
                'code' => 'COP',
                'name' => 'Colombian Peso',
                'symbol' => '$',
                'countries' => ['Colombia'],
                'is_active' => true,
            ],
            // Algerian Dinar
            [
                'code' => 'DZD',
                'name' => 'Algerian Dinar',
                'symbol' => 'DA',
                'countries' => ['Algeria'],
                'is_active' => true,
            ],
            // Tunisian Dinar
            [
                'code' => 'TND',
                'name' => 'Tunisian Dinar',
                'symbol' => 'DT',
                'countries' => ['Tunisia'],
                'is_active' => true,
            ],
        ];

        foreach ($currencies as $currency) {
            Currency::updateOrCreate(
                ['code' => $currency['code']],
                $currency
            );
        }
    }
}
