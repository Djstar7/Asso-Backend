<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DisputeShipment;
use App\Services\DisputeService;
use Illuminate\Http\Request;

/**
 * Courses de litige côté livreur partenaire : produit de remplacement (boutique →
 * client) ou retour (client → boutique). Même logique qu'une commande : accepter,
 * récupérer le colis, remettre contre le code à 6 chiffres du destinataire.
 */
class DisputeCourseController extends Controller
{
    public function __construct(private DisputeService $disputes)
    {
    }

    /** GET /api/v1/delivery/dispute-courses */
    public function index(Request $request)
    {
        $courier = $request->user();

        return response()->json([
            'success' => true,
            'courses' => $this->disputes->courierShipments($courier)
                ->map(fn (DisputeShipment $s) => $this->disputes->courseToApi($s, $courier))
                ->values(),
        ]);
    }

    /** POST /api/v1/delivery/dispute-courses/{id}/accept */
    public function accept(Request $request, $id)
    {
        return $this->run($request, $id, fn ($shipment, $courier) => $this->disputes->acceptCourse($shipment, $courier));
    }

    /** POST /api/v1/delivery/dispute-courses/{id}/pickup */
    public function pickup(Request $request, $id)
    {
        return $this->run($request, $id, fn ($shipment, $courier) => $this->disputes->pickUpCourse($shipment, $courier));
    }

    /** POST /api/v1/delivery/dispute-courses/{id}/complete {confirmation_code} */
    public function complete(Request $request, $id)
    {
        $code = $request->validate(['confirmation_code' => 'required|string|size:6'])['confirmation_code'];

        return $this->run($request, $id, fn ($shipment, $courier) => $this->disputes->completeCourse($shipment, $courier, $code), __('disputes.course_completed'));
    }

    private function run(Request $request, $id, callable $action, ?string $message = null)
    {
        $courier = $request->user();
        $shipment = DisputeShipment::with(['dispute.order.user', 'dispute.item.product.shop.user', 'company'])->findOrFail($id);

        try {
            $shipment = $action($shipment, $courier);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(array_filter([
            'success' => true,
            'message' => $message,
            'course' => $this->disputes->courseToApi($shipment->fresh(['dispute.order.user', 'dispute.item.product.shop.user', 'company']), $courier),
        ], fn ($v) => $v !== null));
    }
}
