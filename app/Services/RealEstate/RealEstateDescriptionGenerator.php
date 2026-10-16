<?php

namespace App\Services\RealEstate;

use App\Models\RealEstate\Listing;

/**
 * Description générée à partir du questionnaire du bailleur (sans IA) :
 * accès à la route, quartier, eau, électricité, sécurité… Les phrases
 * viennent des fichiers de langue, donc suivent la langue de la requête.
 * Le texte libre du bailleur est ajouté à la suite.
 */
class RealEstateDescriptionGenerator
{
    public function generate(Listing $listing): string
    {
        $t = fn (string $key, array $replace = []) => __("real_estate.description.$key", $replace);
        $lines = [];

        $place = $listing->district ? "{$listing->district}, {$listing->city}" : $listing->city;
        $kind = __('real_estate.kinds.'.($listing->property_kind ?: ($listing->isLand() ? 'land' : 'house')));

        if ($listing->isLand()) {
            $surface = $listing->surfaceRangeLabel();
            $lines[] = $t('land_intro', ['place' => $place, 'surface' => $surface ?: '-']);
            $lines[] = $listing->land_titled ? $t('land_titled') : $t('land_not_titled');
            if ($listing->serviced) {
                $lines[] = $t('land_serviced');
            }
        } else {
            $intro = $t('house_intro', ['kind' => $kind, 'place' => $place]);
            $parts = [];
            if ($listing->rooms) {
                $parts[] = trans_choice('real_estate.description.rooms', $listing->rooms, ['count' => $listing->rooms]);
            }
            if ($listing->bedrooms) {
                $parts[] = trans_choice('real_estate.description.bedrooms', $listing->bedrooms, ['count' => $listing->bedrooms]);
            }
            if ($listing->bathrooms) {
                $parts[] = trans_choice('real_estate.description.bathrooms', $listing->bathrooms, ['count' => $listing->bathrooms]);
            }
            if ($listing->surface_m2) {
                $parts[] = "{$listing->surface_m2} m²";
            }
            $lines[] = $parts ? $intro.' '.$t('composed_of', ['parts' => implode(', ', $parts)]) : $intro.'.';
            $lines[] = $listing->furnished ? $t('furnished') : $t('unfurnished');
        }

        // Accès.
        if ($listing->road_access === 'roadside') {
            $lines[] = $t('roadside');
        } elseif ($listing->distance_to_road_m) {
            $lines[] = $t('distance_to_road', ['meters' => $listing->distance_to_road_m]);
        }
        if ($listing->road_condition) {
            $lines[] = $t('road_condition_'.$listing->road_condition);
        }
        $lines[] = $listing->is_landlocked ? $t('landlocked') : $t('not_landlocked');

        // Commodités.
        if ($listing->water_source) {
            $lines[] = $t('water_'.$listing->water_source);
        }
        if ($listing->electricity) {
            $lines[] = $t('electricity_'.$listing->electricity);
        }
        if ($listing->security_level) {
            $lines[] = $t('security_'.$listing->security_level);
        }
        $extras = array_filter([
            $listing->has_parking ? $t('parking') : null,
            $listing->has_fence ? $t('fence') : null,
            $listing->has_guard ? $t('guard') : null,
        ]);
        if ($extras) {
            $lines[] = $t('extras', ['items' => implode(', ', $extras)]);
        }

        if (! $listing->isLand()) {
            if ($listing->min_lease_months) {
                $lines[] = $t('min_lease', ['months' => $listing->min_lease_months]);
            }
            if ($listing->advance_months) {
                $lines[] = $t('advance', ['months' => $listing->advance_months]);
            }
            $lines[] = $listing->available_from && $listing->available_from->isFuture()
                ? $t('available_from', ['date' => $listing->available_from->translatedFormat('j F Y')])
                : $t('available_now');
        }

        $text = implode(' ', array_filter($lines));
        if ($listing->owner_description) {
            $text .= "\n\n".trim($listing->owner_description);
        }

        return $text;
    }
}
