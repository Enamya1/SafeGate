<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImageEmbedding extends Model
{
    protected $fillable = [
        'product_id',
        'product_image_id',
        'model_name',
        'embedding_dim',
        'embedding_vector',
        'image_fingerprint',
        'indexed_at',
    ];

    protected $casts = [
        'indexed_at' => 'datetime',
    ];
}
