<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'product_id',
        'buyer_id',
        'seller_id',
        'amount',
        'currency',
        'payment_method',
        'transaction_status',
        'transaction_id',
    ];
}
