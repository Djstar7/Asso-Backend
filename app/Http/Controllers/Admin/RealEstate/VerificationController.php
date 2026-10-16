<?php

namespace App\Http\Controllers\Admin\RealEstate;

use App\Http\Controllers\Controller;
use App\Models\RealEstate\LandlordVerification;
use App\Services\RealEstate\LandlordVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Vérification des bailleurs : CNI, preuve de propriété, vidéo. */
class VerificationController extends Controller
{
    public function __construct(private LandlordVerificationService $service)
    {
    }

    public function index(Request $request)
    {
        $status = $request->query('status', 'pending');
        $verifications = LandlordVerification::with(['user', 'reviewer'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest('updated_at')->paginate(25)->withQueryString();

        return view('admin.real_estate.verifications.index', [
            'verifications' => $verifications,
            'status' => $status,
            'counts' => LandlordVerification::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function show(LandlordVerification $verification)
    {
        $verification->load(['user', 'reviewer']);
        $listings = \App\Models\RealEstate\Listing::where('owner_id', $verification->user_id)->latest()->get();

        return view('admin.real_estate.verifications.show', compact('verification', 'listings'));
    }

    /** Sert une pièce privée à l'administration uniquement. */
    public function document(LandlordVerification $verification, string $field)
    {
        $column = [
            'id_front' => 'id_front_path', 'id_back' => 'id_back_path',
            'ownership_proof' => 'ownership_proof_path', 'video' => 'video_path',
        ][$field] ?? abort(404);
        $path = $verification->$column;
        abort_unless($path && Storage::disk(LandlordVerificationService::DISK)->exists($path), 404);

        return Storage::disk(LandlordVerificationService::DISK)->response($path);
    }

    public function approve(Request $request, LandlordVerification $verification)
    {
        $this->service->approve($verification, $request->user()->id);

        return back()->with('success', 'Bailleur vérifié : ses annonces peuvent être publiées.');
    }

    public function reject(Request $request, LandlordVerification $verification)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->service->reject($verification, $data['reason'], $request->user()->id);

        return back()->with('success', 'Pièces refusées, le bailleur est prévenu.');
    }
}
