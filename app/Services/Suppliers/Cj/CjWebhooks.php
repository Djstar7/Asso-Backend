<?php

namespace App\Services\Suppliers\Cj;

use App\Jobs\ImportCjProductJob;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\SupplierVariant;
use App\Models\SupplierWebhookEvent;
use Illuminate\Support\Facades\DB;

/**
 * Alertes CJ (webhooks) : configuration chez CJ, vérification de la
 * signature, inscription des produits suivis et traitement des messages.
 *
 * CJ ne renvoie jamais une alerte ratée et n'envoie pas les changements de
 * prix seuls : la resynchronisation planifiée (cj:resync) reste la source de
 * vérité pour les prix, les alertes servent à réagir vite.
 */
class CjWebhooks
{
    /** Sujets activés chez CJ (commandes et suivi : utilisés aux lots 4 et 5). */
    public const TOPICS = ['product', 'stock', 'order', 'logistics'];

    public const SUBSCRIBE_BATCH = 100;

    public function __construct(private Supplier $supplier, private CjClient $client) {}

    /** Adresse donnée à CJ : le jeton secret du fournisseur est dans le chemin. */
    public function callbackUrl(): string
    {
        $base = rtrim((string) (config('suppliers.cj.webhook_base_url') ?: config('app.url')), '/');

        return $base.'/api/v1/suppliers/cj/webhook/'.$this->supplier->webhook_token;
    }

    /** Raison pour laquelle CJ refusera l'adresse, ou null si elle convient. */
    public function callbackUrlProblem(): ?string
    {
        $parts = parse_url($this->callbackUrl());
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https') {
            return 'L’adresse doit être en https.';
        }
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.test')
            || (filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            return 'L’adresse doit être publique (pas localhost ni une adresse locale).';
        }

