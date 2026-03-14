<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->string('exchange_type', 50)->default('exchange_only');
            $table->foreignId('target_product_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('target_product_condition_id')->nullable()->constrained('condition_levels')->nullOnDelete();
            $table->string('target_product_title')->nullable();
            $table->string('exchange_status', 40)->default('open');
            $table->timestamp('expiration_date')->nullable();
            $table->timestamps();
            $table->unique('product_id');
            $table->index(['exchange_status', 'expiration_date']);
            $table->index(['exchange_type', 'exchange_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_products');
    }
};
