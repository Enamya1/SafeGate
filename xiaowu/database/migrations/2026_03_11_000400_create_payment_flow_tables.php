<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_states', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_flows', function (Blueprint $table) {
            $table->id();
            $table->string('payment_uuid')->unique();
            $table->foreignId('buyer_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('seller_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->foreignId('escrow_id')->nullable()->constrained('escrows')->nullOnDelete();
            $table->foreignId('payment_state_id')->constrained('payment_states')->onDelete('restrict');
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('CNY');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['buyer_id', 'seller_id', 'payment_state_id']);
        });

        Schema::create('payment_flow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_flow_id')->constrained('payment_flows')->onDelete('cascade');
            $table->unsignedInteger('step_order');
            $table->string('step_type');
            $table->foreignId('state_id')->constrained('payment_states')->onDelete('restrict');
            $table->string('status')->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['payment_flow_id', 'step_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_flow_steps');
        Schema::dropIfExists('payment_flows');
        Schema::dropIfExists('payment_states');
        Schema::dropIfExists('payment_methods');
    }
};
