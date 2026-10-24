<?php

return [
    // Internal test references never represent a DHL/FedEx waybill.
    'export_test_tracking' => (bool) env('EXPORT_TEST_TRACKING_ENABLED', false),
];
