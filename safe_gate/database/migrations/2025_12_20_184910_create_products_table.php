<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('campus_id')->constrained('campus')->onDelete('cascade');
            $table->foreignId('category_id')->constrained('categories')->onDelete('cascade');
            $table->foreignId('condition_level_id')->constrained('condition_levels')->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('price', 8, 2)->unsigned()->check('price > 0');
            $table->string('status')->default('available');
            $table->timestamp('deleted_at')->nullable();
            $table->foreignId('modified_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('modification_reason')->nullable();
            $table->timestamps();
            $table->index(['campus_id', 'status', 'created_at']);
            $table->index(['category_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
