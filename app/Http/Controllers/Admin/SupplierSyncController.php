<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\SupplierWebhookEvent;
use App\Services\Suppliers\Cj\CjApiException;
use App\Services\Suppliers\SupplierManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Synchronisation CJ dans le back-office (admin uniquement) : alertes CJ,
 * produits suivis, relecture planifiée et journal des alertes reçues.
 */
class SupplierSyncController extends Controller
{
    public function __construct(private SupplierManager $suppliers) {}

    public function index(Request $request)
    {
        $driver = $this->suppliers->driver(Supplier::CJ);
        $cj = $driver->supplier();
        $webhooks = $driver->webhooks();
        $products = SupplierProduct::where('supplier_id', $cj->id);
        $events = SupplierWebhookEvent::where('supplier_id', $cj->id);
        $hours = (int) $cj->setting('resync_after_hours', 20);

        $log = (clone $events)->latest('id');
        if ($type = $request->input('type')) {
            $log->where('type', $type);
        }
        if ($status = $request->input('status')) {
            $log->where('status', $status);
        }

        return view('admin.suppliers.sync', [
            'cj' => $cj,
            'enabled' => $webhooks->isEnabled(),
            'callbackUrl' => $webhooks->callbackUrl(),
            'urlProblem' => $webhooks->callbackUrlProblem(),
            'urlChanged' => $webhooks->isEnabled() && $cj->setting('webhook_url_hash') !== hash('sha256', $webhooks->callbackUrl()),
            'lastEvent' => (clone $events)->latest('id')->first(),
            'counts24h' => (clone $events)->where('created_at', '>=', now()->subDay())
                ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all(),
            'rejected24h' => (int) Cache::get('suppliers:cj:webhook-rejected', 0),
            'subscribed' => (clone $products)->whereNotNull('subscribed_at')->count(),
            'expired' => (clone $products)->where('subscribed_at', '<', now()->subDays(\App\Services\Suppliers\Cj\CjWebhooks::SUBSCRIPTION_DAYS))->count(),
            'toSubscribe' => (clone $products)->where('status', SupplierProduct::IMPORTED)->whereNotNull('product_id')->whereNull('subscribed_at')->count(),
            'limit' => (int) $cj->setting('subscription_limit', 1000),
            'stale' => (clone $products)->whereIn('status', [SupplierProduct::IMPORTED, SupplierProduct::NO_SHIPPING, SupplierProduct::ERROR])
                ->whereNotNull('product_id')
                ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subHours($hours)))->count(),
            'hours' => $hours,
            'log' => $log->paginate(30)->withQueryString(),
        ]);
    }

    public function enable()
    {
        $driver = $this->suppliers->driver(Supplier::CJ);
        $cj = $driver->supplier();
        $webhooks = $driver->webhooks();
        if (! $cj->isConnected()) {
            return back()->with('error', 'Testez d’abord la connexion CJ (onglet Connexion).');
        }
        if ($problem = $webhooks->callbackUrlProblem()) {
            return back()->with('error', 'CJ refusera cette adresse : '.$problem.' Réglez APP_URL ou CJ_WEBHOOK_BASE_URL (adresse d’un tunnel en local).');
        }

        try {
            // L'openId (clé de signature) n'est renvoyé qu'à l'obtention d'un nouveau jeton.
            if (blank($cj->account_id)) {
                $driver->client()->forgetTokens();
                $driver->client()->accessToken();
                $cj->refresh();
            }
            $webhooks->configure(true);
        } catch (CjApiException $e) {
            return back()->with('error', 'CJ a refusé l’activation : '.$e->describe());
        }
        Artisan::queue('cj:subscribe-products');

        return back()->with('success', 'Alertes CJ activées. Les produits importés sont en cours d’inscription (quelques minutes).'
            .(blank($cj->account_id) ? ' Attention : identifiant de signature CJ inconnu, chaque alerte sera revérifiée auprès de CJ.' : ''));
    }

    public function disable()
    {
        try {
            $this->suppliers->driver(Supplier::CJ)->webhooks()->configure(false);
        } catch (CjApiException $e) {
            return back()->with('error', 'CJ a refusé la désactivation : '.$e->describe());
        }

        return back()->with('success', 'Alertes CJ coupées. La relecture de nuit continue de mettre les fiches à jour.');
    }

    /** Nouvelle adresse secrète : l'ancienne répond 404 dès maintenant. */
    public function rotateToken()
    {
        $driver = $this->suppliers->driver(Supplier::CJ);
        $cj = $driver->supplier();
        $old = $cj->webhook_token;
        $cj->forceFill(['webhook_token' => Str::random(48)])->save();

        if ($driver->webhooks()->isEnabled()) {
            try {
                $driver->webhooks()->configure(true);
            } catch (CjApiException $e) {
                $cj->forceFill(['webhook_token' => $old])->save();

                return back()->with('error', 'Adresse inchangée : CJ a refusé la nouvelle adresse. '.$e->describe());
            }
        }

        return back()->with('success', 'Nouvelle adresse secrète enregistrée'.($driver->webhooks()->isEnabled() ? ' et transmise à CJ.' : '.'));
    }

    public function subscribe()
    {
        Artisan::queue('cj:subscribe-products');

        return back()->with('success', 'Inscription des produits lancée en arrière-plan.');
    }

    public function resync()
    {
        Artisan::queue('cj:resync', ['--hours' => 0]);

        return back()->with('success', 'Relecture de toutes les fiches lancée en arrière-plan (environ 40 minutes pour 1 000 produits).');
    }
}
