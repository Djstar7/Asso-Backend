<?php

namespace App\Models\Concerns;

use App\Models\Translation;
use App\Support\Translation\ContentLocale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Champs traduisibles : la colonne garde le français, les autres langues sont
 * dans la table translations. Lus dans la langue de la requête (SetLocale),
 * avec repli sur le français quand la traduction manque.
 *
 * Le modèle déclare :
 *   protected array $translatable = ['name', 'description'];
 * et, pour une ancienne colonne déjà traduite :
 *   protected array $translationColumns = ['name' => ['en' => 'name_en']];
 */
trait HasTranslations
{
    public static function bootHasTranslations(): void
    {
        // Une seule requête de plus par liste, et seulement hors français.
        static::addGlobalScope('translations', function (Builder $query) {
            if (ContentLocale::localizing($query->getModel()::class)) {
                $query->with('translations');
            }
        });

        static::deleted(function ($model) {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }
            $model->translations()->delete();
        });
    }

    public function translations(): MorphMany
    {
        return $this->morphMany(Translation::class, 'translatable');
    }

    public function translatableFields(): array
    {
        return $this->translatable ?? [];
    }

    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);

        if (! is_string($key) || ! in_array($key, $this->translatableFields(), true) || ! ContentLocale::localizing(static::class)) {
            return $value;
        }

        $translated = $this->getTranslation($key, app()->getLocale());

        return $translated === null ? $value : $this->mergeTranslation($key, $value, $translated);
    }

    public function attributesToArray()
    {
        $attributes = parent::attributesToArray();

        if (ContentLocale::localizing(static::class)) {
            foreach ($this->translatableFields() as $field) {
                if (array_key_exists($field, $attributes)
                    && ($translated = $this->getTranslation($field, app()->getLocale())) !== null) {
                    $attributes[$field] = $this->mergeTranslation($field, $attributes[$field], $translated);
                }
            }
        }

        return $attributes;
    }

    public function relationsToArray()
    {
        // Les traductions brutes ne sortent que via translationsPayload().
        return array_diff_key(parent::relationsToArray(), ['translations' => true]);
    }

    /** Valeur dans $locale, sans repli (null si absente). */
    public function getTranslation(string $field, string $locale): mixed
    {
        if ($locale === ContentLocale::SOURCE) {
            return parent::getAttribute($field);
        }

        if ($column = $this->translationColumns[$field][$locale] ?? null) {
            $value = parent::getAttribute($column);

            return filled($value) ? $value : null;
        }

        $row = $this->translations->first(fn (Translation $t) => $t->locale === $locale && $t->field === $field);
        if (! $row || blank($row->value)) {
            return null;
        }

        return $this->isJsonTranslation($field) ? json_decode($row->value, true) : $row->value;
    }

    /** Enregistre (ou supprime si vide) la valeur de $field dans $locale. */
    public function setTranslation(string $field, string $locale, mixed $value): void
    {
        if ($column = $this->translationColumns[$field][$locale] ?? null) {
            $this->forceFill([$column => blank($value) ? null : $value])->save();

            return;
        }

        if (is_array($value)) {
            $filtered = array_filter($value, fn ($item) => filled($item));
            $value = array_is_list($value) ? array_values($filtered) : $filtered;
        }

        if (blank($value)) {
            $this->translations()->where(['locale' => $locale, 'field' => $field])->delete();
        } else {
            $this->translations()->updateOrCreate(
                ['locale' => $locale, 'field' => $field],
                ['value' => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value],
            );
        }

        $this->unsetRelation('translations');
    }

    /**
     * Enregistre les traductions envoyées par un formulaire :
     * ['en' => ['name' => '…', 'description' => '…']]. Seuls les champs
     * présents sont touchés ; un champ vide supprime la traduction.
     */
    public function syncTranslations(?array $input): void
    {
        foreach (ContentLocale::targets() as $locale) {
            $values = $input[$locale] ?? null;
            if (! is_array($values)) {
                continue;
            }
            foreach ($this->translatableFields() as $field) {
                if (array_key_exists($field, $values)) {
                    $this->setTranslation($field, $locale, $values[$field]);
                }
            }
        }
    }

    /** ['en' => ['name' => '…' | null, …]] : pour pré-remplir un formulaire. */
    public function translationsPayload(): array
    {
        $payload = [];
        foreach (ContentLocale::targets() as $locale) {
            foreach ($this->translatableFields() as $field) {
                $payload[$locale][$field] = $this->getTranslation($field, $locale);
            }
        }

        return $payload;
    }

    /** Vrai si tous les champs non vides en français ont leur traduction. */
    public function isTranslatedIn(string $locale, ?array $fields = null): bool
    {
        foreach ($fields ?? $this->translatableFields() as $field) {
            if (filled(parent::getAttribute($field)) && $this->getTranslation($field, $locale) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Valeur rendue à partir de la valeur française et de sa traduction. Par
     * défaut la traduction remplace tout ; un modèle dont le champ JSON ne
     * traduit que des libellés (grille de livraison) redéfinit cette méthode.
     */
    protected function mergeTranslation(string $field, mixed $source, mixed $translated): mixed
    {
        return $translated;
    }

    private function isJsonTranslation(string $field): bool
    {
        return $this->hasCast($field, ['array', 'json', 'collection', 'object']);
    }
}
