<?php

namespace App\Services\RealEstate;

use App\Models\RealEstate\LandlordVerification;
use App\Models\RealEstate\Listing;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Vérification des bailleurs : envoi des pièces, validation ou refus par
 * l'admin. Les pièces sont rangées sur le disque privé : seules les pages
 * d'administration les servent.
 */
class LandlordVerificationService
{
    public const DISK = 'local';

    /** @param array{id_front: UploadedFile, id_back?: ?UploadedFile, ownership_proof: UploadedFile, video: UploadedFile} $files */
    public function submit(User $user, string $idType, string $proofType, array $files): LandlordVerification
    {
        $dir = "landlord_verifications/{$user->id}";
        $store = fn (?UploadedFile $file) => $file?->store($dir, self::DISK);

        $previous = LandlordVerification::where('user_id', $user->id)->first();

        $verification = DB::transaction(function () use ($user, $idType, $proofType, $files, $store) {
            $verification = LandlordVerification::updateOrCreate(['user_id' => $user->id], [
                'id_document_type' => $idType,
                'id_front_path' => $store($files['id_front']),
                'id_back_path' => $store($files['id_back'] ?? null),
                'ownership_proof_type' => $proofType,
                'ownership_proof_path' => $store($files['ownership_proof']),
                'video_path' => $store($files['video']),
                'status' => 'pending',
                'rejection_reason' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ]);
            $user->forceFill(['landlord_verification_status' => 'pending', 'landlord_verified_at' => null])->save();

            return $verification;
        });

        // Les anciennes pièces remplacées ne servent plus.
        if ($previous) {
            foreach (['id_front_path', 'id_back_path', 'ownership_proof_path', 'video_path'] as $field) {
                if ($previous->$field && $previous->$field !== $verification->$field) {
                    Storage::disk(self::DISK)->delete($previous->$field);
                }
            }
        }

        return $verification;
    }

    public function approve(LandlordVerification $verification, ?int $adminId): void
    {
        $user = $verification->user;
        DB::transaction(function () use ($verification, $user, $adminId) {
            $verification->update([
                'status' => 'verified', 'rejection_reason' => null,
                'reviewed_by' => $adminId, 'reviewed_at' => now(),
            ]);
            $user->forceFill(['landlord_verification_status' => 'verified', 'landlord_verified_at' => now()])->save();
        });

        $this->notify($user, 'real_estate_landlord_verified', ['type' => 'real_estate_landlord_verified', 'action' => 'open_landlord_space']);
    }

    public function reject(LandlordVerification $verification, string $reason, ?int $adminId): void
    {
        $user = $verification->user;
        DB::transaction(function () use ($verification, $user, $reason, $adminId) {
            $verification->update([
                'status' => 'rejected', 'rejection_reason' => $reason,
                'reviewed_by' => $adminId, 'reviewed_at' => now(),
            ]);
            $user->forceFill(['landlord_verification_status' => 'rejected', 'landlord_verified_at' => null])->save();
        });

        $this->notify($user, 'real_estate_landlord_rejected', [
            'type' => 'real_estate_landlord_rejected', 'action' => 'open_landlord_space', 'reason' => $reason,
        ], ['reason' => $reason]);
    }

    /** Annonce validée ou refusée par l'admin. */
    public function reviewListing(Listing $listing, bool $approved, ?string $reason = null): void
    {
        $listing->update($approved
            ? ['status' => Listing::STATUS_PUBLISHED, 'rejection_reason' => null, 'published_at' => $listing->published_at ?? now()]
            : ['status' => Listing::STATUS_REJECTED, 'rejection_reason' => $reason]);

        if ($listing->owner) {
            $key = $approved ? 'real_estate_listing_published' : 'real_estate_listing_rejected';
            $this->notify($listing->owner, $key, [
                'type' => $key, 'action' => 'open_landlord_listing', 'listing_id' => $listing->id,
            ], ['title' => $listing->title, 'reason' => (string) $reason]);
        }
    }

    private function notify(User $user, string $key, array $data, array $replace = []): void
    {
        try {
            app(FirebaseMessagingService::class)->sendToUser(
                $user,
                $user->localized("notifications.$key.title", $replace),
                $user->localized("notifications.$key.body", $replace),
                $data,
            );
        } catch (\Throwable $e) {
            Log::error('[IMMO] Notification non envoyée', ['user_id' => $user->id, 'key' => $key, 'error' => $e->getMessage()]);
        }
    }
}
