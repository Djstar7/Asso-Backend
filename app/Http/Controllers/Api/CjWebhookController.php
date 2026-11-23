<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCjWebhookJob;
use App\Models\Supplier;
use App\Models\SupplierWebhookEvent;
use App\Services\Suppliers\Cj\CjWebhooks;
use App\Services\Suppliers\SupplierManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Réception des alertes CJ. CJ exige une réponse 200 en moins de 3 secondes et
 * ne renvoie jamais une alerte ratée : on enregistre, on met en file, on répond.
 *
 * Protections : jeton secret dans l'adresse (404 sinon), signature HMAC du
 * corps brut avec l'openId du compte (401 si elle ne correspond pas),
 * dédoublonnage par messageId.
 */
class CjWebhookController extends Controller
{
    private const MAX_BODY_BYTES = 1048576;

    public function handle(Request $request, string $token, SupplierManager $suppliers): JsonResponse
    {
        $supplier = Supplier::where('code', Supplier::CJ)->first();
        if (! $supplier || ! is_string($supplier->webhook_token) || ! hash_equals($supplier->webhook_token, $token)) {
            abort(404);
        }

        $raw = (string) $request->getContent();
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            abort(413);
        }

        /** @var CjWebhooks $webhooks */
        $webhooks = $suppliers->driver(Supplier::CJ)->webhooks();
        $verified = $webhooks->verifySignature($raw, $request->header('sign'));
        if ($verified === false) {
            // Compteur affiché sur la page Synchronisation (alertes rejetées sur 24 h).
            Cache::add('suppliers:cj:webhook-rejected', 0, now()->addDay());
            Cache::increment('suppliers:cj:webhook-rejected');
            Log::warning('CJ webhook: signature invalide', ['ip' => $request->ip()]);

            return response()->json(['code' => 401, 'result' => 'fail', 'message' => 'invalid signature'], 401);
        }

        $message = json_decode($raw, true);
        $messageId = is_array($message) ? trim((string) ($message['messageId'] ?? '')) : '';
        $type = is_array($message) ? strtoupper(trim((string) ($message['type'] ?? ''))) : '';
        if ($messageId === '' || $type === '') {
            return response()->json(['code' => 422, 'result' => 'fail', 'message' => 'invalid message'], 422);
        }

        try {
            $event = SupplierWebhookEvent::create([
                'supplier_id' => $supplier->id,
                'message_id' => mb_substr($messageId, 0, 200),
                'type' => mb_substr($type, 0, 30),
                'message_type' => isset($message['messageType']) ? mb_substr(strtoupper((string) $message['messageType']), 0, 30) : null,
                'signature_verified' => $verified === true,
                'payload' => $message,
            ]);
            ProcessCjWebhookJob::dispatch($event->id);
        } catch (UniqueConstraintViolationException) {
            // Alerte déjà reçue : rien à refaire.
        }

        return response()->json(['code' => 200, 'result' => 'success', 'message' => 'ok']);
    }
}
