<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('universities') || ! Schema::hasColumn('universities', 'pic')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE universities MODIFY pic TEXT NULL');
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE universities ALTER COLUMN pic TYPE TEXT');
            return;
        }

        if ($driver === 'sqlsrv') {
            DB::statement('ALTER TABLE universities ALTER COLUMN pic NVARCHAR(MAX) NULL');
            return;
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('universities') || ! Schema::hasColumn('universities', 'pic')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE universities MODIFY pic VARCHAR(255) NULL');
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE universities ALTER COLUMN pic TYPE VARCHAR(255)');
            return;
        }

        if ($driver === 'sqlsrv') {
            DB::statement('ALTER TABLE universities ALTER COLUMN pic NVARCHAR(255) NULL');
            return;
        }
    }
};
