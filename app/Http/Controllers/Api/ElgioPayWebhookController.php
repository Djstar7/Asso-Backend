<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Wallet\ProcessElgioPayEventJob;
use App\Services\ElgioPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/v1/elgiopay/callback
 *
 * Répond immédiatement (ElgioPay ré-émet en cas de lenteur/erreur) et délègue le
 * traitement à la file : `deposits` pour payment.*, `withdrawals` pour payout.*.
 *
 * Le corps du webhook n'est qu'un DÉCLENCHEUR : le job relit le statut auprès de
 * l'API ElgioPay (authentifiée) avant tout crédit/débit, donc un faux webhook ne
 * peut rien créditer. La signature est exigée dès qu'un secret webhook dédié est
 * renseigné ; sans lui, une signature non vérifiable est journalisée mais le
 * traitement (re-vérifié par l'API) continue.
 */
class ElgioPayWebhookController extends Controller
{
    public function handle(Request $request, ElgioPayService $elgiopay)
    {
        $raw = $request->getContent();
        $signature = $request->header('X-Elgiopay-Signature');
        $valid = $elgiopay->verifyWebhookSignature($raw, $signature);

        if (!$valid && $elgiopay->hasDedicatedWebhookSecret()) {
            Log::warning('[ElgioPay webhook] Signature invalide', ['event' => $request->header('X-Elgiopay-Event')]);
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        // Enveloppe SDK { id, event, created, data } ou corps plat (doc publique).
        $event = (string) ($payload['event'] ?? $request->header('X-Elgiopay-Event') ?? '');
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        $isPayout = str_starts_with($event, 'payout.') || !empty($data['payout_id']);
        $id = $isPayout
            ? ($data['payout_id'] ?? $data['id'] ?? null)
            : ($data['transaction_id'] ?? $data['id'] ?? null);

        Log::info('[ElgioPay webhook] Reçu', [
            'event' => $event,
            'id' => $id,
            'reference' => $data['reference'] ?? null,
            'status' => $data['status'] ?? null,
            'signature' => $valid ? 'ok' : ($signature ? 'unverified' : 'absent'),
            'signature_format' => $signature ? (str_contains($signature, 'v1=') ? 't=,v1=' : 'hex(' . strlen($signature) . ')') : null,
            'elgiopay_headers' => array_values(array_filter(array_keys($request->headers->all()), fn ($h) => str_contains($h, 'elgio'))),
        ]);

        if (!$id && empty($data['reference'])) {
            return response()->json(['message' => 'Ignored (no transaction id)']);
        }

        // Dédoublonnage des ré-émissions (même événement livré plusieurs fois).
        $eventId = $payload['id'] ?? $request->header('X-Elgiopay-Event-Id')
            ?? sha1($event . '|' . $id . '|' . ($data['status'] ?? ''));
        if (!Cache::add('elgiopay_webhook_' . $eventId, 1, now()->addDay())) {
            return response()->json(['message' => 'Already received']);
        }

        ProcessElgioPayEventJob::dispatch($isPayout ? 'payout' : 'payment', $id, $data['reference'] ?? null)
            ->onQueue($isPayout ? 'withdrawals' : 'deposits');

        return response()->json(['message' => 'Accepted']);
    }
}
