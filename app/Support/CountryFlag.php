<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Drapeau emoji d'un pays à partir de son nom (français ou anglais) ou de son
 * code ISO 3166-1 alpha-2.
 *
 * Le catalogue devises (`currencies.countries`) stocke des noms libres, dans la
 * langue du seeder qui les a posés (anglais historiquement, français avec
 * WorldCurrencySeeder). Ce helper ramène ces noms à un code ISO, dont le drapeau
 * se dérive mécaniquement (indicateurs régionaux Unicode : « CM » → 🇨🇲).
 */
class CountryFlag
{
    /**
     * Code ISO => noms acceptés (anglais, français, variantes usuelles).
     * La comparaison passe par key() : casse, accents et ponctuation ignorés.
     *
     * Les nations du Royaume-Uni ont leur propre drapeau emoji (séquence de tags)
     * sauf l'Irlande du Nord, rattachée au drapeau britannique.
     */
    private const ALIASES = [
        'AD' => ['Andorra', 'Andorre'],
        'AE' => ['United Arab Emirates', 'Émirats arabes unis', 'Émirats arabes unis (Dubaï)', 'UAE', 'EAU', 'Dubaï', 'Dubai', 'Emirates'],
        'AF' => ['Afghanistan'],
        'AG' => ['Antigua and Barbuda', 'Antigua-et-Barbuda'],
        'AI' => ['Anguilla'],
        'AL' => ['Albania', 'Albanie'],
        'AM' => ['Armenia', 'Arménie'],
        'AO' => ['Angola'],
        'AQ' => ['Antarctica', 'Antarctique'],
        'AR' => ['Argentina', 'Argentine'],
        'AS' => ['American Samoa', 'Samoa américaines'],
        'AT' => ['Austria', 'Autriche'],
        'AU' => ['Australia', 'Australie'],
        'AW' => ['Aruba'],
        'AX' => ['Åland Islands', 'Îles Åland'],
        'AZ' => ['Azerbaijan', 'Azerbaïdjan'],
        'BA' => ['Bosnia and Herzegovina', 'Bosnie-Herzégovine'],
        'BB' => ['Barbados', 'Barbade'],
        'BD' => ['Bangladesh'],
        'BE' => ['Belgium', 'Belgique'],
        'BF' => ['Burkina Faso'],
        'BG' => ['Bulgaria', 'Bulgarie'],
        'BH' => ['Bahrain', 'Bahreïn'],
        'BI' => ['Burundi'],
        'BJ' => ['Benin', 'Bénin'],
        'BL' => ['Saint Barthélemy', 'Saint-Barthélemy'],
        'BM' => ['Bermuda', 'Bermudes'],
        'BN' => ['Brunei', 'Brunei Darussalam', 'Brunéi'],
        'BO' => ['Bolivia', 'Bolivie'],
        'BQ' => ['Bonaire, Sint Eustatius and Saba', 'Bonaire'],
        'BR' => ['Brazil', 'Brésil'],
        'BS' => ['Bahamas'],
        'BT' => ['Bhutan', 'Bhoutan'],
        'BV' => ['Bouvet Island', 'Île Bouvet'],
        'BW' => ['Botswana'],
        'BY' => ['Belarus', 'Biélorussie', 'Bélarus'],
        'BZ' => ['Belize'],
        'CA' => ['Canada'],
        'CC' => ['Cocos (Keeling) Islands', 'Îles Cocos'],
        'CD' => ['Democratic Republic of the Congo', 'République démocratique du Congo', 'DR Congo', 'RDC', 'RD Congo', 'Congo (Kinshasa)', 'Congo-Kinshasa', 'Congo, Democratic Republic of the'],
        'CF' => ['Central African Republic', 'République centrafricaine', 'Centrafrique'],
        'CG' => ['Republic of the Congo', 'République du Congo', 'Congo', 'Congo (Brazzaville)', 'Congo-Brazzaville'],
        'CH' => ['Switzerland', 'Suisse'],
        'CI' => ["Côte d'Ivoire", "Cote d'Ivoire", 'Ivory Coast'],
        'CK' => ['Cook Islands', 'Îles Cook'],
        'CL' => ['Chile', 'Chili'],
        'CM' => ['Cameroon', 'Cameroun', 'Kamerun'],
        'CN' => ['China', 'Chine', "People's Republic of China", 'République populaire de Chine'],
        'CO' => ['Colombia', 'Colombie'],
        'CR' => ['Costa Rica'],
        'CU' => ['Cuba'],
        'CV' => ['Cape Verde', 'Cabo Verde', 'Cap-Vert'],
        'CW' => ['Curaçao'],
        'CX' => ['Christmas Island', 'Île Christmas'],
        'CY' => ['Cyprus', 'Chypre'],
        'CZ' => ['Czech Republic', 'Czechia', 'République tchèque', 'Tchéquie'],
        'DE' => ['Germany', 'Allemagne'],
        'DJ' => ['Djibouti'],
        'DK' => ['Denmark', 'Danemark'],
        'DM' => ['Dominica', 'Dominique'],
        'DO' => ['Dominican Republic', 'République dominicaine'],
        'DZ' => ['Algeria', 'Algérie'],
        'EC' => ['Ecuador', 'Équateur'],
        'EE' => ['Estonia', 'Estonie'],
        'EG' => ['Egypt', 'Égypte'],
        'EH' => ['Western Sahara', 'Sahara occidental'],
        'ER' => ['Eritrea', 'Érythrée'],
        'ES' => ['Spain', 'Espagne'],
        'ET' => ['Ethiopia', 'Éthiopie'],
        'FI' => ['Finland', 'Finlande'],
        'FJ' => ['Fiji', 'Fidji'],
        'FK' => ['Falkland Islands', 'Îles Malouines', 'Îles Falkland'],
        'FM' => ['Micronesia', 'Micronésie', 'Federated States of Micronesia', 'États fédérés de Micronésie'],
        'FO' => ['Faroe Islands', 'Îles Féroé'],
        'FR' => ['France'],
        'GA' => ['Gabon'],
        'GB' => ['United Kingdom', 'Royaume-Uni', 'UK', 'Great Britain', 'Grande-Bretagne', 'Britain', 'Northern Ireland', 'Irlande du Nord'],
        'GD' => ['Grenada', 'Grenade'],
        'GE' => ['Georgia', 'Géorgie'],
        'GF' => ['French Guiana', 'Guyane française', 'Guyane'],
        'GG' => ['Guernsey', 'Guernesey'],
        'GH' => ['Ghana'],
        'GI' => ['Gibraltar'],
        'GL' => ['Greenland', 'Groenland'],
        'GM' => ['Gambia', 'Gambie'],
        'GN' => ['Guinea', 'Guinée'],
        'GP' => ['Guadeloupe'],
        'GQ' => ['Equatorial Guinea', 'Guinée équatoriale'],
        'GR' => ['Greece', 'Grèce'],
        'GS' => ['South Georgia and the South Sandwich Islands', 'Géorgie du Sud-et-les Îles Sandwich du Sud'],
        'GT' => ['Guatemala'],
        'GU' => ['Guam'],
        'GW' => ['Guinea-Bissau', 'Guinée-Bissau'],
        'GY' => ['Guyana'],
        'HK' => ['Hong Kong'],
        'HM' => ['Heard Island and McDonald Islands', 'Îles Heard-et-MacDonald'],
        'HN' => ['Honduras'],
        'HR' => ['Croatia', 'Croatie'],
        'HT' => ['Haiti', 'Haïti'],
        'HU' => ['Hungary', 'Hongrie'],
        'ID' => ['Indonesia', 'Indonésie'],
        'IE' => ['Ireland', 'Irlande'],
        'IL' => ['Israel', 'Israël'],
        'IM' => ['Isle of Man', 'Île de Man'],
        'IN' => ['India', 'Inde'],
        'IO' => ['British Indian Ocean Territory', "Territoire britannique de l'océan Indien"],
        'IQ' => ['Iraq', 'Irak'],
        'IR' => ['Iran', 'Islamic Republic of Iran'],
        'IS' => ['Iceland', 'Islande'],
        'IT' => ['Italy', 'Italie'],
        'JE' => ['Jersey'],
        'JM' => ['Jamaica', 'Jamaïque'],
        'JO' => ['Jordan', 'Jordanie'],
        'JP' => ['Japan', 'Japon'],
        'KE' => ['Kenya'],
        'KG' => ['Kyrgyzstan', 'Kirghizistan'],
        'KH' => ['Cambodia', 'Cambodge'],
        'KI' => ['Kiribati'],
        'KM' => ['Comoros', 'Comores'],
        'KN' => ['Saint Kitts and Nevis', 'Saint-Christophe-et-Niévès', 'Saint-Kitts-et-Nevis'],
        'KP' => ['North Korea', 'Corée du Nord', "Korea, Democratic People's Republic of", "Democratic People's Republic of Korea"],
        'KR' => ['South Korea', 'Corée du Sud', 'Korea, Republic of', 'Republic of Korea', 'Korea', 'Corée'],
        'KW' => ['Kuwait', 'Koweït'],
        'KY' => ['Cayman Islands', 'Îles Caïmans'],
        'KZ' => ['Kazakhstan'],
        'LA' => ['Laos', "Lao People's Democratic Republic"],
        'LB' => ['Lebanon', 'Liban'],
        'LC' => ['Saint Lucia', 'Sainte-Lucie'],
        'LI' => ['Liechtenstein'],
        'LK' => ['Sri Lanka'],
        'LR' => ['Liberia', 'Libéria'],
        'LS' => ['Lesotho'],
        'LT' => ['Lithuania', 'Lituanie'],
        'LU' => ['Luxembourg'],
        'LV' => ['Latvia', 'Lettonie'],
        'LY' => ['Libya', 'Libye'],
        'MA' => ['Morocco', 'Maroc'],
        'MC' => ['Monaco'],
        'MD' => ['Moldova', 'Moldavie', 'Republic of Moldova'],
        'ME' => ['Montenegro', 'Monténégro'],
        'MF' => ['Saint Martin', 'Saint-Martin'],
        'MG' => ['Madagascar'],
        'MH' => ['Marshall Islands', 'Îles Marshall'],
        'MK' => ['North Macedonia', 'Macédoine du Nord', 'Macedonia', 'Macédoine'],
        'ML' => ['Mali'],
        'MM' => ['Myanmar', 'Birmanie', 'Burma'],
        'MN' => ['Mongolia', 'Mongolie'],
        'MO' => ['Macau', 'Macao'],
        'MP' => ['Northern Mariana Islands', 'Îles Mariannes du Nord'],
        'MQ' => ['Martinique'],
        'MR' => ['Mauritania', 'Mauritanie'],
        'MS' => ['Montserrat'],
        'MT' => ['Malta', 'Malte'],
        'MU' => ['Mauritius', 'Maurice', 'Île Maurice'],
        'MV' => ['Maldives'],
        'MW' => ['Malawi'],
        'MX' => ['Mexico', 'Mexique'],
        'MY' => ['Malaysia', 'Malaisie'],
        'MZ' => ['Mozambique'],
        'NA' => ['Namibia', 'Namibie'],
        'NC' => ['New Caledonia', 'Nouvelle-Calédonie'],
        'NE' => ['Niger'],
        'NF' => ['Norfolk Island', 'Île Norfolk'],
        'NG' => ['Nigeria', 'Nigéria'],
        'NI' => ['Nicaragua'],
        'NL' => ['Netherlands', 'Pays-Bas', 'Holland', 'Hollande'],
        'NO' => ['Norway', 'Norvège'],
        'NP' => ['Nepal', 'Népal'],
        'NR' => ['Nauru'],
        'NU' => ['Niue'],
        'NZ' => ['New Zealand', 'Nouvelle-Zélande'],
        'OM' => ['Oman'],
        'PA' => ['Panama'],
        'PE' => ['Peru', 'Pérou'],
        'PF' => ['French Polynesia', 'Polynésie française'],
        'PG' => ['Papua New Guinea', 'Papouasie-Nouvelle-Guinée'],
        'PH' => ['Philippines'],
        'PK' => ['Pakistan'],
        'PL' => ['Poland', 'Pologne'],
        'PM' => ['Saint Pierre and Miquelon', 'Saint-Pierre-et-Miquelon'],
        'PN' => ['Pitcairn Islands', 'Pitcairn', 'Îles Pitcairn'],
        'PR' => ['Puerto Rico', 'Porto Rico'],
        'PS' => ['Palestine', 'State of Palestine', 'Palestinian Territory'],
        'PT' => ['Portugal'],
        'PW' => ['Palau', 'Palaos'],
        'PY' => ['Paraguay'],
        'QA' => ['Qatar'],
        'RE' => ['Réunion', 'La Réunion'],
        'RO' => ['Romania', 'Roumanie'],
        'RS' => ['Serbia', 'Serbie'],
        'RU' => ['Russia', 'Russie', 'Russian Federation', 'Fédération de Russie'],
        'RW' => ['Rwanda'],
        'SA' => ['Saudi Arabia', 'Arabie saoudite'],
        'SB' => ['Solomon Islands', 'Îles Salomon'],
        'SC' => ['Seychelles'],
        'SD' => ['Sudan', 'Soudan'],
        'SE' => ['Sweden', 'Suède'],
        'SG' => ['Singapore', 'Singapour'],
        'SH' => ['Saint Helena', 'Sainte-Hélène'],
        'SI' => ['Slovenia', 'Slovénie'],
        'SJ' => ['Svalbard and Jan Mayen', 'Svalbard et Jan Mayen'],
        'SK' => ['Slovakia', 'Slovaquie'],
        'SL' => ['Sierra Leone'],
        'SM' => ['San Marino', 'Saint-Marin'],
        'SN' => ['Senegal', 'Sénégal'],
        'SO' => ['Somalia', 'Somalie'],
        'SR' => ['Suriname'],
        'SS' => ['South Sudan', 'Soudan du Sud'],
        'ST' => ['São Tomé and Príncipe', 'Sao Tome and Principe', 'Sao Tomé-et-Principe', 'São Tomé-et-Príncipe'],
        'SV' => ['El Salvador', 'Salvador'],
        'SX' => ['Sint Maarten'],
        'SY' => ['Syria', 'Syrie', 'Syrian Arab Republic'],
        'SZ' => ['Eswatini', 'Swaziland'],
        'TC' => ['Turks and Caicos Islands', 'Îles Turques-et-Caïques'],
        'TD' => ['Chad', 'Tchad'],
        'TF' => ['French Southern Territories', 'Terres australes françaises'],
        'TG' => ['Togo'],
        'TH' => ['Thailand', 'Thaïlande'],
        'TJ' => ['Tajikistan', 'Tadjikistan'],
        'TK' => ['Tokelau'],
        'TL' => ['Timor-Leste', 'East Timor', 'Timor oriental'],
        'TM' => ['Turkmenistan', 'Turkménistan'],
        'TN' => ['Tunisia', 'Tunisie'],
        'TO' => ['Tonga'],
        'TR' => ['Turkey', 'Türkiye', 'Turkiye', 'Turquie'],
        'TT' => ['Trinidad and Tobago', 'Trinité-et-Tobago'],
        'TV' => ['Tuvalu'],
        'TW' => ['Taiwan', 'Taïwan'],
        'TZ' => ['Tanzania', 'Tanzanie', 'United Republic of Tanzania'],
        'UA' => ['Ukraine'],
        'UG' => ['Uganda', 'Ouganda'],
        'UM' => ['United States Minor Outlying Islands'],
        'US' => ['United States', 'United States of America', 'USA', 'US', 'États-Unis', 'Etats-Unis', "États-Unis d'Amérique", 'America', 'Amérique'],
        'UY' => ['Uruguay'],
        'UZ' => ['Uzbekistan', 'Ouzbékistan'],
        'VA' => ['Vatican', 'Vatican City', 'Holy See', 'Saint-Siège'],
        'VC' => ['Saint Vincent and the Grenadines', 'Saint-Vincent-et-les-Grenadines'],
        'VE' => ['Venezuela'],
        'VG' => ['British Virgin Islands', 'Îles Vierges britanniques'],
        'VI' => ['U.S. Virgin Islands', 'United States Virgin Islands', 'Îles Vierges des États-Unis'],
        'VN' => ['Vietnam', 'Viet Nam', 'Viêt Nam'],
        'VU' => ['Vanuatu'],
        'WF' => ['Wallis and Futuna', 'Wallis-et-Futuna'],
        'WS' => ['Samoa'],
        'XK' => ['Kosovo'],
        'YE' => ['Yemen', 'Yémen'],
        'YT' => ['Mayotte'],
        'ZA' => ['South Africa', 'Afrique du Sud'],
        'ZM' => ['Zambia', 'Zambie'],
        'ZW' => ['Zimbabwe'],
        'GB-ENG' => ['England', 'Angleterre'],
        'GB-SCT' => ['Scotland', 'Écosse'],
        'GB-WLS' => ['Wales', 'Pays de Galles'],
    ];

