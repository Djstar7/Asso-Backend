<?php

namespace App\Support\Translation;

use Stringable;

/**
 * Texte enregistré en base (historique des notifications, libellé d'une
 * opération du portefeuille) : on garde sa clé et ses valeurs à côté du
 * texte rédigé, pour le rédiger à nouveau dans la langue du lecteur.
 *
 * Une valeur peut elle-même être un LocalizedText (raison par défaut,
 * étape du suivi…) : elle est traduite dans la même langue.
 */
class LocalizedText implements Stringable
{
    /** @param  array<string, mixed>  $replace */
    public function __construct(
        public readonly string $key,
        public readonly array $replace = [],
        public readonly ?string $locale = null,
    ) {}

    /** Rédige le texte (langue donnée, sinon celle du texte, sinon celle de la requête). */
    public function render(?string $locale = null): string
    {
        $locale ??= $this->locale;
        $replace = array_map(
            fn ($value) => $value instanceof self ? $value->render($locale) : (string) $value,
            $this->replace
        );

        return __($this->key, $replace, $locale);
    }

    public function __toString(): string
    {
        return $this->render();
    }

    /** @return array{key: string, params: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'params' => array_map(
                fn ($value) => $value instanceof self ? $value->toArray() : $value,
                $this->replace
            ),
        ];
    }

    /** Inverse de toArray() ; null si la forme enregistrée n'est pas reconnue. */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || ! is_string($data['key'] ?? null)) {
            return null;
        }

        $params = array_map(
            fn ($value) => self::fromArray($value) ?? (is_scalar($value) || $value === null ? $value : ''),
            is_array($data['params'] ?? null) ? $data['params'] : []
        );

        return new self($data['key'], $params);
    }

    /**
     * Texte enregistré rendu dans la langue de la requête. Repli sur le texte
     * stocké si la clé n'existe plus.
     */
    public static function renderStored(mixed $data, ?string $stored): ?string
    {
        $text = self::fromArray($data);
        if ($text === null) {
            return $stored;
        }

        $rendered = $text->render();

        return $rendered === $text->key ? $stored : $rendered;
    }
}