        return null;
    }

    public function isEnabled(): bool
    {
        return filled($this->supplier->setting('webhook_enabled_at'));
    }

    /** Active (ou coupe) les alertes CJ vers l'adresse de ce serveur. */
    public function configure(bool $enable): void
    {
        $type = $enable ? 'ENABLE' : 'CANCEL';
        $url = $this->callbackUrl();
        $body = [];
        foreach (self::TOPICS as $topic) {
            $body[$topic] = ['type' => $type, 'callbackUrls' => [$url]];
        }
        $this->client->post('webhook/set', $body);

        $this->supplier->putSettings($enable
            ? ['webhook_enabled_at' => now()->toIso8601String(), 'webhook_url_hash' => hash('sha256', $url)]
            : ['webhook_enabled_at' => null]);
    }

    /**
     * Signature CJ : HMAC-SHA256 du corps brut, clé = openId du compte, en
     * Base64. Renvoie null si l'openId est inconnu (signature invérifiable).
     */
    public function verifySignature(string $rawBody, ?string $sign): ?bool
    {
        $openId = (string) $this->supplier->account_id;
        if ($openId === '') {
            return null;
        }
        $expected = base64_encode(hash_hmac('sha256', $rawBody, $openId, true));

        return $sign !== null && hash_equals($expected, trim($sign));
    }

    /**
     * Met la liste des produits suivis chez CJ en accord avec ASSO : inscrit
     * les produits importés (dans la limite du niveau du compte), désinscrit
     * ceux retirés ou dont la fiche a été supprimée.
     *
     * @return array{subscribed: int, unsubscribed: int, failed: int, error: ?string}
     */
    public function syncSubscriptions(): array
    {
        $result = ['subscribed' => 0, 'unsubscribed' => 0, 'failed' => 0, 'error' => null];
        $base = SupplierProduct::where('supplier_id', $this->supplier->id);

        $gone = (clone $base)->whereNotNull('subscribed_at')
            ->where(fn ($q) => $q->where('status', SupplierProduct::DELISTED)->orWhereNull('product_id'))
            ->limit(1000)->pluck('external_id')->all();
        foreach (array_chunk($gone, self::SUBSCRIBE_BATCH) as $chunk) {
            try {
                $this->client->post('webhook/product/unsubscribe', ['productIds' => $chunk]);
            } catch (CjApiException $e) {
                $result['error'] = 'Désinscription refusée par CJ : '.$e->describe();

                return $result;
            }
            (clone $base)->whereIn('external_id', $chunk)->update(['subscribed_at' => null]);
            $result['unsubscribed'] += count($chunk);
        }

        $room = (int) $this->supplier->setting('subscription_limit', 1000) - (clone $base)->whereNotNull('subscribed_at')->count();
        if ($room <= 0) {
            return $result;
        }
        $pending = (clone $base)->where('status', SupplierProduct::IMPORTED)->whereNotNull('product_id')
            ->whereNull('subscribed_at')
            ->where(fn ($q) => $q->whereNull('subscribe_failed_at')->orWhere('subscribe_failed_at', '<', now()->subDay()))
            ->orderBy('id')->limit($room)->pluck('external_id')->all();

        foreach (array_chunk($pending, self::SUBSCRIBE_BATCH) as $chunk) {
            try {
                $data = (array) $this->client->post('webhook/product/subscribe', ['productIds' => $chunk, 'subscribeAll' => false]);
            } catch (CjApiException $e) {
                $result['error'] = match ($e->cjCode) {
                    1606010 => 'Les alertes produits ne sont pas activées chez CJ : cliquez sur « Activer les alertes CJ ».',
                    1606011 => 'Limite de produits suivis atteinte pour le niveau du compte CJ.',
                    default => 'Inscription refusée par CJ : '.$e->describe(),
                };

                return $result;
            }
            $ok = array_map('strval', (array) ($data['successProductIds'] ?? []));
            $failed = array_values(array_diff($chunk, $ok));
            if ($ok) {
                (clone $base)->whereIn('external_id', $ok)->update(['subscribed_at' => now(), 'subscribe_failed_at' => null]);
            }
            if ($failed) {
                // Déjà inscrit, ou produit qui n'existe plus : nouvel essai demain.
                (clone $base)->whereIn('external_id', $failed)->update(['subscribe_failed_at' => now()]);
            }
            $result['subscribed'] += count($ok);
            $result['failed'] += count($failed);
        }

        return $result;
    }

    /** Traite une alerte enregistrée (appelé par ProcessCjWebhookJob). */
    public function process(SupplierWebhookEvent $event): void
    {
        $params = $event->payload['params'] ?? null;

        [$status, $note] = match (strtoupper($event->type)) {
            'PRODUCT' => $this->onProduct(is_array($params) ? $params : []),
            'VARIANT' => $this->onVariant(is_array($params) ? $params : [], (string) $event->message_type),
            'STOCK' => $this->onStock(is_array($params) ? $params : [], $event->signature_verified),
            'ORDER' => app(\App\Services\Suppliers\SupplierManager::class)->driver(Supplier::CJ)->orders()
                ->onWebhook(is_array($params) ? $params : [], (string) $event->message_type),
            default => [SupplierWebhookEvent::IGNORED, 'Type d’alerte pas encore traité par ASSO.'],
        };

        $event->finish($status, $note);
    }

    private function onProduct(array $params): array
    {
        $pid = (string) ($params['pid'] ?? '');
        $row = $pid !== '' ? $this->row($pid) : null;
        if (! $row) {
            return [SupplierWebhookEvent::IGNORED, 'Produit non importé dans ASSO.'];
        }
        $this->refresh($row);

        return [SupplierWebhookEvent::PROCESSED, 'Fiche relue chez CJ ('.implode(', ', array_slice((array) ($params['fields'] ?? []), 0, 10)).').'];
    }

    private function onVariant(array $params, string $messageType): array
    {
        $vid = (string) ($params['vid'] ?? '');
        if ($vid === '') {
            return [SupplierWebhookEvent::IGNORED, 'Alerte sans déclinaison.'];
        }
        $row = SupplierVariant::where('external_id', $vid)
            ->whereHas('supplierProduct', fn ($q) => $q->where('supplier_id', $this->supplier->id))
            ->first()?->supplierProduct;

        // Nouvelle déclinaison : CJ n'envoie que son identifiant, on retrouve le produit.
        if (! $row && strtoupper($messageType) === 'INSERT') {
            try {
                $pid = (string) (((array) $this->client->get('product/variant/queryByVid', ['vid' => $vid]))['pid'] ?? '');
            } catch (CjApiException) {
                $pid = '';
            }
            $row = $pid !== '' ? $this->row($pid) : null;
        }
        if (! $row) {
            return [SupplierWebhookEvent::IGNORED, 'Déclinaison d’un produit non importé.'];
        }
        $this->refresh($row);

        return [SupplierWebhookEvent::PROCESSED, 'Déclinaison '.$vid.' : fiche relue chez CJ.'];
    }

    /**
     * Stocks : appliqués directement si la signature est vérifiée (aucun appel
     * CJ). Sinon l'alerte ne fait que déclencher une relecture de la fiche.
     */
    private function onStock(array $params, bool $verified): array
    {
        $from = strtoupper((string) $this->supplier->setting('from_country_code', 'CN'));
        $variants = SupplierVariant::whereIn('external_id', array_map('strval', array_keys($params)))
            ->whereHas('supplierProduct', fn ($q) => $q->where('supplier_id', $this->supplier->id))
            ->with('supplierProduct')->get();
        if ($variants->isEmpty()) {
            return [SupplierWebhookEvent::IGNORED, 'Stocks de produits non importés.'];
        }

        if (! $verified) {
            $variants->pluck('supplierProduct')->unique('id')->each(fn ($row) => $this->refresh($row));

            return [SupplierWebhookEvent::PROCESSED, 'Signature non vérifiable : fiches relues chez CJ.'];
        }

        $touched = [];
        $updated = 0;
        DB::transaction(function () use ($variants, $params, $from, &$touched, &$updated) {
            foreach ($variants as $variant) {
                $entries = array_filter((array) $params[$variant->external_id],
                    fn ($e) => is_array($e) && strtoupper((string) ($e['countryCode'] ?? '')) === $from);
                if ($entries === []) {
                    continue; // aucun mouvement dans l'entrepôt de départ
                }
                $stock = max(0, (int) array_sum(array_map(fn ($e) => (int) ($e['storageNum'] ?? 0), $entries)));
                $variant->update(['stock' => $stock]);
                if ($variant->product_variant_id) {
                    ProductVariant::whereKey($variant->product_variant_id)->update(['stock' => $stock]);
                }
                $touched[$variant->supplier_product_id] = $variant->supplierProduct;
                $updated++;
            }
            foreach ($touched as $row) {
                if ($row->product_id) {
                    $total = (int) SupplierVariant::where('supplier_product_id', $row->id)->where('is_active', true)->sum('stock');
                    Product::withoutGlobalScopes()->find($row->product_id)?->update(['stock' => $total]);
                }
            }
        });

        return $updated > 0
            ? [SupplierWebhookEvent::PROCESSED, "{$updated} stock(s) de déclinaison mis à jour."]
            : [SupplierWebhookEvent::IGNORED, 'Aucun stock dans l’entrepôt de départ ('.$from.').'];
    }

    private function row(string $pid): ?SupplierProduct
    {
        return SupplierProduct::where('supplier_id', $this->supplier->id)->where('external_id', $pid)->first();
    }

    /** Relecture complète de la fiche chez CJ, en file (une seule par produit à la fois). */
    private function refresh(SupplierProduct $row): void
    {
        ImportCjProductJob::dispatch($row->external_id);
    }
}
