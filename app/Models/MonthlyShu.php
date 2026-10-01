<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model MonthlyShu (Alias / Extension untuk tabel monthly_cooperative_benchmarks)
 */
class MonthlyShu extends Model
{
    use HasFactory;

    protected $table = 'monthly_cooperative_benchmarks';

    protected $fillable = [
        'fiscal_year',
        'month',
        'cycle_start_date',
        'cycle_end_date',
        'net_income',
        'dividend_allocation_percent',
        'total_coop_shares',
        'is_locked',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'fiscal_year'                 => 'integer',
        'month'                       => 'integer',
        'cycle_start_date'            => 'date:Y-m-d',
        'cycle_end_date'              => 'date:Y-m-d',
        'net_income'                  => 'decimal:2',
        'dividend_allocation_percent' => 'decimal:2',
        'total_coop_shares'           => 'decimal:2',
        'is_locked'                   => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
