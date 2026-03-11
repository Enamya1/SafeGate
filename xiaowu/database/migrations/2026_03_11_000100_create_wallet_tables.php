<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('wallet_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('wallet_type_id')->constrained('wallet_types')->onDelete('restrict');
            $table->foreignId('status_id')->constrained('wallet_statuses')->onDelete('restrict');
            $table->string('currency', 3)->default('CNY');
            $table->decimal('balance', 18, 2)->default(0);
            $table->decimal('available_balance', 18, 2)->default(0);
            $table->decimal('locked_balance', 18, 2)->default(0);
            $table->timestamp('frozen_at')->nullable();
            $table->text('freeze_reason')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'wallet_type_id', 'currency']);
            $table->index(['user_id', 'status_id']);
        });

        Schema::create('wallet_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->onDelete('cascade');
            $table->foreignId('from_status_id')->nullable()->constrained('wallet_statuses')->nullOnDelete();
            $table->foreignId('to_status_id')->constrained('wallet_statuses')->onDelete('restrict');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['wallet_id', 'created_at']);
        });

        Schema::create('wallet_freeze_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->onDelete('cascade');
            $table->foreignId('requested_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action')->default('freeze');
            $table->string('status')->default('pending');
            $table->text('reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['wallet_id', 'status']);
            $table->index(['wallet_id', 'action']);
        });

        DB::table('wallet_types')->insert([
            [
                'code' => 'primary',
                'name' => 'Primary',
                'description' => 'Default user wallet',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'escrow',
                'name' => 'Escrow',
                'description' => 'Escrow holding wallet',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('wallet_statuses')->insert([
            [
                'code' => 'active',
                'name' => 'Active',
                'description' => 'Wallet is active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'frozen',
                'name' => 'Frozen',
                'description' => 'Wallet is frozen',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'closed',
                'name' => 'Closed',
                'description' => 'Wallet is closed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_freeze_requests');
        Schema::dropIfExists('wallet_status_histories');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('wallet_statuses');
        Schema::dropIfExists('wallet_types');
    }
};
