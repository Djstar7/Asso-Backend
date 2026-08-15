<?php

namespace Database\Seeders;

use App\Models\Currency;
use App\Models\ExchangeRate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurrenciesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Base currency: XOF (West African CFA franc)
     * All rates are: 1 XOF = X target_currency
     */
    public function run(): void
    {
        DB::transaction(function () {
            // Définir toutes les devises mondiales
            $currencies = $this->getCurrenciesData();

            foreach ($currencies as $currency) {
                Currency::updateOrCreate(
                    ['code' => $currency['code']],
                    [
                        'name' => $currency['name'],
                        'symbol' => $currency['symbol'],
                        'countries' => $currency['countries'],
                        'is_active' => $currency['is_active'] ?? true,
                    ]
                );
            }

            // Définir les taux de change par rapport au XOF
            $exchangeRates = $this->getExchangeRatesData();

            foreach ($exchangeRates as $rate) {
                ExchangeRate::updateOrCreate(
                    [
                        'from_currency' => $rate['from'],
                        'to_currency' => $rate['to'],
                        'effective_date' => now()->toDateString(),
                    ],
                    [
                        'rate' => $rate['rate'],
                        'is_active' => true,
                    ]
                );
            }
        });
    }

    /**
     * Get all world currencies data
     */
    private function getCurrenciesData(): array
    {
        return [
            // Franc CFA et devises africaines
            ['code' => 'XOF', 'name' => 'West African CFA franc', 'symbol' => 'FCFA', 'countries' => ['Benin', 'Burkina Faso', 'Ivory Coast', 'Guinea-Bissau', 'Mali', 'Niger', 'Senegal', 'Togo']],
            ['code' => 'XAF', 'name' => 'Central African CFA franc', 'symbol' => 'FCFA', 'countries' => ['Cameroon', 'Central African Republic', 'Chad', 'Republic of the Congo', 'Equatorial Guinea', 'Gabon']],
            ['code' => 'DZD', 'name' => 'Algerian Dinar', 'symbol' => 'د.ج', 'countries' => ['Algeria']],
            ['code' => 'AOA', 'name' => 'Angolan Kwanza', 'symbol' => 'Kz', 'countries' => ['Angola']],
            ['code' => 'BWP', 'name' => 'Botswana Pula', 'symbol' => 'P', 'countries' => ['Botswana']],
            ['code' => 'BIF', 'name' => 'Burundian Franc', 'symbol' => 'FBu', 'countries' => ['Burundi']],
            ['code' => 'CVE', 'name' => 'Cape Verdean Escudo', 'symbol' => '$', 'countries' => ['Cape Verde']],
            ['code' => 'KMF', 'name' => 'Comorian Franc', 'symbol' => 'CF', 'countries' => ['Comoros']],
            ['code' => 'CDF', 'name' => 'Congolese Franc', 'symbol' => 'FC', 'countries' => ['Democratic Republic of the Congo']],
            ['code' => 'DJF', 'name' => 'Djiboutian Franc', 'symbol' => 'Fdj', 'countries' => ['Djibouti']],
            ['code' => 'EGP', 'name' => 'Egyptian Pound', 'symbol' => '£', 'countries' => ['Egypt']],
            ['code' => 'ERN', 'name' => 'Eritrean Nakfa', 'symbol' => 'Nfk', 'countries' => ['Eritrea']],
            ['code' => 'SZL', 'name' => 'Swazi Lilangeni', 'symbol' => 'L', 'countries' => ['Eswatini']],
            ['code' => 'ETB', 'name' => 'Ethiopian Birr', 'symbol' => 'Br', 'countries' => ['Ethiopia']],
            ['code' => 'GMD', 'name' => 'Gambian Dalasi', 'symbol' => 'D', 'countries' => ['Gambia']],
            ['code' => 'GHS', 'name' => 'Ghanaian Cedi', 'symbol' => '₵', 'countries' => ['Ghana']],
            ['code' => 'GNF', 'name' => 'Guinean Franc', 'symbol' => 'FG', 'countries' => ['Guinea']],
            ['code' => 'KES', 'name' => 'Kenyan Shilling', 'symbol' => 'KSh', 'countries' => ['Kenya']],
            ['code' => 'LSL', 'name' => 'Lesotho Loti', 'symbol' => 'L', 'countries' => ['Lesotho']],
            ['code' => 'LRD', 'name' => 'Liberian Dollar', 'symbol' => '$', 'countries' => ['Liberia']],
            ['code' => 'LYD', 'name' => 'Libyan Dinar', 'symbol' => 'ل.د', 'countries' => ['Libya']],
            ['code' => 'MGA', 'name' => 'Malagasy Ariary', 'symbol' => 'Ar', 'countries' => ['Madagascar']],
            ['code' => 'MWK', 'name' => 'Malawian Kwacha', 'symbol' => 'MK', 'countries' => ['Malawi']],
            ['code' => 'MRU', 'name' => 'Mauritanian Ouguiya', 'symbol' => 'UM', 'countries' => ['Mauritania']],
            ['code' => 'MUR', 'name' => 'Mauritian Rupee', 'symbol' => '₨', 'countries' => ['Mauritius']],
            ['code' => 'MAD', 'name' => 'Moroccan Dirham', 'symbol' => 'د.م.', 'countries' => ['Morocco']],
            ['code' => 'MZN', 'name' => 'Mozambican Metical', 'symbol' => 'MT', 'countries' => ['Mozambique']],
            ['code' => 'NAD', 'name' => 'Namibian Dollar', 'symbol' => '$', 'countries' => ['Namibia']],
            ['code' => 'NGN', 'name' => 'Nigerian Naira', 'symbol' => '₦', 'countries' => ['Nigeria']],
            ['code' => 'RWF', 'name' => 'Rwandan Franc', 'symbol' => 'FRw', 'countries' => ['Rwanda']],
            ['code' => 'STN', 'name' => 'São Tomé and Príncipe Dobra', 'symbol' => 'Db', 'countries' => ['São Tomé and Príncipe']],
            ['code' => 'SCR', 'name' => 'Seychellois Rupee', 'symbol' => '₨', 'countries' => ['Seychelles']],
            ['code' => 'SLL', 'name' => 'Sierra Leonean Leone', 'symbol' => 'Le', 'countries' => ['Sierra Leone']],
            ['code' => 'SOS', 'name' => 'Somali Shilling', 'symbol' => 'Sh', 'countries' => ['Somalia']],
            ['code' => 'ZAR', 'name' => 'South African Rand', 'symbol' => 'R', 'countries' => ['South Africa']],
            ['code' => 'SSP', 'name' => 'South Sudanese Pound', 'symbol' => '£', 'countries' => ['South Sudan']],
            ['code' => 'SDG', 'name' => 'Sudanese Pound', 'symbol' => 'ج.س.', 'countries' => ['Sudan']],
            ['code' => 'TZS', 'name' => 'Tanzanian Shilling', 'symbol' => 'TSh', 'countries' => ['Tanzania']],
            ['code' => 'TND', 'name' => 'Tunisian Dinar', 'symbol' => 'د.ت', 'countries' => ['Tunisia']],
            ['code' => 'UGX', 'name' => 'Ugandan Shilling', 'symbol' => 'USh', 'countries' => ['Uganda']],
            ['code' => 'ZMW', 'name' => 'Zambian Kwacha', 'symbol' => 'ZK', 'countries' => ['Zambia']],
            ['code' => 'ZWL', 'name' => 'Zimbabwean Dollar', 'symbol' => '$', 'countries' => ['Zimbabwe']],

            // Devises majeures internationales
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'countries' => ['Austria', 'Belgium', 'Cyprus', 'Estonia', 'Finland', 'France', 'Germany', 'Greece', 'Ireland', 'Italy', 'Latvia', 'Lithuania', 'Luxembourg', 'Malta', 'Netherlands', 'Portugal', 'Slovakia', 'Slovenia', 'Spain']],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'countries' => ['United States', 'Ecuador', 'El Salvador', 'Zimbabwe', 'Timor-Leste']],
            ['code' => 'GBP', 'name' => 'British Pound Sterling', 'symbol' => '£', 'countries' => ['United Kingdom']],
            ['code' => 'JPY', 'name' => 'Japanese Yen', 'symbol' => '¥', 'countries' => ['Japan']],
            ['code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF', 'countries' => ['Switzerland', 'Liechtenstein']],
            ['code' => 'CAD', 'name' => 'Canadian Dollar', 'symbol' => '$', 'countries' => ['Canada']],
            ['code' => 'AUD', 'name' => 'Australian Dollar', 'symbol' => '$', 'countries' => ['Australia', 'Kiribati', 'Nauru', 'Tuvalu']],
            ['code' => 'CNY', 'name' => 'Chinese Yuan', 'symbol' => '¥', 'countries' => ['China']],

            // Amérique du Sud
            ['code' => 'ARS', 'name' => 'Argentine Peso', 'symbol' => '$', 'countries' => ['Argentina']],
            ['code' => 'BOB', 'name' => 'Bolivian Boliviano', 'symbol' => 'Bs.', 'countries' => ['Bolivia']],
            ['code' => 'BRL', 'name' => 'Brazilian Real', 'symbol' => 'R$', 'countries' => ['Brazil']],
            ['code' => 'CLP', 'name' => 'Chilean Peso', 'symbol' => '$', 'countries' => ['Chile']],
            ['code' => 'COP', 'name' => 'Colombian Peso', 'symbol' => '$', 'countries' => ['Colombia']],
            ['code' => 'PEN', 'name' => 'Peruvian Sol', 'symbol' => 'S/', 'countries' => ['Peru']],
            ['code' => 'UYU', 'name' => 'Uruguayan Peso', 'symbol' => '$', 'countries' => ['Uruguay']],
            ['code' => 'VES', 'name' => 'Venezuelan Bolívar', 'symbol' => 'Bs.', 'countries' => ['Venezuela']],
            ['code' => 'PYG', 'name' => 'Paraguayan Guarani', 'symbol' => '₲', 'countries' => ['Paraguay']],

            // Amérique centrale et Caraïbes
            ['code' => 'MXN', 'name' => 'Mexican Peso', 'symbol' => '$', 'countries' => ['Mexico']],
            ['code' => 'CRC', 'name' => 'Costa Rican Colón', 'symbol' => '₡', 'countries' => ['Costa Rica']],
            ['code' => 'GTQ', 'name' => 'Guatemalan Quetzal', 'symbol' => 'Q', 'countries' => ['Guatemala']],
            ['code' => 'HNL', 'name' => 'Honduran Lempira', 'symbol' => 'L', 'countries' => ['Honduras']],
            ['code' => 'NIO', 'name' => 'Nicaraguan Córdoba', 'symbol' => 'C$', 'countries' => ['Nicaragua']],
            ['code' => 'PAB', 'name' => 'Panamanian Balboa', 'symbol' => 'B/.', 'countries' => ['Panama']],
            ['code' => 'DOP', 'name' => 'Dominican Peso', 'symbol' => '$', 'countries' => ['Dominican Republic']],
            ['code' => 'HTG', 'name' => 'Haitian Gourde', 'symbol' => 'G', 'countries' => ['Haiti']],
            ['code' => 'JMD', 'name' => 'Jamaican Dollar', 'symbol' => '$', 'countries' => ['Jamaica']],
            ['code' => 'TTD', 'name' => 'Trinidad and Tobago Dollar', 'symbol' => '$', 'countries' => ['Trinidad and Tobago']],

            // Asie
            ['code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹', 'countries' => ['India', 'Bhutan']],
            ['code' => 'IDR', 'name' => 'Indonesian Rupiah', 'symbol' => 'Rp', 'countries' => ['Indonesia']],
            ['code' => 'KRW', 'name' => 'South Korean Won', 'symbol' => '₩', 'countries' => ['South Korea']],
            ['code' => 'MYR', 'name' => 'Malaysian Ringgit', 'symbol' => 'RM', 'countries' => ['Malaysia']],
            ['code' => 'PHP', 'name' => 'Philippine Peso', 'symbol' => '₱', 'countries' => ['Philippines']],
            ['code' => 'SGD', 'name' => 'Singapore Dollar', 'symbol' => '$', 'countries' => ['Singapore']],
            ['code' => 'THB', 'name' => 'Thai Baht', 'symbol' => '฿', 'countries' => ['Thailand']],
            ['code' => 'VND', 'name' => 'Vietnamese Dong', 'symbol' => '₫', 'countries' => ['Vietnam']],
            ['code' => 'PKR', 'name' => 'Pakistani Rupee', 'symbol' => '₨', 'countries' => ['Pakistan']],
            ['code' => 'BDT', 'name' => 'Bangladeshi Taka', 'symbol' => '৳', 'countries' => ['Bangladesh']],
            ['code' => 'LKR', 'name' => 'Sri Lankan Rupee', 'symbol' => 'Rs', 'countries' => ['Sri Lanka']],
            ['code' => 'NPR', 'name' => 'Nepalese Rupee', 'symbol' => 'Rs', 'countries' => ['Nepal']],
            ['code' => 'MMK', 'name' => 'Myanmar Kyat', 'symbol' => 'K', 'countries' => ['Myanmar']],
            ['code' => 'KHR', 'name' => 'Cambodian Riel', 'symbol' => '៛', 'countries' => ['Cambodia']],
            ['code' => 'LAK', 'name' => 'Lao Kip', 'symbol' => '₭', 'countries' => ['Laos']],
            ['code' => 'TWD', 'name' => 'New Taiwan Dollar', 'symbol' => 'NT$', 'countries' => ['Taiwan']],
            ['code' => 'HKD', 'name' => 'Hong Kong Dollar', 'symbol' => '$', 'countries' => ['Hong Kong']],
            ['code' => 'MOP', 'name' => 'Macanese Pataca', 'symbol' => 'MOP$', 'countries' => ['Macau']],

            // Moyen-Orient
            ['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'ر.س', 'countries' => ['Saudi Arabia']],
            ['code' => 'AED', 'name' => 'UAE Dirham', 'symbol' => 'د.إ', 'countries' => ['United Arab Emirates']],
            ['code' => 'QAR', 'name' => 'Qatari Riyal', 'symbol' => 'ر.ق', 'countries' => ['Qatar']],
            ['code' => 'KWD', 'name' => 'Kuwaiti Dinar', 'symbol' => 'د.ك', 'countries' => ['Kuwait']],
            ['code' => 'BHD', 'name' => 'Bahraini Dinar', 'symbol' => 'ب.د', 'countries' => ['Bahrain']],
            ['code' => 'OMR', 'name' => 'Omani Rial', 'symbol' => 'ر.ع.', 'countries' => ['Oman']],
            ['code' => 'JOD', 'name' => 'Jordanian Dinar', 'symbol' => 'د.ا', 'countries' => ['Jordan']],
            ['code' => 'ILS', 'name' => 'Israeli New Shekel', 'symbol' => '₪', 'countries' => ['Israel']],
            ['code' => 'LBP', 'name' => 'Lebanese Pound', 'symbol' => 'ل.ل', 'countries' => ['Lebanon']],
            ['code' => 'SYP', 'name' => 'Syrian Pound', 'symbol' => '£S', 'countries' => ['Syria']],
            ['code' => 'IQD', 'name' => 'Iraqi Dinar', 'symbol' => 'ع.د', 'countries' => ['Iraq']],
            ['code' => 'IRR', 'name' => 'Iranian Rial', 'symbol' => '﷼', 'countries' => ['Iran']],
            ['code' => 'TRY', 'name' => 'Turkish Lira', 'symbol' => '₺', 'countries' => ['Turkey']],

            // Europe (hors zone euro)
            ['code' => 'NOK', 'name' => 'Norwegian Krone', 'symbol' => 'kr', 'countries' => ['Norway']],
            ['code' => 'SEK', 'name' => 'Swedish Krona', 'symbol' => 'kr', 'countries' => ['Sweden']],
            ['code' => 'DKK', 'name' => 'Danish Krone', 'symbol' => 'kr', 'countries' => ['Denmark']],
            ['code' => 'PLN', 'name' => 'Polish Zloty', 'symbol' => 'zł', 'countries' => ['Poland']],
            ['code' => 'CZK', 'name' => 'Czech Koruna', 'symbol' => 'Kč', 'countries' => ['Czech Republic']],
            ['code' => 'HUF', 'name' => 'Hungarian Forint', 'symbol' => 'Ft', 'countries' => ['Hungary']],
            ['code' => 'RON', 'name' => 'Romanian Leu', 'symbol' => 'lei', 'countries' => ['Romania']],
            ['code' => 'BGN', 'name' => 'Bulgarian Lev', 'symbol' => 'лв', 'countries' => ['Bulgaria']],
            ['code' => 'HRK', 'name' => 'Croatian Kuna', 'symbol' => 'kn', 'countries' => ['Croatia']],
            ['code' => 'RSD', 'name' => 'Serbian Dinar', 'symbol' => 'дин', 'countries' => ['Serbia']],
            ['code' => 'UAH', 'name' => 'Ukrainian Hryvnia', 'symbol' => '₴', 'countries' => ['Ukraine']],
            ['code' => 'RUB', 'name' => 'Russian Ruble', 'symbol' => '₽', 'countries' => ['Russia']],
            ['code' => 'ISK', 'name' => 'Icelandic Króna', 'symbol' => 'kr', 'countries' => ['Iceland']],
            ['code' => 'ALL', 'name' => 'Albanian Lek', 'symbol' => 'L', 'countries' => ['Albania']],
            ['code' => 'BAM', 'name' => 'Bosnia-Herzegovina Convertible Mark', 'symbol' => 'KM', 'countries' => ['Bosnia and Herzegovina']],
            ['code' => 'MKD', 'name' => 'Macedonian Denar', 'symbol' => 'ден', 'countries' => ['North Macedonia']],

            // Océanie
            ['code' => 'NZD', 'name' => 'New Zealand Dollar', 'symbol' => '$', 'countries' => ['New Zealand', 'Cook Islands', 'Niue', 'Pitcairn Islands', 'Tokelau']],
            ['code' => 'FJD', 'name' => 'Fijian Dollar', 'symbol' => '$', 'countries' => ['Fiji']],
            ['code' => 'PGK', 'name' => 'Papua New Guinean Kina', 'symbol' => 'K', 'countries' => ['Papua New Guinea']],
            ['code' => 'WST', 'name' => 'Samoan Tala', 'symbol' => 'T', 'countries' => ['Samoa']],
            ['code' => 'TOP', 'name' => 'Tongan Paʻanga', 'symbol' => 'T$', 'countries' => ['Tonga']],
            ['code' => 'VUV', 'name' => 'Vanuatu Vatu', 'symbol' => 'Vt', 'countries' => ['Vanuatu']],
            ['code' => 'SBD', 'name' => 'Solomon Islands Dollar', 'symbol' => '$', 'countries' => ['Solomon Islands']],

            // Asie centrale
            ['code' => 'KZT', 'name' => 'Kazakhstani Tenge', 'symbol' => '₸', 'countries' => ['Kazakhstan']],
            ['code' => 'UZS', 'name' => 'Uzbekistani Som', 'symbol' => 'so\'m', 'countries' => ['Uzbekistan']],
            ['code' => 'TJS', 'name' => 'Tajikistani Somoni', 'symbol' => 'ЅМ', 'countries' => ['Tajikistan']],
            ['code' => 'TMT', 'name' => 'Turkmenistani Manat', 'symbol' => 'm', 'countries' => ['Turkmenistan']],
            ['code' => 'KGS', 'name' => 'Kyrgyzstani Som', 'symbol' => 'с', 'countries' => ['Kyrgyzstan']],
            ['code' => 'AZN', 'name' => 'Azerbaijani Manat', 'symbol' => '₼', 'countries' => ['Azerbaijan']],
            ['code' => 'GEL', 'name' => 'Georgian Lari', 'symbol' => '₾', 'countries' => ['Georgia']],
            ['code' => 'AMD', 'name' => 'Armenian Dram', 'symbol' => '֏', 'countries' => ['Armenia']],

            // Autres devises
            ['code' => 'AFN', 'name' => 'Afghan Afghani', 'symbol' => '؋', 'countries' => ['Afghanistan']],
            ['code' => 'BND', 'name' => 'Brunei Dollar', 'symbol' => '$', 'countries' => ['Brunei']],
            ['code' => 'BTN', 'name' => 'Bhutanese Ngultrum', 'symbol' => 'Nu.', 'countries' => ['Bhutan']],
            ['code' => 'MVR', 'name' => 'Maldivian Rufiyaa', 'symbol' => 'Rf', 'countries' => ['Maldives']],
            ['code' => 'MNT', 'name' => 'Mongolian Tugrik', 'symbol' => '₮', 'countries' => ['Mongolia']],
            ['code' => 'KPW', 'name' => 'North Korean Won', 'symbol' => '₩', 'countries' => ['North Korea']],
        ];
    }

    /**
     * Get exchange rates data
     * Base: XOF (West African CFA franc)
     * Format: 1 XOF = X target_currency
     * Les taux ci-dessous sont approximatifs (au 2026) et doivent être mis à jour régulièrement
     */
    private function getExchangeRatesData(): array
    {
        return [
            // XOF vers lui-même
            ['from' => 'XOF', 'to' => 'XOF', 'rate' => 1.00000000],

            // Franc CFA Central (parité fixe)
            ['from' => 'XOF', 'to' => 'XAF', 'rate' => 1.00000000],

            // Devises majeures
            ['from' => 'XOF', 'to' => 'EUR', 'rate' => 0.00152449], // 1 EUR = ~656 XOF (taux fixe CFA)
            ['from' => 'XOF', 'to' => 'USD', 'rate' => 0.00165000], // 1 USD = ~606 XOF
            ['from' => 'XOF', 'to' => 'GBP', 'rate' => 0.00128000], // 1 GBP = ~780 XOF
            ['from' => 'XOF', 'to' => 'CHF', 'rate' => 0.00144000], // 1 CHF = ~694 XOF
            ['from' => 'XOF', 'to' => 'CAD', 'rate' => 0.00226000], // 1 CAD = ~442 XOF
            ['from' => 'XOF', 'to' => 'AUD', 'rate' => 0.00255000], // 1 AUD = ~392 XOF
            ['from' => 'XOF', 'to' => 'JPY', 'rate' => 0.24500000], // 1 JPY = ~4.08 XOF
            ['from' => 'XOF', 'to' => 'CNY', 'rate' => 0.01190000], // 1 CNY = ~84 XOF

            // Devises africaines
            ['from' => 'XOF', 'to' => 'NGN', 'rate' => 2.55000000], // Nigeria
            ['from' => 'XOF', 'to' => 'GHS', 'rate' => 0.02600000], // Ghana
            ['from' => 'XOF', 'to' => 'ZAR', 'rate' => 0.03050000], // South Africa
            ['from' => 'XOF', 'to' => 'KES', 'rate' => 0.21300000], // Kenya
            ['from' => 'XOF', 'to' => 'EGP', 'rate' => 0.08100000], // Egypt
            ['from' => 'XOF', 'to' => 'MAD', 'rate' => 0.01630000], // Morocco
            ['from' => 'XOF', 'to' => 'TND', 'rate' => 0.00513000], // Tunisia
            ['from' => 'XOF', 'to' => 'DZD', 'rate' => 0.22200000], // Algeria
            ['from' => 'XOF', 'to' => 'UGX', 'rate' => 6.05000000], // Uganda
            ['from' => 'XOF', 'to' => 'TZS', 'rate' => 4.15000000], // Tanzania
            ['from' => 'XOF', 'to' => 'RWF', 'rate' => 2.20000000], // Rwanda
            ['from' => 'XOF', 'to' => 'ETB', 'rate' => 0.20000000], // Ethiopia
            ['from' => 'XOF', 'to' => 'MUR', 'rate' => 0.07500000], // Mauritius
            ['from' => 'XOF', 'to' => 'MGA', 'rate' => 7.50000000], // Madagascar
            ['from' => 'XOF', 'to' => 'ZMW', 'rate' => 0.04450000], // Zambia
            ['from' => 'XOF', 'to' => 'BWP', 'rate' => 0.02250000], // Botswana
            ['from' => 'XOF', 'to' => 'MZN', 'rate' => 0.10500000], // Mozambique
            ['from' => 'XOF', 'to' => 'AOA', 'rate' => 1.37000000], // Angola
            ['from' => 'XOF', 'to' => 'GNF', 'rate' => 14.20000000], // Guinea
            ['from' => 'XOF', 'to' => 'LRD', 'rate' => 0.31000000], // Liberia
            ['from' => 'XOF', 'to' => 'SLL', 'rate' => 34.50000000], // Sierra Leone
            ['from' => 'XOF', 'to' => 'GMD', 'rate' => 0.11100000], // Gambia

            // Asie
            ['from' => 'XOF', 'to' => 'INR', 'rate' => 0.13700000], // India
            ['from' => 'XOF', 'to' => 'IDR', 'rate' => 26.50000000], // Indonesia
            ['from' => 'XOF', 'to' => 'KRW', 'rate' => 2.21000000], // South Korea
            ['from' => 'XOF', 'to' => 'MYR', 'rate' => 0.00735000], // Malaysia
            ['from' => 'XOF', 'to' => 'PHP', 'rate' => 0.09500000], // Philippines
            ['from' => 'XOF', 'to' => 'SGD', 'rate' => 0.00222000], // Singapore
            ['from' => 'XOF', 'to' => 'THB', 'rate' => 0.05700000], // Thailand
            ['from' => 'XOF', 'to' => 'VND', 'rate' => 41.90000000], // Vietnam
            ['from' => 'XOF', 'to' => 'PKR', 'rate' => 0.45900000], // Pakistan
            ['from' => 'XOF', 'to' => 'BDT', 'rate' => 0.19900000], // Bangladesh
            ['from' => 'XOF', 'to' => 'TWD', 'rate' => 0.05350000], // Taiwan
            ['from' => 'XOF', 'to' => 'HKD', 'rate' => 0.01285000], // Hong Kong

            // Moyen-Orient
            ['from' => 'XOF', 'to' => 'SAR', 'rate' => 0.00619000], // Saudi Arabia
            ['from' => 'XOF', 'to' => 'AED', 'rate' => 0.00606000], // UAE
            ['from' => 'XOF', 'to' => 'QAR', 'rate' => 0.00601000], // Qatar
            ['from' => 'XOF', 'to' => 'KWD', 'rate' => 0.00051000], // Kuwait
            ['from' => 'XOF', 'to' => 'ILS', 'rate' => 0.00606000], // Israel
            ['from' => 'XOF', 'to' => 'TRY', 'rate' => 0.05650000], // Turkey

            // Amérique du Sud
            ['from' => 'XOF', 'to' => 'BRL', 'rate' => 0.00840000], // Brazil
            ['from' => 'XOF', 'to' => 'ARS', 'rate' => 1.65000000], // Argentina
            ['from' => 'XOF', 'to' => 'CLP', 'rate' => 1.58000000], // Chile
            ['from' => 'XOF', 'to' => 'COP', 'rate' => 6.87000000], // Colombia
            ['from' => 'XOF', 'to' => 'PEN', 'rate' => 0.00619000], // Peru
            ['from' => 'XOF', 'to' => 'MXN', 'rate' => 0.02890000], // Mexico

            // Europe (hors zone euro)
            ['from' => 'XOF', 'to' => 'GBP', 'rate' => 0.00128000], // UK
            ['from' => 'XOF', 'to' => 'NOK', 'rate' => 0.01770000], // Norway
            ['from' => 'XOF', 'to' => 'SEK', 'rate' => 0.01735000], // Sweden
            ['from' => 'XOF', 'to' => 'DKK', 'rate' => 0.01136000], // Denmark
            ['from' => 'XOF', 'to' => 'PLN', 'rate' => 0.00660000], // Poland
            ['from' => 'XOF', 'to' => 'CZK', 'rate' => 0.03840000], // Czech Republic
            ['from' => 'XOF', 'to' => 'HUF', 'rate' => 0.59000000], // Hungary
            ['from' => 'XOF', 'to' => 'RUB', 'rate' => 0.15100000], // Russia
            ['from' => 'XOF', 'to' => 'UAH', 'rate' => 0.06800000], // Ukraine

            // Océanie
            ['from' => 'XOF', 'to' => 'NZD', 'rate' => 0.00278000], // New Zealand

            // Taux inverses (pour faciliter les conversions bidirectionnelles)
            // De EUR vers XOF
            ['from' => 'EUR', 'to' => 'XOF', 'rate' => 655.95700000],
            ['from' => 'USD', 'to' => 'XOF', 'rate' => 606.06060000],
            ['from' => 'GBP', 'to' => 'XOF', 'rate' => 781.25000000],
        ];
    }
}
