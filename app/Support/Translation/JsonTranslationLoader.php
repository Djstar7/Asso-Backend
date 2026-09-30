<?php

namespace App\Support\Translation;

use Illuminate\Contracts\Translation\Loader;
use Illuminate\Filesystem\Filesystem;

/**
 * Toutes les traductions d'une langue tiennent dans un seul fichier,
 * `lang/<langue>.json`, imbriqué par module comme côté mobile :
 *
 *     { "validation": { "required": "…" }, "wallet": { "insufficient": "…" } }
 *
 * Chaque premier niveau sert de « groupe » Laravel : `__('wallet.insufficient')`
 * et les messages natifs (`validation.required`, `auth.failed`…) s'y
 * résolvent. Ajouter une langue revient à traduire un seul fichier.
 */
class JsonTranslationLoader implements Loader
{
    /** @var array<string, array<string, mixed>> */
    private array $files = [];

    /**
     * [$fallback] garde les traductions des paquets (espaces de noms
     * `paquet::cle`), qui ne passent pas par nos fichiers.
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly string $path,
        private readonly Loader $fallback,
    ) {}

    public function load($locale, $group, $namespace = null): array
    {
        if ($namespace !== null && $namespace !== '*') {
            return $this->fallback->load($locale, $group, $namespace);
        }

        // Les clés sont toutes rangées par groupe : pas de lignes JSON à plat.
        if ($group === '*') {
            return [];
        }

        $value = $this->file($locale)[$group] ?? [];

        return is_array($value) ? $value : [];
    }

    /** @return array<string, mixed> */
    private function file(string $locale): array
    {
        if (! array_key_exists($locale, $this->files)) {
            $file = $this->path.DIRECTORY_SEPARATOR.$locale.'.json';
            $this->files[$locale] = $this->filesystem->exists($file)
                ? (json_decode($this->filesystem->get($file), true, 512, JSON_THROW_ON_ERROR) ?? [])
                : [];
        }

        return $this->files[$locale];
    }

    public function addNamespace($namespace, $hint): void
    {
        $this->fallback->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path): void
    {
        $this->fallback->addJsonPath($path);
    }

    public function namespaces(): array
    {
        return $this->fallback->namespaces();
    }
}
