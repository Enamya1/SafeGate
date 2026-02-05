<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('conversations')) {
            return;
        }

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE conversations MODIFY product_id BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('conversations')) {
            return;
        }

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE conversations MODIFY product_id BIGINT UNSIGNED NOT NULL');
        }
    }
};
