<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // MySQL supports CHECK constraints from version 8.0.16
        // Add check constraint for language column
        DB::statement("ALTER TABLE users ADD CONSTRAINT chk_users_language CHECK (language IN ('en', 'zh', 'ar', 'fr') OR language IS NULL)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the check constraint
        // Note: MySQL requires knowing the constraint name
        DB::statement("ALTER TABLE users DROP CONSTRAINT chk_users_language");
    }
};
