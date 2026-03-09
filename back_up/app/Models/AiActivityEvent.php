<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiActivityEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'activity_type',
        'context',
        'payload',
        'event_type',
        'model_used',
        'total_tokens',
        'prompt_tokens',
        'completion_tokens',
        'cost',
        'duration_ms',
        'success',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'success' => 'boolean',
            'cost' => 'decimal:6',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
