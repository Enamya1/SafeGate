<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeMessage extends Model
{
    protected $fillable = [
        'exchange_id',
        'sender_id',
        'message_type',
        'message_text',
        'negotiation_details',
    ];

    protected $casts = [
        'negotiation_details' => 'array',
    ];
}
