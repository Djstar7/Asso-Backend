<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DiaspoOffer;
use Illuminate\Http\Request;

class DiaspoOfferController extends Controller
{
    /**
     * Display a listing of the offers.
     */
    public function index(Request $request)
    {
        $query = DiaspoOffer::with(['user', 'bookings'])
            ->withCount('bookings');

        // Filter by search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('departure_country', 'like', "%{$search}%")
                    ->orWhere('departure_city', 'like', "%{$search}%")
                    ->orWhere('arrival_country', 'like', "%{$search}%")
                    ->orWhere('arrival_city', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        // Filter by status
        if ($request->filled('status')) {
            if ($request->status === 'all') {
                // No filter
            } else {
                $query->where('status', $request->status);
            }
        }

        // Filter by verification status
        if ($request->filled('verification_status')) {
            if ($request->verification_status !== 'all') {
                $query->where('verification_status', $request->verification_status);
            }
        }

        // Filter by route
        if ($request->filled('departure_country')) {
            $query->where('departure_country', 'like', "%{$request->departure_country}%");
        }
        if ($request->filled('arrival_country')) {
            $query->where('arrival_country', 'like', "%{$request->arrival_country}%");
        }

        // Order by
        $orderBy = $request->get('order_by', 'recent');
        if ($orderBy === 'oldest') {
            $query->orderBy('created_at', 'asc');
        } elseif ($orderBy === 'departure_soon') {
            $query->orderBy('departure_datetime', 'asc');
        } elseif ($orderBy === 'popular') {
            $query->orderByDesc('bookings_count');
        } else {
            $query->orderByDesc('created_at');
        }

        $offers = $query->paginate(20);

        // Stats
        $stats = [
            'total' => DiaspoOffer::count(),
            'pending' => DiaspoOffer::where('status', 'pending')->orWhere('verification_status', 'pending')->count(),
            'approved' => DiaspoOffer::where('status', 'approved')->where('verification_status', 'verified')->count(),
            'available' => DiaspoOffer::where('status', 'approved')
                ->where('verification_status', 'verified')
                ->where('remaining_kg', '>', 0)
                ->where('departure_datetime', '>', now())
                ->count(),
            'rejected' => DiaspoOffer::where('status', 'rejected')->orWhere('verification_status', 'rejected')->count(),
            'today' => DiaspoOffer::whereDate('created_at', today())->count(),
        ];

        return view('admin.diaspo.offers.index', compact('offers', 'stats'));
    }

    /**
     * Display the specified offer.
     */
    public function show(DiaspoOffer $offer)
    {
        $offer->load(['user', 'bookings.buyer', 'bookings.seller', 'verifiedBy']);

        return view('admin.diaspo.offers.show', compact('offer'));
    }

    /**
     * Approve an offer
     */
    public function approve(DiaspoOffer $offer)
    {
        try {
            $offer->update([
                'status' => 'approved',
                'verification_status' => 'verified',
                'verified_at' => now(),
                'verified_by' => auth()->id(),
                'rejection_reason' => null,
            ]);

            return redirect()
                ->back()
                ->with('success', 'L\'offre a été approuvée avec succès.');
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'Erreur lors de l\'approbation: ' . $e->getMessage());
        }
    }

    /**
     * Reject an offer
     */
    public function reject(Request $request, DiaspoOffer $offer)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            $offer->update([
                'status' => 'rejected',
                'verification_status' => 'rejected',
                'rejection_reason' => $request->reason,
                'verified_at' => now(),
                'verified_by' => auth()->id(),
            ]);

            return redirect()
                ->back()
                ->with('success', 'L\'offre a été rejetée.');
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'Erreur lors du rejet: ' . $e->getMessage());
        }
    }

    /**
     * Remove the specified offer from storage.
     */
    public function destroy(DiaspoOffer $offer)
    {
        try {
            // Cancel all pending bookings
            foreach ($offer->bookings()->pending()->get() as $booking) {
                $booking->cancel('Offre supprimée par l\'administrateur');
            }

            // Delete the offer
            $offer->delete();

            return redirect()
                ->route('admin.diaspo.offers.index')
                ->with('success', 'L\'offre a été supprimée avec succès.');
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'Erreur lors de la suppression: ' . $e->getMessage());
        }
    }

    /**
     * Cancel a booking
     */
    public function cancelBooking(DiaspoOffer $offer, $bookingId, Request $request)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            $booking = $offer->bookings()->findOrFail($bookingId);
            $booking->cancel($request->reason);

            // Restore kg to offer
            $offer->increment('remaining_kg', $booking->kg_booked);

            return redirect()
                ->back()
                ->with('success', 'La réservation a été annulée.');
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'Erreur lors de l\'annulation: ' . $e->getMessage());
        }
    }
}
