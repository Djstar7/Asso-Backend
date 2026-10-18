<?php

namespace App\Support;

/**
 * Pays des trajets de livraison : les boutiques stockent un nom libre
 * (« Cameroun », « Cameroon », « Dubaï »…), les trajets un code ISO à 2 lettres.
 */
class CountryCode
{
    public const NAMES = [
        'AD' => 'Andorre',
        'AE' => 'Émirats arabes unis (Dubaï)',
        'AF' => 'Afghanistan',
        'AG' => 'Antigua-et-Barbuda',
        'AI' => 'Anguilla',
        'AL' => 'Albanie',
        'AM' => 'Arménie',
        'AO' => 'Angola',
        'AQ' => 'Antarctique',
        'AR' => 'Argentine',
        'AS' => 'Samoa américaines',
        'AT' => 'Autriche',
        'AU' => 'Australie',
        'AW' => 'Aruba',
        'AX' => 'Îles Åland',
        'AZ' => 'Azerbaïdjan',
        'BA' => 'Bosnie-Herzégovine',
        'BB' => 'Barbade',
        'BD' => 'Bangladesh',
        'BE' => 'Belgique',
        'BF' => 'Burkina Faso',
        'BG' => 'Bulgarie',
        'BH' => 'Bahreïn',
        'BI' => 'Burundi',
        'BJ' => 'Bénin',
        'BL' => 'Saint-Barthélemy',
        'BM' => 'Bermudes',
        'BN' => 'Brunei',
        'BO' => 'Bolivie',
        'BQ' => 'Pays-Bas caribéens',
        'BR' => 'Brésil',
        'BS' => 'Bahamas',
        'BT' => 'Bhoutan',
        'BV' => 'Île Bouvet',
        'BW' => 'Botswana',
        'BY' => 'Biélorussie',
        'BZ' => 'Belize',
        'CA' => 'Canada',
        'CC' => 'Îles Cocos',
        'CD' => 'République démocratique du Congo',
        'CF' => 'Centrafrique',
        'CG' => 'Congo',
        'CH' => 'Suisse',
        'CI' => 'Côte d’Ivoire',
        'CK' => 'Îles Cook',
        'CL' => 'Chili',
        'CM' => 'Cameroun',
        'CN' => 'Chine',
        'CO' => 'Colombie',
        'CR' => 'Costa Rica',
        'CU' => 'Cuba',
        'CV' => 'Cap-Vert',
        'CW' => 'Curaçao',
        'CX' => 'Île Christmas',
        'CY' => 'Chypre',
        'CZ' => 'Tchéquie',
        'DE' => 'Allemagne',
        'DJ' => 'Djibouti',
        'DK' => 'Danemark',
        'DM' => 'Dominique',
        'DO' => 'République dominicaine',
        'DZ' => 'Algérie',
        'EC' => 'Équateur',
        'EE' => 'Estonie',
        'EG' => 'Égypte',
        'EH' => 'Sahara occidental',
        'ER' => 'Érythrée',
        'ES' => 'Espagne',
        'ET' => 'Éthiopie',
        'FI' => 'Finlande',
        'FJ' => 'Fidji',
        'FK' => 'Îles Malouines',
        'FM' => 'Micronésie',
        'FO' => 'Îles Féroé',
        'FR' => 'France',
        'GA' => 'Gabon',
        'GB' => 'Royaume-Uni',
        'GD' => 'Grenade',
        'GE' => 'Géorgie',
        'GF' => 'Guyane française',
        'GG' => 'Guernesey',
        'GH' => 'Ghana',
        'GI' => 'Gibraltar',
        'GL' => 'Groenland',
        'GM' => 'Gambie',
        'GN' => 'Guinée',
        'GP' => 'Guadeloupe',
        'GQ' => 'Guinée équatoriale',
        'GR' => 'Grèce',
        'GS' => 'Géorgie du Sud-et-les Îles Sandwich du Sud',
        'GT' => 'Guatemala',
        'GU' => 'Guam',
        'GW' => 'Guinée-Bissau',
        'GY' => 'Guyana',
        'HK' => 'R.A.S. chinoise de Hong Kong',
        'HM' => 'Îles Heard-et-MacDonald',
        'HN' => 'Honduras',
        'HR' => 'Croatie',
        'HT' => 'Haïti',
        'HU' => 'Hongrie',
        'ID' => 'Indonésie',
        'IE' => 'Irlande',
        'IL' => 'Israël',
        'IM' => 'Île de Man',
        'IN' => 'Inde',
        'IO' => 'Territoire britannique de l’océan Indien',
        'IQ' => 'Irak',
        'IR' => 'Iran',
        'IS' => 'Islande',
        'IT' => 'Italie',
        'JE' => 'Jersey',
        'JM' => 'Jamaïque',
        'JO' => 'Jordanie',
        'JP' => 'Japon',
        'KE' => 'Kenya',
        'KG' => 'Kirghizstan',
        'KH' => 'Cambodge',
        'KI' => 'Kiribati',
        'KM' => 'Comores',
        'KN' => 'Saint-Christophe-et-Niévès',
        'KP' => 'Corée du Nord',
        'KR' => 'Corée du Sud',
        'KW' => 'Koweït',
        'KY' => 'Îles Caïmans',
        'KZ' => 'Kazakhstan',
        'LA' => 'Laos',
        'LB' => 'Liban',
        'LC' => 'Sainte-Lucie',
        'LI' => 'Liechtenstein',
        'LK' => 'Sri Lanka',
        'LR' => 'Liberia',
        'LS' => 'Lesotho',
        'LT' => 'Lituanie',
        'LU' => 'Luxembourg',
        'LV' => 'Lettonie',
        'LY' => 'Libye',
        'MA' => 'Maroc',
        'MC' => 'Monaco',
        'MD' => 'Moldavie',
        'ME' => 'Monténégro',
        'MF' => 'Saint-Martin',
        'MG' => 'Madagascar',
        'MH' => 'Îles Marshall',
        'MK' => 'Macédoine du Nord',
        'ML' => 'Mali',
        'MM' => 'Myanmar (Birmanie)',
        'MN' => 'Mongolie',
        'MO' => 'R.A.S. chinoise de Macao',
        'MP' => 'Îles Mariannes du Nord',
        'MQ' => 'Martinique',
        'MR' => 'Mauritanie',
        'MS' => 'Montserrat',
        'MT' => 'Malte',
        'MU' => 'Maurice',
        'MV' => 'Maldives',
        'MW' => 'Malawi',
        'MX' => 'Mexique',
        'MY' => 'Malaisie',
        'MZ' => 'Mozambique',
        'NA' => 'Namibie',
        'NC' => 'Nouvelle-Calédonie',
        'NE' => 'Niger',
        'NF' => 'Île Norfolk',
        'NG' => 'Nigeria',
        'NI' => 'Nicaragua',
        'NL' => 'Pays-Bas',
        'NO' => 'Norvège',
        'NP' => 'Népal',
        'NR' => 'Nauru',
        'NU' => 'Niue',
        'NZ' => 'Nouvelle-Zélande',
        'OM' => 'Oman',
        'PA' => 'Panama',
        'PE' => 'Pérou',
        'PF' => 'Polynésie française',
        'PG' => 'Papouasie-Nouvelle-Guinée',
        'PH' => 'Philippines',
        'PK' => 'Pakistan',
        'PL' => 'Pologne',
        'PM' => 'Saint-Pierre-et-Miquelon',
        'PN' => 'Îles Pitcairn',
        'PR' => 'Porto Rico',
        'PS' => 'Territoires palestiniens',
        'PT' => 'Portugal',
        'PW' => 'Palaos',
        'PY' => 'Paraguay',
        'QA' => 'Qatar',
        'RE' => 'La Réunion',
        'RO' => 'Roumanie',
        'RS' => 'Serbie',
        'RU' => 'Russie',
        'RW' => 'Rwanda',
        'SA' => 'Arabie saoudite',
        'SB' => 'Îles Salomon',
        'SC' => 'Seychelles',
        'SD' => 'Soudan',
        'SE' => 'Suède',
        'SG' => 'Singapour',
        'SH' => 'Sainte-Hélène',
        'SI' => 'Slovénie',
        'SJ' => 'Svalbard et Jan Mayen',
        'SK' => 'Slovaquie',
        'SL' => 'Sierra Leone',
        'SM' => 'Saint-Marin',
        'SN' => 'Sénégal',
        'SO' => 'Somalie',
        'SR' => 'Suriname',
        'SS' => 'Soudan du Sud',
        'ST' => 'Sao Tomé-et-Principe',
        'SV' => 'Salvador',
        'SX' => 'Saint-Martin (partie néerlandaise)',
        'SY' => 'Syrie',
        'SZ' => 'Eswatini',
        'TC' => 'Îles Turques-et-Caïques',
        'TD' => 'Tchad',
        'TF' => 'Terres australes françaises',
        'TG' => 'Togo',
        'TH' => 'Thaïlande',
        'TJ' => 'Tadjikistan',
        'TK' => 'Tokelau',
        'TL' => 'Timor oriental',
        'TM' => 'Turkménistan',
        'TN' => 'Tunisie',
        'TO' => 'Tonga',
        'TR' => 'Turquie',
        'TT' => 'Trinité-et-Tobago',
        'TV' => 'Tuvalu',
        'TW' => 'Taïwan',
        'TZ' => 'Tanzanie',
        'UA' => 'Ukraine',
        'UG' => 'Ouganda',
        'UM' => 'Îles mineures éloignées des États-Unis',
        'US' => 'États-Unis',
        'UY' => 'Uruguay',
        'UZ' => 'Ouzbékistan',
        'VA' => 'État de la Cité du Vatican',
        'VC' => 'Saint-Vincent-et-les Grenadines',
        'VE' => 'Venezuela',
        'VG' => 'Îles Vierges britanniques',
        'VI' => 'Îles Vierges des États-Unis',
        'VN' => 'Viêt Nam',
        'VU' => 'Vanuatu',
        'WF' => 'Wallis-et-Futuna',
        'WS' => 'Samoa',
        'XK' => 'Kosovo',
        'YE' => 'Yémen',
        'YT' => 'Mayotte',
        'ZA' => 'Afrique du Sud',
        'ZM' => 'Zambie',
        'ZW' => 'Zimbabwe',
    ];

