<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    use HasFactory;

    protected $table = 'journal_entries';

    protected $fillable = [
        'transaction_id',
        'entry_date',
        'voucher_number',
        'description',
        'created_by',
    ];

    protected $casts = [
        'entry_date'  => 'date',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    public function setVoucherNumberAttribute($value): void
    {
        if (is_string($value)) {
            $cleaned = trim($value);
            while (preg_match('/^(KM|KK)[-\s_]+(KM|KK)[-\s_]*/i', $cleaned)) {
                $cleaned = preg_replace('/^(KM|KK)[-\s_]+/i', '', $cleaned);
            }
            $this->attributes['voucher_number'] = $cleaned;
        } else {
            $this->attributes['voucher_number'] = $value;
        }
    }

    /**
     * Relasi ke Transaction (opsional, bisa null untuk jurnal manual)
     */
    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    /**
     * Detail baris jurnal (debit & kredit)
     */
    public function details()
    {
        return $this->hasMany(JournalDetail::class, 'journal_entry_id');
    }

    /**
     * User pembuat
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Accessor: total debit
     */
    public function getTotalDebitAttribute(): float
    {
        return (float) $this->details()->sum('debit');
    }

    /**
     * Accessor: total kredit
     */
    public function getTotalCreditAttribute(): float
    {
        return (float) $this->details()->sum('credit');
    }

    /**
     * Accessor: cek balance (debit == kredit)
     */
    public function getIsBalancedAttribute(): bool
    {
        return abs($this->total_debit - $this->total_credit) < 0.01;
    }
}
