<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('initiator_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('responder_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('offered_product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('requested_product_id')->constrained('products')->onDelete('cascade');
            $table->json('exchange_terms')->nullable();
            $table->string('status', 40)->default('pending');
            $table->json('status_timeline')->nullable();
            $table->timestamp('initiator_accepted_at')->nullable();
            $table->timestamp('responder_accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['initiator_id', 'status']);
            $table->index(['responder_id', 'status']);
            $table->index(['offered_product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_transactions');
    }
};
