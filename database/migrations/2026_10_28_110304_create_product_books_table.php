<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('author');
            $table->string('isbn', 20)->nullable()->index();
            $table->string('publisher')->nullable();
            $table->string('language', 5)->default('fr');
            $table->unsignedSmallInteger('pages')->nullable();
            $table->string('format', 20)->nullable(); // paperback, hardcover, ebook, audio
            $table->unsignedSmallInteger('published_year')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_books');
    }
};
