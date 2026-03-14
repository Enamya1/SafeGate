<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exchange_id')->constrained('exchange_transactions')->onDelete('cascade');
            $table->foreignId('sender_id')->constrained('users')->onDelete('cascade');
            $table->string('message_type', 40)->default('text');
            $table->text('message_text')->nullable();
            $table->json('negotiation_details')->nullable();
            $table->timestamps();
            $table->index(['exchange_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_messages');
    }
};
