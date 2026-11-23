<?php

namespace App\Services\Suppliers\Cj;

use App\Models\Supplier;
use App\Services\ProductVariantService;
use App\Services\Suppliers\SupplierDriver;
use App\Services\Suppliers\SupplierImageStore;

/** Fournisseur CJdropshipping. */
class CjDriver implements SupplierDriver
{
    private CjClient $client;

    public function __construct(private Supplier $supplier)
    {
        $this->client = new CjClient($supplier);
    }

    public function code(): string
    {
        return Supplier::CJ;
    }

    public function supplier(): Supplier
    {
        return $this->supplier;
    }

    public function client(): CjClient
    {
        return $this->client;
    }

    public function catalog(): CjCatalog
    {
        return new CjCatalog($this->supplier, $this->client);
    }

    public function importer(): CjProductImporter
    {
        return new CjProductImporter(
            $this->supplier,
            $this->catalog(),
            app(SupplierImageStore::class),
            app(ProductVariantService::class),
        );
    }

    public function testConnection(): array
    {
        if (! $this->supplier->hasApiKey()) {
            return ['ok' => false, 'message' => 'Enregistrez d’abord la clé API CJ.', 'balance_usd' => null];
        }

        try {
            $this->client->accessToken();
            $balance = $this->refreshBalance();
        } catch (CjApiException $e) {
            $this->supplier->forceFill(['connected_at' => null])->save();
            $this->supplier->recordError($e->describe());

            return ['ok' => false, 'message' => $this->explain($e), 'balance_usd' => null];
        }

        $this->supplier->forceFill(['connected_at' => now()])->save();
        $this->supplier->clearError();

        return ['ok' => true, 'message' => 'Connexion à CJ réussie.', 'balance_usd' => $balance];
    }

    public function refreshBalance(): ?float
    {
        $data = $this->client->get('shopping/pay/getBalance');
        $amount = is_array($data) && isset($data['amount']) ? (float) $data['amount'] : null;

        $this->supplier->forceFill([
            'balance_usd' => $amount,
            'balance_frozen_usd' => is_array($data) && isset($data['freezeAmount']) ? (float) $data['freezeAmount'] : null,
            'balance_checked_at' => now(),
        ])->save();

        return $amount;
    }

    /** Message compréhensible pour l'admin, selon le code CJ. */
    private function explain(CjApiException $e): string
    {
        return match ($e->cjCode) {
            CjApiException::WRONG_API_KEY, CjApiException::USER_NOT_FOUND, CjApiException::INVALID_TOKEN => 'Clé API refusée par CJ : vérifiez-la sur la page « My CJ API » de votre compte CJ. '.$e->describe(),
            CjApiException::API_DISABLED => 'L’accès API de ce compte CJ est suspendu : réactivez-le sur la page « My CJ API ». '.$e->describe(),
            CjApiException::TOO_MANY_REQUESTS, CjApiException::QUOTA_EXHAUSTED => 'CJ limite le nombre d’appels : réessayez dans quelques minutes. '.$e->describe(),
            default => 'CJ a refusé la connexion : '.$e->describe(),
        };
    }
}
