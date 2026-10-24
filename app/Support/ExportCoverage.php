<?php

namespace App\Support;

/** Destination polygon: [latitude, longitude] points drawn on the OSM map. */
class ExportCoverage
{
    public static function contains(array $polygon, ?float $lat, ?float $lng): bool
    {
        if ($lat === null || $lng === null || count($polygon) < 3) {
            return false;
        }
        $inside = false;
        for ($i = 0, $j = count($polygon) - 1; $i < count($polygon); $j = $i++) {
            [$a, $b] = $polygon[$i];
            [$c, $d] = $polygon[$j];
            // Points on the boundary belong to the zone.
            $cross = ($lat - $a) * ($d - $b) - ($lng - $b) * ($c - $a);
            if (abs($cross) < 1e-9 && $lat >= min($a, $c) && $lat <= max($a, $c)
                && $lng >= min($b, $d) && $lng <= max($b, $d)) {
                return true;
            }
            if (($a > $lat) !== ($c > $lat) && $lng < ($d - $b) * ($lat - $a) / ($c - $a) + $b) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
