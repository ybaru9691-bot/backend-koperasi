<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanInstallment extends Model
{
    use HasFactory;

    protected $fillable = [
        'loan_id',
        'installment_number',
        'receipt_number',
        'beginning_balance',
        'principal_amount',
        'interest_amount',
        'ending_balance',
        'penalty_fee',
        'penalty_amount',
        'total_amount',
        'due_date',
        'paid_at',
        'paid_by',
        'status',
        'notes',
        // Legacy FK columns (kept for backward compat)
        'paid_by_member_id',
        'received_by_user_id',
    ];

    protected $casts = [
        'due_date'          => 'date',
        'paid_at'           => 'datetime',
        'beginning_balance' => 'float',
        'principal_amount'  => 'float',
        'interest_amount'   => 'float',
        'ending_balance'    => 'float',
        'penalty_fee'       => 'float',
        'penalty_amount'    => 'float',
        'total_amount'      => 'float',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function teller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function paidByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /**
     * Kembalikan total denda (dari kolom penalty_fee atau penalty_amount, mana saja yang ada).
     */
    public function getPenaltyAttribute(): float
    {
        return (float) ($this->attributes['penalty_fee'] ?? $this->attributes['penalty_amount'] ?? 0.0);
    }
}