    private const ALIASES = [
        'cameroun' => 'CM', 'cameroon' => 'CM', 'kamerun' => 'CM',
        'chine' => 'CN', 'china' => 'CN',
        'emiratsarabesunis' => 'AE', 'unitedarabemirates' => 'AE', 'dubai' => 'AE', 'uae' => 'AE', 'eau' => 'AE',
        'turquie' => 'TR', 'turkey' => 'TR', 'turkiye' => 'TR',
        'france' => 'FR', 'belgique' => 'BE', 'belgium' => 'BE', 'allemagne' => 'DE', 'germany' => 'DE',
        'royaumeuni' => 'GB', 'unitedkingdom' => 'GB', 'uk' => 'GB',
        'etatsunis' => 'US', 'unitedstates' => 'US', 'usa' => 'US', 'canada' => 'CA',
        'nigeria' => 'NG', 'gabon' => 'GA', 'tchad' => 'TD', 'chad' => 'TD', 'congo' => 'CG',
        'congokinshasa' => 'CD', 'democraticrepublicofthecongo' => 'CD', 'rdc' => 'CD',
        'guineeequatoriale' => 'GQ', 'centrafrique' => 'CF', 'benin' => 'BJ', 'togo' => 'TG',
        'cotedivoire' => 'CI', 'senegal' => 'SN',
    ];

