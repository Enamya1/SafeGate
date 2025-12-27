<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'seller_id',
        'campus_id',
        'category_id',
        'condition_level_id',
        'title',
        'description',
        'price',
        'status',
        'deleted_at',
        'modified_by',
        'modification_reason',
    ];

    protected $casts = [
        'deleted_at' => 'datetime',
    ];
}
