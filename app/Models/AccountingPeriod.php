<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountingPeriod extends Model
{
    use HasFactory;

    protected $table = 'accounting_periods';

    protected $fillable = [
        'period_name',
        'start_date',
        'end_date',
        'status',
        'is_locked',
        'is_active',
        'closed_by',
        'closed_at',
        'notes',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'is_locked'  => 'boolean',
        'is_active'  => 'boolean',
        'closed_at'  => 'datetime',
    ];

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}