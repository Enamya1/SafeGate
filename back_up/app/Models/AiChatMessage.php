<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'user_id',
        'role',
        'message_type',
        'content_type',
        'content',
        'function_name',
        'function_arguments',
        'function_response',
        'tokens',
        'tokens_used',
        'response_ms',
        'audio_duration_seconds',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'function_arguments' => 'array',
            'function_response' => 'array',
            'metadata' => 'array',
        ];
    }

    public function session()
    {
        return $this->belongsTo(AiChatSession::class, 'session_id');
    }
}
