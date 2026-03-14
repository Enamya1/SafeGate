<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeTransaction extends Model
{
    protected $fillable = [
        'initiator_id',
        'responder_id',
        'offered_product_id',
        'requested_product_id',
        'exchange_terms',
        'status',
        'status_timeline',
        'initiator_accepted_at',
        'responder_accepted_at',
        'completed_at',
    ];

    protected $casts = [
        'exchange_terms' => 'array',
        'status_timeline' => 'array',
        'initiator_accepted_at' => 'datetime',
        'responder_accepted_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