    private static array $normalizedNames = [];

    /** Code ISO à 2 lettres depuis un code ou un nom de pays, null si inconnu. */
    public static function normalize(?string $country): ?string
    {
        if ($country === null || trim($country) === '') {
            return null;
        }

        $upper = strtoupper(trim($country));
        if (strlen($upper) === 2 && isset(self::NAMES[$upper])) {
            return $upper;
        }

        $key = self::key($country);
        if (isset(self::ALIASES[$key])) {
            return self::ALIASES[$key];
        }

        if (self::$normalizedNames === []) {
            foreach (self::NAMES as $code => $name) {
                self::$normalizedNames[self::key($name)] = $code;
            }
        }

        return self::$normalizedNames[$key] ?? null;
    }

    public static function name(?string $code): ?string
    {
        return $code ? (self::NAMES[strtoupper($code)] ?? strtoupper($code)) : null;
    }

    /** Nom de ville comparable : sans accents, casse ni ponctuation (« Yaoundé » = « YAOUNDE »). */
    public static function key(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $value) ?: $value;

        return preg_replace('/[^a-z0-9]/', '', strtolower($ascii));
    }

    public static function sameCity(?string $a, ?string $b): bool
    {
        if (! $a || ! $b) {
            return false;
        }

        return self::key($a) === self::key($b);
    }
}
