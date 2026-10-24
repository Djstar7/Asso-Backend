<?php

namespace App\Support;

class InternationalCarrierSchema
{
    public const REQUIRED_COLUMNS = [
        'delivery_territories' => ['code', 'metadata'],
        'delivery_city_grids' => ['department_code', 'coverage_scope'],
        'delivery_routes' => ['network_key', 'export_config'],
        'delivery_agencies' => ['country', 'latitude', 'longitude', 'is_international_reference', 'is_provisional',
            'source_dataset', 'source_url', 'verified_at', 'coordinate_precision', 'collection_pricing'],
        'orders' => ['recipient_name', 'delivery_postal_code', 'collection_agency_id', 'collection_completed_at'],
        'deliverer_sync_codes' => ['delivery_agency_id'],
        'deliverer_code_syncs' => ['delivery_agency_id'],
        'dispute_shipments' => ['quote', 'stops', 'payer', 'payment_status', 'carrier_tracking_number'],
    ];
}
