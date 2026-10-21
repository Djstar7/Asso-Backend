<?php

return [
    'enabled' => env('ADMIN_ACTION_EMAILS_ENABLED', true),
    // Un nouveau dossier ou une nouvelle étape déclenche le prochain récapitulatif.
    // Sans changement, un rappel quotidien suffit.
    'reminder_hours' => max(1, (int) env('ADMIN_ACTION_REMINDER_HOURS', 24)),
    'items_per_section' => 20,
];
