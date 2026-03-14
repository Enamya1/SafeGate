<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeProduct extends Model
{
    protected $fillable = [
        'product_id',
        'exchange_type',
        'target_product_category_id',
        'target_product_condition_id',
        'target_product_title',
        'exchange_status',
        'expiration_date',
    ];

    protected $casts = [
        'expiration_date' => 'datetime',
    ];
}
