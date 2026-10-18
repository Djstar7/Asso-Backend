<?php

namespace App\Services;

use App\Models\DelivererCompany;
use App\Models\User;
use App\Support\Translation\LocalizedText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Compte interne d'un partenaire (livraison ou maritime), géré par ASSO.
 *
 * Même principe que le compte plateforme : ses courses et son fret sont
 * crédités sur le Wallet de ce compte, et l'admin les lui retire depuis sa
 * fiche avec la même page que le retrait ASSO (Mobile Money par pays, IBAN
 * Stripe). Le compte n'a pas de mot de passe connu : personne ne s'y connecte.
 */
class PartnerAccountService
{
    /** Domaine des adresses techniques des comptes partenaires. */
    public const EMAIL_DOMAIN = 'partenaires.asso.app';

    /** Compte du partenaire, créé à la première utilisation. */
    public static function accountFor(DelivererCompany $company): User
    {
        return DB::transaction(function () use ($company) {
            $locked = DelivererCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            if ($locked->account_user_id && ($user = User::find($locked->account_user_id))) {
                return $user;
            }

            $user = User::create([
                'first_name' => Str::limit($locked->name, 100, ''),
                'last_name' => '(compte partenaire)',
                'email' => 'partenaire-' . $locked->id . '@' . self::EMAIL_DOMAIN,
                'password' => Hash::make(Str::random(48)),
                'role' => 'client',
                'country' => 'Cameroun',
            ]);
            $locked->update(['account_user_id' => $user->id]);
            $company->account_user_id = $user->id;

            return $user;
        });
    }

    public static function isPartnerAccount(?User $user): bool
    {
        return $user !== null && str_ends_with((string) $user->email, '@' . self::EMAIL_DOMAIN);
    }

    /**
     * Crédite le Wallet du partenaire (XAF) pour une course ou un fret.
     * Idempotent par référence : une même course n'est créditée qu'une fois.
     */
    public static function credit(int $companyId, float $amountXaf, LocalizedText $label, string $referenceType, int $referenceId, array $metadata = []): void
    {
        if ($amountXaf <= 0 || !($company = DelivererCompany::find($companyId))) {
            return;
        }

        $account = self::accountFor($company);
        $already = \App\Models\WalletTransaction::where('user_id', $account->id)
            ->where('type', 'credit')
            ->where('metadata->partner_reference', "{$referenceType}:{$referenceId}")
            ->exists();
        if ($already) {
            return;
        }

        app(WalletService::class)->credit(
            $account,
            round($amountXaf, 2),
            null,
            $label,
            $metadata + ['partner_reference' => "{$referenceType}:{$referenceId}", 'deliverer_company_id' => $company->id, 'direct_settlement' => true],
            'kpay'
        );
    }
}
