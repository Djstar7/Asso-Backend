<?php

namespace App\Support\Translation;

/**
 * Libellés enregistrés en base en français (historique d'un litige…) :
 * on retrouve leur clé dans lang/fr.json pour les afficher dans la langue
 * de la requête. Un libellé inconnu est rendu tel quel.
 */
class StoredLabel
{
    /** @param  array<int, string>  $groups  ex. ['disputes.events'] */
    public static function translate(?string $label, array $groups): ?string
    {
        if ($label === null || app()->getLocale() === ContentLocale::SOURCE) {
            return $label;
        }

        foreach ($groups as $group) {
            $key = self::find((array) trans($group, [], ContentLocale::SOURCE), $label);
            if ($key !== null) {
                return __("{$group}.{$key}");
            }
        }

        return $label;
    }

    private static function find(array $entries, string $label, string $prefix = ''): ?string
    {
        foreach ($entries as $key => $value) {
            if (is_array($value)) {
                if (($found = self::find($value, $label, "{$prefix}{$key}.")) !== null) {
                    return $found;
                }
            } elseif ($value === $label) {
                return $prefix . $key;
            }
        }

        return null;
    }
}
