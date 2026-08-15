<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Subcategory;
use Gemini\Laravel\Facades\Gemini;
use Gemini\Data\Blob;
use Gemini\Enums\MimeType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GeminiService
{
    /**
     * Analyze product image using Gemini Vision AI
     *
     * @param string $imagePath Path to the uploaded image
     * @return array Analyzed product data with suggestions
     */
    public function analyzeProductImage(string $imagePath): array
    {
        try {
            // Read image file and encode to base64
            $imageData = base64_encode(file_get_contents($imagePath));
            $mimeType = mime_content_type($imagePath);

            // Map PHP mime type to Gemini MimeType enum
            $geminiMimeType = $this->mapMimeType($mimeType);

            // Build the analysis prompt
            $prompt = $this->buildAnalysisPrompt();

            // Call Gemini Vision API with the correct method
            // Using gemini-1.5-flash which has better free tier quotas
            $response = Gemini::generativeModel(model: 'gemini-1.5-flash')
                ->generateContent([
                    $prompt,
                    new Blob(
                        mimeType: $geminiMimeType,
                        data: $imageData
                    ),
                ]);

            // Parse the response
            $analysisText = $response->text();
            Log::info('Gemini Analysis Response:', ['response' => $analysisText]);

            // Parse JSON response from Gemini
            $parsedData = $this->parseGeminiResponse($analysisText);

            // Map categories from Gemini suggestions to database IDs
            $categoryMapping = $this->mapCategories($parsedData);

            // Build final response
            return [
                'success' => true,
                'suggested_data' => [
                    'name' => $parsedData['name'] ?? null,
                    'description' => $parsedData['description'] ?? null,
                    'condition' => $parsedData['condition'] ?? 'used',
                    'type' => $parsedData['type'] ?? 'article',
                    'weight_category' => $parsedData['weight_category'] ?? 'X-small',
                    'category_id' => $categoryMapping['category_id'] ?? null,
                    'subcategory_id' => $categoryMapping['subcategory_id'] ?? null,
                    'suggested_category_name' => $parsedData['category'] ?? null,
                    'suggested_subcategory_name' => $parsedData['subcategory'] ?? null,
                ],
                'confidence' => [
                    'name' => $parsedData['confidence']['name'] ?? 0.5,
                    'description' => $parsedData['confidence']['description'] ?? 0.5,
                    'condition' => $parsedData['confidence']['condition'] ?? 0.5,
                    'category' => $parsedData['confidence']['category'] ?? 0.5,
                ],
                'raw_analysis' => $analysisText,
            ];

        } catch (\Exception $e) {
            Log::error('Gemini Analysis Error:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to analyze image: ' . $e->getMessage(),
                'suggested_data' => null,
            ];
        }
    }

    /**
     * Build comprehensive prompt for Gemini Vision AI
     *
     * @return string
     */
    private function buildAnalysisPrompt(): string
    {
        return <<<PROMPT
You are a product analysis AI assistant for an e-commerce marketplace in Africa (Benin/Gabon region).
Analyze this product image carefully and extract the following information in JSON format.

**IMPORTANT**: Your response MUST be ONLY valid JSON, no markdown, no code blocks, no explanation text.

Analyze and provide:
1. **name**: Product name (concise, max 100 characters) - in French if possible
2. **description**: Detailed product description (200-500 characters) explaining what it is, key features, visible details - in French
3. **condition**: Product condition based on visual appearance
   - "new": Brand new, pristine condition, possibly in packaging
   - "used": Shows signs of use but functional
   - "refurbished": Appears restored or professionally cleaned
4. **type**: Product classification
   - "article": Physical product (clothing, electronics, furniture, etc.)
   - "service": Service-based offering (unlikely from image alone)
5. **category**: Main product category (in French). Examples: "Électronique", "Mode et Vêtements", "Maison et Jardin", "Livres et Médias", "Sports et Loisirs", "Santé et Beauté", "Alimentation", "Véhicules", "Immobilier", "Animaux"
6. **subcategory**: Specific subcategory (in French). Examples: "Téléphones", "Ordinateurs", "Chaussures Homme", "Meubles Salon"
7. **weight_category**: Estimated shipping weight category based on visible size
   - "X-small": Very small items (jewelry, accessories, small electronics)
   - "30 Deep": Small packages
   - "50 Deep": Medium packages
   - "60 Deep": Large packages
   - "Rainbow XL": Extra large items
   - "Pallet": Very large/heavy items (furniture, appliances)
8. **confidence**: Confidence scores (0.0 to 1.0) for each field
   - name: How confident are you about the product name?
   - description: How confident about the description accuracy?
   - condition: How confident about the condition assessment?
   - category: How confident about category/subcategory match?

**Response Format (JSON only, no markdown):**
{
  "name": "string",
  "description": "string",
  "condition": "new|used|refurbished",
  "type": "article|service",
  "category": "string",
  "subcategory": "string",
  "weight_category": "X-small|30 Deep|50 Deep|60 Deep|Rainbow XL|Pallet",
  "confidence": {
    "name": 0.0-1.0,
    "description": 0.0-1.0,
    "condition": 0.0-1.0,
    "category": 0.0-1.0
  }
}

Remember: ONLY return valid JSON, nothing else.
PROMPT;
    }

    /**
     * Parse Gemini's text response into structured data
     *
     * @param string $text
     * @return array
     */
    private function parseGeminiResponse(string $text): array
    {
        // Clean up the response (remove markdown code blocks if present)
        $text = trim($text);
        $text = preg_replace('/```json\s*/', '', $text);
        $text = preg_replace('/```\s*/', '', $text);
        $text = trim($text);

        // Try to decode JSON
        $decoded = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('Failed to parse Gemini JSON response', [
                'error' => json_last_error_msg(),
                'raw_text' => $text,
            ]);

            // Return default structure
            return [
                'name' => null,
                'description' => null,
                'condition' => 'used',
                'type' => 'article',
                'category' => null,
                'subcategory' => null,
                'weight_category' => 'X-small',
                'confidence' => [
                    'name' => 0.0,
                    'description' => 0.0,
                    'condition' => 0.0,
                    'category' => 0.0,
                ],
            ];
        }

        return $decoded;
    }

    /**
     * Map Gemini's category suggestions to database category/subcategory IDs
     *
     * @param array $parsedData
     * @return array ['category_id' => int|null, 'subcategory_id' => int|null]
     */
    private function mapCategories(array $parsedData): array
    {
        $result = [
            'category_id' => null,
            'subcategory_id' => null,
        ];

        if (empty($parsedData['category'])) {
            return $result;
        }

        $suggestedCategory = $parsedData['category'];
        $suggestedSubcategory = $parsedData['subcategory'] ?? null;

        // Try to find matching category (case-insensitive, partial match)
        $category = Category::query()
            ->where(function ($query) use ($suggestedCategory) {
                $query->where('name', 'ILIKE', "%{$suggestedCategory}%")
                    ->orWhere('name_en', 'ILIKE', "%{$suggestedCategory}%");
            })
            ->first();

        if ($category) {
            $result['category_id'] = $category->id;

            // Try to find matching subcategory
            if ($suggestedSubcategory) {
                $subcategory = Subcategory::query()
                    ->where('category_id', $category->id)
                    ->where(function ($query) use ($suggestedSubcategory) {
                        $query->where('name', 'ILIKE', "%{$suggestedSubcategory}%")
                            ->orWhere('name_en', 'ILIKE', "%{$suggestedSubcategory}%");
                    })
                    ->first();

                if ($subcategory) {
                    $result['subcategory_id'] = $subcategory->id;
                }
            }
        }

        return $result;
    }

    /**
     * Map PHP mime type to Gemini MimeType enum
     *
     * @param string $mimeType
     * @return MimeType
     */
    private function mapMimeType(string $mimeType): MimeType
    {
        return match ($mimeType) {
            'image/jpeg', 'image/jpg' => MimeType::IMAGE_JPEG,
            'image/png' => MimeType::IMAGE_PNG,
            'image/webp' => MimeType::IMAGE_WEBP,
            'image/heic' => MimeType::IMAGE_HEIC,
            'image/heif' => MimeType::IMAGE_HEIF,
            'image/gif' => MimeType::IMAGE_JPEG, // Fallback to JPEG for GIF
            default => MimeType::IMAGE_JPEG, // Default fallback
        };
    }

    /**
     * Get all available categories for reference
     *
     * @return array
     */
    public function getAvailableCategories(): array
    {
        return Category::with('subcategories')
            ->get()
            ->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'name_en' => $category->name_en,
                    'subcategories' => $category->subcategories->map(function ($sub) {
                        return [
                            'id' => $sub->id,
                            'name' => $sub->name,
                            'name_en' => $sub->name_en,
                        ];
                    }),
                ];
            })
            ->toArray();
    }
}
