<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductTax extends Model
{
    protected $fillable = [
        'state_code',
        'state_name',
        'state_rate',
        'local_rate',
        'combined_rate',
        'status',
    ];

    protected $casts = [
        'state_rate' => 'decimal:2',
        'local_rate' => 'decimal:2',
        'combined_rate' => 'decimal:2',
        'status' => 'boolean',
    ];
}