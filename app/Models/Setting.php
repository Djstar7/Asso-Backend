<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use App\Support\Translation\ContentLocale;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use HasTranslations;

    /** Champs traduisibles (voir HasTranslations). */
    protected array $translatable = ['value'];

    /** Réglages publics dont le texte se traduit (clé => règles), voir ContentLocale::rules. */
    public const TRANSLATABLE_KEYS = [
        'app_slogan' => 'string|max:255',
        'app_description' => 'string',
        'contact_address' => 'string|max:255',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'description',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Cache duration in minutes.
     *
     * @var int
     */
    const CACHE_DURATION = 60;

    /**
     * Récupérer la valeur d'un paramètre par sa clé.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $cacheKey = self::cacheKey('setting_' . $key);

        return Cache::remember($cacheKey, self::CACHE_DURATION * 60, function () use ($key, $default) {
            $setting = self::where('key', $key)->first();

            if (!$setting) {
                return $default;
            }

            return self::castValue($setting->value, $setting->type);
        });
    }

    /**
     * Définir ou mettre à jour un paramètre.
     *
     * @param string $key
     * @param mixed $value
     * @param string $type
     * @param string|null $group
     * @param string|null $description
     * @return Setting
     */
    public static function set(string $key, $value, string $type = 'string', ?string $group = null, ?string $description = null): Setting
    {
        // Convertir la valeur en chaîne selon le type
        $storedValue = self::prepareValueForStorage($value, $type);

        $setting = self::updateOrCreate(
            ['key' => $key],
            [
                'value' => $storedValue,
                'type' => $type,
                'group' => $group,
                'description' => $description,
            ]
        );

        // Vider le cache pour cette clé
        self::forgetCached($key, $group);

        return $setting;
    }

    /**
     * Récupérer tous les paramètres d'un groupe.
     *
     * @param string $group
     * @return array
     */
    public static function getByGroup(string $group): array
    {
        $cacheKey = self::cacheKey('settings_group_' . $group);

        return Cache::remember($cacheKey, self::CACHE_DURATION * 60, function () use ($group) {
            $settings = self::where('group', $group)->get();

            $result = [];
            foreach ($settings as $setting) {
                $result[$setting->key] = self::castValue($setting->value, $setting->type);
            }

            return $result;
        });
    }

    /**
     * Convertir la valeur selon son type.
     *
     * @param mixed $value
     * @param string $type
     * @return mixed
     */
    protected static function castValue($value, string $type)
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $value,
            'json' => json_decode($value, true),
            'text', 'string', 'file' => $value,
            default => $value,
        };
    }

    /**
     * Préparer la valeur pour le stockage.
     *
     * @param mixed $value
     * @param string $type
     * @return string
     */
    protected static function prepareValueForStorage($value, string $type): string
    {
        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'integer' => (string) $value,
            'json' => json_encode($value),
            default => (string) $value,
        };
    }

    /** Clé de cache : les valeurs traduites sont mises en cache par langue. */
    private static function cacheKey(string $base): string
    {
        return ContentLocale::localizing(self::class) ? $base . ':' . app()->getLocale() : $base;
    }

    public static function forgetCached(string $key, ?string $group): void
    {
        foreach (array_merge([null], ContentLocale::targets()) as $locale) {
            $suffix = $locale ? ':' . $locale : '';
            Cache::forget('setting_' . $key . $suffix);
            if ($group) {
                Cache::forget('settings_group_' . $group . $suffix);
            }
        }
    }

    /**
     * Vider tout le cache des paramètres.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        $settings = self::all();

        foreach ($settings as $setting) {
            self::forgetCached($setting->key, $setting->group);
        }
    }

    /**
     * Boot method.
     */
    protected static function boot()
    {
        parent::boot();

        // Vider le cache lors de la mise à jour ou suppression
        static::updated(function ($setting) {
            self::forgetCached($setting->key, $setting->group);
        });

        static::deleted(function ($setting) {
            self::forgetCached($setting->key, $setting->group);
        });
    }
}
