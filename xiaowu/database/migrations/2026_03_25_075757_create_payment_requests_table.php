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
        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->onDelete('cascade');
            $table->foreignId('sender_id')->constrained('users')->onDelete('cascade'); // User requesting payment
            $table->foreignId('receiver_id')->constrained('users')->onDelete('cascade'); // User who needs to pay
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('CNY');
            $table->string('status')->default('pending'); // pending, paid, expired, cancelled
            $table->text('message')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete(); // The transfer message created when paid
            $table->foreignId('atomic_transaction_id')->nullable()->constrained('atomic_transactions')->nullOnDelete();
            $table->timestamps();
            
            $table->index(['conversation_id', 'status']);
            $table->index(['receiver_id', 'status']);
            $table->index(['sender_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_requests');
    }
};
