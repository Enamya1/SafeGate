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
        if (!Schema::hasColumn('messages', 'transfer_data')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->json('transfer_data')->nullable()->after('message_type');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('messages', 'transfer_data')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->dropColumn('transfer_data');
            });
        }
    }
};