    /** @var array<string, string>|null Index key(nom) => code, construit à la première demande. */
    private static ?array $index = null;

    /**
     * Code ISO (« CM », ou « GB-ENG » pour une nation britannique) depuis un nom
     * de pays ou un code, null si inconnu.
     */
    public static function code(?string $country): ?string
    {
        if ($country === null || trim($country) === '') {
            return null;
        }

        $upper = strtoupper(trim($country));
        if (isset(self::ALIASES[$upper])) {
            return $upper;
        }

        return self::index()[self::key($country)] ?? null;
    }

    /** Drapeau emoji du pays (nom ou code), null si le pays n'est pas reconnu. */
    public static function flag(?string $country): ?string
    {
        $code = self::code($country);
        if ($code === null) {
            return null;
        }

        // Nations du Royaume-Uni : drapeau noir + séquence de tags (ex. 🏴 + "gbeng").
        if (str_starts_with($code, 'GB-')) {
            $tags = strtolower(str_replace('-', '', $code));
            $flag = "\u{1F3F4}";
            foreach (str_split($tags) as $char) {
                $flag .= mb_chr(0xE0000 + ord($char), 'UTF-8');
            }

            return $flag . "\u{E007F}";
        }

        // Indicateurs régionaux : 'A' => U+1F1E6 … 'Z' => U+1F1FF.
        return mb_chr(0x1F1E6 + ord($code[0]) - ord('A'), 'UTF-8')
            . mb_chr(0x1F1E6 + ord($code[1]) - ord('A'), 'UTF-8');
    }

    /** Clé de comparaison : ASCII, minuscules, lettres et chiffres seulement. */
    public static function key(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($value)));
    }

    /** @return array<string, string> */
    private static function index(): array
    {
        if (self::$index === null) {
            self::$index = [];
            foreach (self::ALIASES as $code => $names) {
                foreach ($names as $name) {
                    // Le premier alias déclaré garde la priorité en cas de collision.
                    self::$index[self::key($name)] ??= $code;
                }
            }
        }

        return self::$index;
    }
}
