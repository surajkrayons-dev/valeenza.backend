<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreSetting extends Model
{
    use HasFactory;

    protected $table = 'store_settings';
    
    protected $fillable = [
        'delivery_charge',
        'status',
    ];
}
