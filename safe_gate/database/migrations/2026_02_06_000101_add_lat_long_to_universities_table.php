<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('universities', function (Blueprint $table) {
            $table->double('latitude', 10, 7)->nullable()->after('domain');
            $table->double('longitude', 10, 7)->nullable()->after('latitude');
            $table->dropColumn('location');
        });
    }

    public function down(): void
    {
        Schema::table('universities', function (Blueprint $table) {
            $table->string('location')->nullable()->after('domain');
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
