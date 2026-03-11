<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_ledger_id')->constrained('transaction_ledgers')->onDelete('cascade');
            $table->foreignId('requested_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('approved_by_level1')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_level2')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->text('reason')->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3);
            $table->unsignedInteger('approval_required_level')->default(1);
            $table->unsignedInteger('approved_level')->default(0);
            $table->foreignId('reversal_ledger_id')->nullable()->constrained('transaction_ledgers')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'approval_required_level']);
        });

        Schema::create('fraud_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_ledger_id')->nullable()->constrained('transaction_ledgers')->nullOnDelete();
            $table->foreignId('wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->foreignId('related_wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->unsignedInteger('risk_score')->default(0);
            $table->string('risk_level')->default('low');
            $table->string('status')->default('open');
            $table->json('reasons')->nullable();
            $table->string('external_provider')->nullable();
            $table->string('external_reference')->nullable();
            $table->decimal('external_score', 8, 2)->nullable();
            $table->timestamps();
            $table->index(['status', 'risk_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_alerts');
        Schema::dropIfExists('transaction_reversals');
    }
};
