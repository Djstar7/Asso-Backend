<?php

namespace App\Support;

/** Country outlines for the delivery map; carrier coverage remains configured by ASSO. */
class DeliveryCountryBoundaries
{
    private static ?array $countries = null;

    public static function all(): array
    {
        return self::$countries ??= json_decode(file_get_contents(database_path('data/delivery-country-boundaries.json')), true, flags: JSON_THROW_ON_ERROR)['countries'];
    }

    public static function countryAt(float $lat, float $lng): ?string
    {
        foreach (self::all() as $country => $area) {
            foreach ($area['polygons'] as $i => $rings) {
                [$south, $west, $north, $east] = $area['bounds'][$i];
                if ($lat < $south || $lat > $north || $lng < $west || $lng > $east
                    || ! ExportCoverage::contains($rings[0], $lat, $lng)) {
                    continue;
                }
                foreach (array_slice($rings, 1) as $hole) {
                    if (ExportCoverage::contains($hole, $lat, $lng)) {
                        continue 2;
                    }
                }

                return $country;
            }
        }
        // Small coastal islands can disappear at this map scale (e.g. Manhattan).
        // The address geocoder's explicit country takes precedence in the caller.
        $nearest = null;
        $bestKm = 5.0;
        $cos = cos(deg2rad($lat));
        foreach (self::all() as $country => $area) {
            foreach ($area['polygons'] as $i => $rings) {
                [$south, $west, $north, $east] = $area['bounds'][$i];
                if ($lat < $south - 0.05 || $lat > $north + 0.05 || $lng < $west - 0.2 || $lng > $east + 0.2) {
                    continue;
                }
                $ring = $rings[0];
                for ($j = 1; $j < count($ring); $j++) {
                    $ay = ($ring[$j - 1][0] - $lat) * 111;
                    $ax = ($ring[$j - 1][1] - $lng) * 111 * $cos;
                    $by = ($ring[$j][0] - $lat) * 111;
                    $bx = ($ring[$j][1] - $lng) * 111 * $cos;
                    $dx = $bx - $ax;
                    $dy = $by - $ay;
                    $length = $dx * $dx + $dy * $dy;
                    $t = $length > 0 ? max(0, min(1, -($ax * $dx + $ay * $dy) / $length)) : 0;
                    $distance = hypot($ax + $t * $dx, $ay + $t * $dy);
                    if ($distance < $bestKm) {
                        $bestKm = $distance;
                        $nearest = $country;
                    }
                }
            }
        }

        return $nearest;
    }
}
