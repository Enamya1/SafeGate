<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeetupLocation extends Model
{
    protected $fillable = [
        'dormitory_id',
        'name',
        'description',
        'is_active',
    ];

    public function dormitory()
    {
        return $this->belongsTo(Dormitory::class);
    }
}
