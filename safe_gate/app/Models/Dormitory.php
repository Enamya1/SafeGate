<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Dormitory extends Model
{
    protected $table = 'dormitories';

    protected $fillable = [
        'dormitory_name',
        'domain',
        'latitude',
        'longitude',
        'address',
        'full_capacity',
        'is_active',
        'university_id',
    ];

    public function university()
    {
        return $this->belongsTo(University::class);
    }
}
