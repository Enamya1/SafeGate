<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atomic_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('atomic_uuid')->unique();
            $table->string('status')->default('pending');
            $table->decimal('total_amount', 18, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('atomic_transaction_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('atomic_transaction_id')->constrained('atomic_transactions')->onDelete('cascade');
            $table->unsignedInteger('step_order');
            $table->string('step_type');
            $table->string('status')->default('pending');
            $table->foreignId('from_wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->foreignId('to_wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3);
            $table->foreignId('transaction_ledger_id')->nullable()->constrained('transaction_ledgers')->nullOnDelete();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->unique(['atomic_transaction_id', 'step_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atomic_transaction_steps');
        Schema::dropIfExists('atomic_transactions');
    }
};
