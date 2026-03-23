<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->index(['conversation_id', 'id'], 'messages_conversation_id_id_index');
            $table->index(['sender_id', 'conversation_id', 'id'], 'messages_sender_conversation_id_index');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->index(['buyer_id', 'seller_id', 'product_id'], 'conversations_buyer_seller_product_index');
            $table->index(['product_id', 'buyer_id', 'seller_id'], 'conversations_product_buyer_seller_index');
        });

        Schema::table('exchange_products', function (Blueprint $table) {
            $table->index(['exchange_status', 'created_at'], 'exchange_products_status_created_at_index');
            $table->index(['product_id', 'exchange_status'], 'exchange_products_product_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_products', function (Blueprint $table) {
            $table->dropIndex('exchange_products_product_status_index');
            $table->dropIndex('exchange_products_status_created_at_index');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_product_buyer_seller_index');
            $table->dropIndex('conversations_buyer_seller_product_index');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_sender_conversation_id_index');
            $table->dropIndex('messages_conversation_id_id_index');
        });
    }
};
