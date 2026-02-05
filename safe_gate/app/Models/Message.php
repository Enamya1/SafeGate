<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [
        'conversation_id',
        'sender_id',
        'message_text',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];
}
