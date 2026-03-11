<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escrows', function (Blueprint $table) {
            $table->id();
            $table->string('escrow_uuid')->unique();
            $table->foreignId('buyer_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('seller_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('CNY');
            $table->string('status')->default('holding');
            $table->json('release_conditions')->nullable();
            $table->timestamp('release_at')->nullable();
            $table->timestamps();
            $table->index(['buyer_id', 'seller_id', 'status']);
        });

        Schema::create('escrow_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escrow_id')->constrained('escrows')->onDelete('cascade');
            $table->foreignId('opened_by')->constrained('users')->onDelete('cascade');
            $table->string('status')->default('open');
            $table->text('reason');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['escrow_id', 'status']);
        });

        Schema::create('escrow_release_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escrow_id')->constrained('escrows')->onDelete('cascade');
            $table->string('condition_type');
            $table->json('condition_payload')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('satisfied_at')->nullable();
            $table->timestamps();
            $table->index(['escrow_id', 'status']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('escrow_release_conditions');
        Schema::dropIfExists('escrow_disputes');
        Schema::dropIfExists('escrows');
    }
};
