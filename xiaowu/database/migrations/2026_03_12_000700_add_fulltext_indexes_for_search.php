<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->fullText(['title', 'description'], 'products_fulltext');
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->fullText(['name'], 'tags_fulltext');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->fullText(['name', 'description'], 'categories_fulltext');
        });

        Schema::table('condition_levels', function (Blueprint $table) {
            $table->fullText(['name', 'description'], 'condition_levels_fulltext');
        });

        Schema::table('dormitories', function (Blueprint $table) {
            $table->fullText(['dormitory_name', 'domain', 'address'], 'dormitories_fulltext');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->fullText(['username', 'full_name'], 'users_fulltext');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropFullText('products_fulltext');
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->dropFullText('tags_fulltext');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropFullText('categories_fulltext');
        });

        Schema::table('condition_levels', function (Blueprint $table) {
            $table->dropFullText('condition_levels_fulltext');
        });

        Schema::table('dormitories', function (Blueprint $table) {
            $table->dropFullText('dormitories_fulltext');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropFullText('users_fulltext');
        });
    }
};
