<?php

namespace Tests\Feature;

use App\Models\Product;
use Tests\TestCase;

class ProductSizeCategoryTest extends TestCase
{
    public function test_allowed_size_groups_follow_category_rules(): void
    {
        $this->assertSame([], Product::getAllowedSizeGroupsForCategory('Électronique'));
        $this->assertSame([], Product::getAllowedSizeGroupsForCategory('Livres & Médias'));
        $this->assertSame([], Product::getAllowedSizeGroupsForCategory('Beauté & Santé'));
        $this->assertSame([], Product::getAllowedSizeGroupsForCategory('Alimentation & Boissons'));
        $this->assertSame([], Product::getAllowedSizeGroupsForCategory('Automobile & Moto'));
        $this->assertSame([], Product::getAllowedSizeGroupsForCategory('Animalerie'));
        $this->assertSame([], Product::getAllowedSizeGroupsForCategory('Bureautique & Fournitures'));
        $this->assertSame([], Product::getAllowedSizeGroupsForCategory('Art & Loisirs créatifs'));
        $this->assertSame([], Product::getAllowedSizeGroupsForCategory('Électroménager'));
        $this->assertSame([], Product::getAllowedSizeGroupsForCategory('Accessoires'));

        $this->assertSame(['clothing', 'numeric'], Product::getAllowedSizeGroupsForCategory('Mode & Vêtements', 'Vêtements Hommes'));
        $this->assertSame(['shoes'], Product::getAllowedSizeGroupsForCategory('Mode & Vêtements', 'Chaussures'));
        $this->assertSame(['baby'], Product::getAllowedSizeGroupsForCategory('Bébé & Puériculture', 'Vêtements bébé'));
        $this->assertSame(['dimensions'], Product::getAllowedSizeGroupsForCategory('Maison & Jardin', 'Meubles'));
    }
}
