<?php

return [
    'collect_before_replacement' => true,
    'review_hours' => 48,
    'payment_hours' => 48,
    'receipt_contest_hours' => 48,
    'local_transit_hours' => 168,
    'international_transit_hours' => 1080,
    'dispatch_hours' => 48,
    'preparation_hours' => 48,
    'import_preparation_hours' => 720,
    // Une avance automatique nécessite une enveloppe explicitement activée.
    'automatic_advance_limit' => 0,
];
