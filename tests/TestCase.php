<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Symfony annonce « en-us » par défaut ; l'application, elle,
        // envoie toujours sa langue. Les tests parlent français, comme
        // l'application par défaut, sauf s'ils en choisissent une autre.
        $this->withHeader('Accept-Language', 'fr');
    }

    /**
     * Photo factice pour créer un produit au back-office, où au moins une est exigée.
     * Les fichiers écrits dans public/storage/products pendant le test sont effacés.
     */
    protected function productPhoto(): array
    {
        $dir = public_path('storage/products');
        $before = glob($dir.'/*') ?: [];
        $this->beforeApplicationDestroyed(function () use ($dir, $before) {
            foreach (array_diff(glob($dir.'/*') ?: [], $before) as $file) {
                @unlink($file);
            }
        });

        return ['images' => [UploadedFile::fake()->image('photo.jpg', 20, 20)]];
    }

    /** Photo déjà enregistrée : un produit modifié au back-office doit en garder une. */
    protected function withProductPhoto(\App\Models\Product $product): \App\Models\Product
    {
        $product->images()->create(['image_path' => 'storage/products/test.jpg', 'is_primary' => true, 'order' => 1]);

        return $product;
    }
}
