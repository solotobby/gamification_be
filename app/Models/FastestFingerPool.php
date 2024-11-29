<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FastestFingerPool extends Model
{
    use HasFactory;
    protected $table = 'fastest_finger_pools';
    protected $fillable = [
        'user_id',
        'date',
        'is_selected'
    ];

    public function fastestfinger()
    {
        return $this->belongsTo(FastestFinger::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
