<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ip extends Model
{
    use HasFactory;

    protected $table = 'ips';

    protected $fillable = [
        'ip',
        'title',
        'description',
        'status',
    ];

    protected $casts = [
        'status' => 'integer',
    ];
}
