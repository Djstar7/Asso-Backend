<?php

namespace App\Services\RealEstate;

use App\Models\RealEstate\Listing;
use App\Models\RealEstate\Media;
use App\Models\RealEstate\Room;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Création et modification d'une annonce, partagées par l'espace bailleur
 * (maisons) et le back-office (maisons et terrains des cabinets).
 */
class ListingEditor
{
    public const DISK = 'public';

    /** Règles de validation d'une annonce ; $partial pour une mise à jour. */
    public static function rules(string $type, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $kinds = $type === Listing::TYPE_LAND ? Listing::LAND_KINDS : Listing::RENT_KINDS;

        return [
            'property_kind' => [$req, Rule::in($kinds)],
            'title' => [$req, 'string', 'max:150'],
            'owner_description' => ['nullable', 'string', 'max:5000'],
            'city' => [$req, 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'rooms' => ['nullable', 'integer', 'min:0', 'max:50'],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:50'],
            'bathrooms' => ['nullable', 'integer', 'min:0', 'max:50'],
            'surface_m2' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'furnished' => ['nullable', 'boolean'],
            'road_access' => ['nullable', Rule::in(['roadside', 'near_road', 'inside'])],
            'distance_to_road_m' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'road_condition' => ['nullable', Rule::in(['paved', 'dirt', 'poor'])],
            'is_landlocked' => ['nullable', 'boolean'],
            'water_source' => ['nullable', Rule::in(['network', 'borehole', 'well', 'none'])],
            'electricity' => ['nullable', Rule::in(['network', 'prepaid', 'solar', 'none'])],
            'security_level' => ['nullable', Rule::in(['high', 'medium', 'low'])],
            'has_parking' => ['nullable', 'boolean'],
            'has_fence' => ['nullable', 'boolean'],
            'has_guard' => ['nullable', 'boolean'],
            'land_titled' => ['nullable', 'boolean'],
            'land_title_status' => ['nullable', 'string', 'max:100'],
            'serviced' => ['nullable', 'boolean'],
            'deposit_percent' => ['nullable', 'numeric', 'min:1', 'max:100'],
            'min_lease_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'advance_months' => ['nullable', 'integer', 'min:1', 'max:36'],
            'available_from' => ['nullable', 'date'],
            'net_price' => [$type === Listing::TYPE_LAND ? 'nullable' : $req, 'integer', 'min:0'],
            'old_net_price' => ['nullable', 'integer', 'min:0'],
            'promo_text' => ['nullable', 'string', 'max:255'],
            'amenity_ids' => ['nullable', 'array'],
            'amenity_ids.*' => ['integer', 'exists:real_estate_amenities,id'],
            'units' => ['nullable', 'array', 'max:50'],
            'units.*.id' => ['nullable', 'integer'],
            'units.*.label' => ['required_with:units', 'string', 'max:100'],
            'units.*.surface_min' => ['nullable', 'integer', 'min:1'],
            'units.*.surface_max' => ['nullable', 'integer', 'min:1'],
            'units.*.net_price' => ['required_with:units', 'integer', 'min:0'],
            'units.*.old_net_price' => ['nullable', 'integer', 'min:0'],
            'units.*.capacity' => ['nullable', 'integer', 'min:1', 'max:50'],
            'units.*.kitchen' => ['nullable', Rule::in(['private', 'shared', 'none'])],
            'units.*.furnished' => ['nullable', 'boolean'],
            'units.*.serviced' => ['nullable', 'boolean'],
            'units.*.units_total' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'units.*.units_available' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'units.*.available_from' => ['nullable', 'date'],
            'rooms_tour' => ['nullable', 'array', 'max:40'],
            'rooms_tour.*.id' => ['nullable', 'integer'],
            'rooms_tour.*.kind' => ['required_with:rooms_tour', Rule::in(Room::KINDS)],
            'rooms_tour.*.label' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function save(Listing $listing, array $data): Listing
    {
        return DB::transaction(function () use ($listing, $data) {
            $fields = Arr::except($data, ['amenity_ids', 'units', 'rooms_tour']);
            $listing->fill($fields)->save();

            if (array_key_exists('amenity_ids', $data)) {
                $listing->amenities()->sync($data['amenity_ids'] ?? []);
            }
            if (array_key_exists('units', $data)) {
                $this->syncUnits($listing, $data['units'] ?? []);
            }
            if (array_key_exists('rooms_tour', $data)) {
                $this->syncRooms($listing, $data['rooms_tour'] ?? []);
            }

            return $listing->fresh(['units', 'tourRooms', 'media', 'amenities']);
        });
    }

    private function syncUnits(Listing $listing, array $units): void
    {
        $keep = [];
        foreach (array_values($units) as $position => $u) {
            $total = (int) ($u['units_total'] ?? 1);
            $attrs = Arr::only($u, ['label', 'surface_min', 'surface_max', 'net_price', 'old_net_price', 'capacity',
                'kitchen', 'furnished', 'serviced', 'available_from']) + [
                'units_total' => $total,
                'units_available' => min($total, (int) ($u['units_available'] ?? $total)),
                'position' => $position,
            ];
            $unit = ! empty($u['id']) ? $listing->units()->whereKey($u['id'])->first() : null;
            $unit ? $unit->update($attrs) : $unit = $listing->units()->create($attrs);
            $keep[] = $unit->id;
        }
        $listing->units()->whereNotIn('id', $keep)->delete();

        // Le prix « à partir de » de l'annonce suit le logement le moins cher.
        if ($keep) {
            $listing->forceFill(['net_price' => (int) $listing->units()->min('net_price')])->save();
        }
    }

    private function syncRooms(Listing $listing, array $rooms): void
    {
        $keep = [];
        foreach (array_values($rooms) as $position => $r) {
            $attrs = ['kind' => $r['kind'], 'label' => $r['label'] ?? null, 'position' => $position];
            $room = ! empty($r['id']) ? $listing->tourRooms()->whereKey($r['id'])->first() : null;
            $room ? $room->update($attrs) : $room = $listing->tourRooms()->create($attrs);
            $keep[] = $room->id;
        }
        $listing->tourRooms()->whereNotIn('id', $keep)->get()->each(function (Room $room) {
            $room->media->each(fn (Media $m) => $this->deleteMedia($m));
            $room->delete();
        });
    }

    public function addMedia(Listing $listing, UploadedFile $file, ?int $roomId = null, bool $cover = false): Media
    {
        if ($roomId && ! $listing->tourRooms()->whereKey($roomId)->exists()) {
            throw ValidationException::withMessages(['room_id' => __('real_estate.errors.unknown_room')]);
        }
        $isVideo = str_starts_with((string) $file->getMimeType(), 'video/');
        $path = $file->store("real_estate/{$listing->id}", self::DISK);

        return DB::transaction(function () use ($listing, $path, $isVideo, $roomId, $cover) {
            $hasCover = $listing->media()->where('is_cover', true)->exists();
            if ($cover) {
                $listing->media()->update(['is_cover' => false]);
            }

            return $listing->media()->create([
                'type' => $isVideo ? 'video' : 'image',
                'path' => $path,
                'room_id' => $roomId,
                'position' => (int) $listing->media()->max('position') + 1,
                'is_cover' => ! $isVideo && ($cover || ! $hasCover),
            ]);
        });
    }

    public function deleteMedia(Media $media): void
    {
        Storage::disk(self::DISK)->delete(array_filter([$media->path, $media->poster_path]));
        $wasCover = $media->is_cover;
        $listing = $media->listing;
        $media->delete();

        if ($wasCover && $listing) {
            $listing->media()->where('type', 'image')->orderBy('position')->first()?->update(['is_cover' => true]);
        }
    }

    /**
     * Une maison ne part en validation qu'avec sa visite complète : la photo
     * d'entrée, une photo par pièce déclarée et une vidéo.
     *
     * @return list<string> clés des manques, vide si l'annonce est complète
     */
    public function missingForSubmission(Listing $listing): array
    {
        $listing->load(['tourRooms.media', 'media', 'units']);
        $missing = [];

        if ($listing->media->where('type', 'image')->isEmpty()) {
            $missing[] = 'photos';
        }
        if (! $listing->isLand()) {
            $entrance = $listing->tourRooms->firstWhere('kind', 'entrance');
            if (! $entrance || $entrance->media->where('type', 'image')->isEmpty()) {
                $missing[] = 'entrance_photo';
            }
            if ($listing->tourRooms->contains(fn (Room $r) => $r->media->where('type', 'image')->isEmpty())) {
                $missing[] = 'room_photos';
            }
            if ($listing->media->where('type', 'video')->isEmpty()) {
                $missing[] = 'video';
            }
            if ($listing->minNetPrice() <= 0) {
                $missing[] = 'price';
            }
        } elseif ($listing->units->isEmpty()) {
            $missing[] = 'lots';
        }

        return $missing;
    }
}
