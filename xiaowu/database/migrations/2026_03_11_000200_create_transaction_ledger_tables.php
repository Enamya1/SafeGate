<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_ledgers', function (Blueprint $table) {
            $table->id();
            $table->string('ledger_uuid')->unique();
            $table->unsignedBigInteger('atomic_transaction_id')->nullable();
            $table->foreignId('wallet_id')->constrained('wallets')->onDelete('cascade');
            $table->foreignId('related_wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->string('direction', 10);
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3);
            $table->string('status')->default('pending');
            $table->string('type')->nullable();
            $table->string('reference')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['wallet_id', 'status']);
            $table->index(['related_wallet_id', 'status']);
            $table->index(['occurred_at']);
        });

        Schema::create('transaction_ledger_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_ledger_id')->constrained('transaction_ledgers')->onDelete('cascade');
            $table->string('action');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['transaction_ledger_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_ledger_audits');
        Schema::dropIfExists('transaction_ledgers');
    }
};
