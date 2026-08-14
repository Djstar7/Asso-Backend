<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchSynonym extends Model
{
    protected $fillable = [
        'term',
        'synonym',
        'weight',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'weight' => 'integer',
    ];

    /**
     * Get all active synonyms for a given term
     */
    public static function getSynonymsFor(string $term): array
    {
        return self::where('term', strtolower($term))
            ->where('is_active', true)
            ->orderBy('weight', 'desc')
            ->pluck('synonym')
            ->toArray();
    }

    /**
     * Get all related terms (both synonyms and reverse lookups)
     */
    public static function getRelatedTerms(string $term): array
    {
        $normalizedTerm = strtolower($term);
        $relatedTerms = [$normalizedTerm];

        // Get direct synonyms
        $synonyms = self::where('term', $normalizedTerm)
            ->where('is_active', true)
            ->pluck('synonym')
            ->toArray();

        // Get reverse synonyms (terms that have this as a synonym)
        $reverseTerms = self::where('synonym', $normalizedTerm)
            ->where('is_active', true)
            ->pluck('term')
            ->toArray();

        $relatedTerms = array_merge($relatedTerms, $synonyms, $reverseTerms);

        return array_unique($relatedTerms);
    }

    /**
     * Expand a search query with all related terms
     */
    public static function expandSearchQuery(string $query): array
    {
        $words = preg_split('/\s+/', strtolower(trim($query)));
        $expandedTerms = [];

        foreach ($words as $word) {
            $relatedTerms = self::getRelatedTerms($word);
            $expandedTerms = array_merge($expandedTerms, $relatedTerms);
        }

        return array_unique($expandedTerms);
    }
}
