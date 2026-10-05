<?php

namespace App\Support\Translation;

use App\Http\Middleware\SetLocale;

/**
 * Langue du contenu saisi : le français est la langue source (colonnes
 * d'origine), les autres langues viennent de la table translations.
 */
class ContentLocale
{
    public const SOURCE = 'fr';

    /** @var array<int, array<int, class-string>|null> pile des appels raw() ; null = tous les modèles */
    private static array $rawStack = [];

    /** Langues dans lesquelles on peut traduire le contenu (hors français). */
    public static function targets(): array
    {
        return array_values(array_diff(SetLocale::SUPPORTED, [self::SOURCE]));
    }

    /**
     * Règles de validation des traductions envoyées par un formulaire :
     * translations[en][name], … Toutes facultatives.
     *
     * @param  array<string, string>  $fields  champ => règles (sans « nullable »)
     */
    public static function rules(array $fields): array
    {
        $rules = ['translations' => 'sometimes|array'];
        foreach (self::targets() as $locale) {
            $rules["translations.{$locale}"] = 'sometimes|array';
            foreach ($fields as $field => $fieldRules) {
                $rules["translations.{$locale}.{$field}"] = 'nullable|' . $fieldRules;
            }
        }

        return $rules;
    }

    /** Vrai quand les champs traduisibles (de $model) doivent être rendus dans la langue courante. */
    public static function localizing(?string $model = null): bool
    {
        if (app()->getLocale() === self::SOURCE) {
            return false;
        }
        foreach (self::$rawStack as $models) {
            if ($models === null || ($model !== null && in_array($model, $models, true))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Exécute $callback en lisant les valeurs françaises d'origine, quelle que
     * soit la langue de la requête. À utiliser pour les formulaires
     * d'édition : sinon un vendeur en anglais réenregistrerait le texte
     * anglais dans la colonne française. $models limite l'effet à ces
     * modèles (les catégories restent traduites dans la liste du vendeur).
     *
     * @param  array<int, class-string>|null  $models
     */
    public static function raw(callable $callback, ?array $models = null): mixed
    {
        self::$rawStack[] = $models;

        try {
            return $callback();
        } finally {
            array_pop(self::$rawStack);
        }
    }
}
