<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotedListing extends Model
{
    protected $fillable = [
        'product_id',
        'promoted_until',
    ];

    protected $casts = [
        'promoted_until' => 'datetime',
    ];
}
