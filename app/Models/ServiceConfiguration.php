<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class ServiceConfiguration extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'service_name',
        'service_type',
        'is_active',
        'configuration',
        'description',

        // WhatsApp
        'whatsapp_api_token',
        'whatsapp_phone_number_id',
        'whatsapp_api_version',
        'whatsapp_template_name',
        'whatsapp_language',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'configuration' => 'array',
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
     * Noms des services disponibles.
     */
    const SERVICE_WHATSAPP = 'whatsapp';
    const SERVICE_NEXAAH_SMS = 'nexaah_sms';
    const SERVICE_FEDAPAY = 'fedapay';
    const SERVICE_PAYPAL = 'paypal';
    const SERVICE_KPAY = 'kpay';

    /**
     * Get configuration for a specific service type (new method matching Estuaire Emploi)
     *
     * @param string $serviceType
     * @return self|null
     */
    public static function getConfigByType(string $serviceType): ?self
    {
        return Cache::remember(
            "service_config_type_{$serviceType}",
            now()->addHours(1),
            fn() => self::where('service_type', $serviceType)->first()
        );
    }

    /**
     * Récupérer la configuration d'un service.
     *
     * @param string $serviceName
     * @return array|null
     */
    public static function getConfig(string $serviceName): ?array
    {
        $cacheKey = 'service_config_' . $serviceName;

        return Cache::remember($cacheKey, self::CACHE_DURATION * 60, function () use ($serviceName) {
            $service = self::where('service_name', $serviceName)->first();

            if (!$service || !$service->is_active) {
                return null;
            }

            return $service->configuration;
        });
    }

    /**
     * Vérifier si un service est actif.
     *
     * @param string $serviceName
     * @return bool
     */
    public static function isActive(string $serviceName): bool
    {
        $cacheKey = 'service_active_' . $serviceName;

        return Cache::remember($cacheKey, self::CACHE_DURATION * 60, function () use ($serviceName) {
            $service = self::where('service_name', $serviceName)->first();

            return $service ? $service->is_active : false;
        });
    }

    /**
     * Récupérer la configuration WhatsApp (returns model instance like Estuaire Emploi).
     *
     * @return self|null
     */
    public static function getWhatsAppConfig(): ?self
    {
        return self::getConfigByType(self::SERVICE_WHATSAPP);
    }

    /**
     * Récupérer la configuration WhatsApp (legacy method for backward compatibility).
     *
     * @return array|null
     */
    public static function getWhatsAppConfigArray(): ?array
    {
        return self::getConfig(self::SERVICE_WHATSAPP);
    }

    /**
     * Récupérer la configuration Nexaah SMS.
     *
     * @return array|null
     */
    public static function getNexaahConfig(): ?array
    {
        return self::getConfig(self::SERVICE_NEXAAH_SMS);
    }

    /**
     * Récupérer la configuration Fedapay.
     *
     * @return array|null
     */
    public static function getFedapayConfig(): ?array
    {
        return self::getConfig(self::SERVICE_FEDAPAY);
    }

    /**
     * Récupérer la configuration PayPal.
     *
     * @return array|null
     */
    public static function getPayPalConfig(): ?array
    {
        return self::getConfig(self::SERVICE_PAYPAL);
    }

    /**
     * Récupérer la configuration KPay.
     *
     * @return array|null
     */
    public static function getKpayConfig(): ?array
    {
        return self::getConfig(self::SERVICE_KPAY);
    }

    /**
     * Mettre à jour ou créer une configuration de service.
     *
     * @param string $serviceName
     * @param array $configuration
     * @param bool $isActive
     * @param string|null $description
     * @return ServiceConfiguration
     */
    public static function setConfig(string $serviceName, array $configuration, bool $isActive = false, ?string $description = null): ServiceConfiguration
    {
        $service = self::updateOrCreate(
            ['service_name' => $serviceName],
            [
                'configuration' => $configuration,
                'is_active' => $isActive,
                'description' => $description,
            ]
        );

        // Vider le cache
        Cache::forget('service_config_' . $serviceName);
        Cache::forget('service_active_' . $serviceName);

        return $service;
    }

    /**
     * Activer ou désactiver un service.
     *
     * @param string $serviceName
     * @param bool $isActive
     * @return bool
     */
    public static function toggleService(string $serviceName, bool $isActive): bool
    {
        $service = self::where('service_name', $serviceName)->first();

        if (!$service) {
            return false;
        }

        $service->is_active = $isActive;
        $service->save();

        // Vider le cache
        Cache::forget('service_config_' . $serviceName);
        Cache::forget('service_active_' . $serviceName);

        return true;
    }

    /**
     * Vider tout le cache des configurations de services.
     *
     * @param string|null $serviceType
     * @return void
     */
    public static function clearCache(?string $serviceType = null): void
    {
        if ($serviceType) {
            Cache::forget("service_config_{$serviceType}");
            Cache::forget("service_config_type_{$serviceType}");
            Cache::forget("service_active_{$serviceType}");
        } else {
            $services = self::all();

            foreach ($services as $service) {
                Cache::forget('service_config_' . $service->service_name);
                Cache::forget('service_config_type_' . $service->service_type);
                Cache::forget('service_active_' . $service->service_name);
            }
        }
    }

    /**
     * Validate WhatsApp configuration
     */
    public function validateWhatsAppConfig(): array
    {
        $errors = [];

        if (empty($this->whatsapp_api_token)) {
            $errors[] = 'WhatsApp API Token is required';
        }

        if (empty($this->whatsapp_phone_number_id)) {
            $errors[] = 'WhatsApp Phone Number ID is required';
        }

        if (empty($this->whatsapp_template_name)) {
            $errors[] = 'WhatsApp Template Name is required';
        }

        return $errors;
    }

    /**
     * Check if service is properly configured and active
     */
    public function isConfigured(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $errors = match($this->service_type ?? $this->service_name) {
            'whatsapp' => $this->validateWhatsAppConfig(),
            'nexaah_sms' => [], // Add validation if needed
            'kpay' => [], // Add validation if needed
            default => [],
        };

        return empty($errors);
    }

    /**
     * Boot method.
     */
    protected static function boot()
    {
        parent::boot();

        // Vider le cache lors de la mise à jour ou suppression
        static::updated(function ($service) {
            Cache::forget('service_config_' . $service->service_name);
            Cache::forget('service_active_' . $service->service_name);
        });

        static::deleted(function ($service) {
            Cache::forget('service_config_' . $service->service_name);
            Cache::forget('service_active_' . $service->service_name);
        });
    }
}
