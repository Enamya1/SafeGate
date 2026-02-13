<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('universities', function (Blueprint $table) {
            $table->text('address')->nullable();
        });

        Schema::table('dormitories', function (Blueprint $table) {
            $table->text('address')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('universities', function (Blueprint $table) {
            $table->dropColumn('address');
        });

        Schema::table('dormitories', function (Blueprint $table) {
            $table->dropColumn('address');
        });
    }
};
