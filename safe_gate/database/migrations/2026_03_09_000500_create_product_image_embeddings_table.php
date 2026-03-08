<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_image_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('product_image_id')->constrained('product_images')->onDelete('cascade');
            $table->string('model_name', 191);
            $table->unsignedSmallInteger('embedding_dim');
            $table->longText('embedding_vector');
            $table->string('image_fingerprint', 128)->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();

            $table->unique('product_image_id');
            $table->index(['product_id', 'indexed_at']);
            $table->index(['model_name', 'indexed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_image_embeddings');
    }
};
