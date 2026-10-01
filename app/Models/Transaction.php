<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory;

    protected $table = 'transactions';

    protected $guarded = [];

    protected $appends = [
        'formatted_receipt_no',
    ];

    protected $casts = [
        'amount'            => 'decimal:2',
        'denda'             => 'decimal:2',
        'beginning_balance' => 'decimal:2',
        'ending_balance'    => 'decimal:2',
        'transaction_date'  => 'date:Y-m-d',
        'approved_at'        => 'datetime',
    ];

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return \Carbon\Carbon::instance($date)->setTimezone('Asia/Jakarta')->format('Y-m-d');
    }

    public function getFormattedReceiptNoAttribute()
    {
        $raw = trim($this->receipt_number ?: $this->transaction_number ?: '');
        if (empty($raw)) {
            $raw = (string) $this->id;
        }

        $typeIn = in_array(strtolower($this->type ?? ''), ['deposit', 'in', 'kas_masuk', 'km']);
        $prefix = $typeIn ? 'KM' : 'KK';

        // Strip repeating KK- / KM- / KK / KM / TRX-
        $cleaned = preg_replace('/^(TRX-|TRX\s+)/i', '', $raw);
        while (preg_match('/^(KM|KK)[-\s_]+(KM|KK)[-\s_]*/i', $cleaned)) {
            $cleaned = preg_replace('/^(KM|KK)[-\s_]+/i', '', $cleaned);
        }

        // If it already starts with KK or KM followed by separator or end of string
        if (preg_match('/^(KM|KK)([-\s_].*|$)/i', $cleaned)) {
            return $cleaned;
        }

        return "{$prefix}-{$cleaned}";
    }

    public function setTransactionNumberAttribute($value): void
    {
        if (is_string($value)) {
            $cleaned = trim($value);
            while (preg_match('/^(KM|KK)[-\s_]+(KM|KK)[-\s_]*/i', $cleaned)) {
                $cleaned = preg_replace('/^(KM|KK)[-\s_]+/i', '', $cleaned);
            }
            $this->attributes['transaction_number'] = $cleaned;
        } else {
            $this->attributes['transaction_number'] = $value;
        }
    }

    public function setReceiptNumberAttribute($value): void
    {
        if (is_string($value)) {
            $cleaned = trim($value);
            while (preg_match('/^(KM|KK)[-\s_]+(KM|KK)[-\s_]*/i', $cleaned)) {
                $cleaned = preg_replace('/^(KM|KK)[-\s_]+/i', '', $cleaned);
            }
            $this->attributes['receipt_number'] = $cleaned;
        } else {
            $this->attributes['receipt_number'] = $value;
        }
    }

    public function getVoucherNoAttribute(): ?string
    {
        return $this->receipt_number ?? $this->transaction_number;
    }

    public function setVoucherNoAttribute($value): void
    {
        $this->setTransactionNumberAttribute($value);
        $this->setReceiptNumberAttribute($value);
    }

    public function getDateAttribute()
    {
        return $this->transaction_date;
    }

    public function setDateAttribute($value): void
    {
        $this->attributes['transaction_date'] = $value;
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function journalEntry()
    {
        return $this->hasOne(JournalEntry::class, 'transaction_id');
    }

    public static bool $bypassPeriodLock = false;

    public static function withoutPeriodLock(callable $callback)
    {
        $prev = static::$bypassPeriodLock;
        static::$bypassPeriodLock = true;
        try {
            return $callback();
        } finally {
            static::$bypassPeriodLock = $prev;
        }
    }

    protected static function booted(): void
    {
        static::creating(function (Transaction $transaction) {
            if (!static::$bypassPeriodLock && \App\Services\PeriodClosingService::isDateLocked($transaction->transaction_date)) {
                abort(response()->json([
                    'status'  => 'error',
                    'success' => false,
                    'message' => \App\Services\PeriodClosingService::LOCKED_MESSAGE,
                    'error'   => 'LOCKED_PERIOD',
                ], 403));
            }
        });

        static::updating(function (Transaction $transaction) {
            if (!static::$bypassPeriodLock) {
                $oldDate = $transaction->getOriginal('transaction_date');
                $newDate = $transaction->transaction_date;
                if (\App\Services\PeriodClosingService::isDateLocked($oldDate) || \App\Services\PeriodClosingService::isDateLocked($newDate)) {
                    abort(response()->json([
                        'status'  => 'error',
                        'success' => false,
                        'message' => \App\Services\PeriodClosingService::LOCKED_MESSAGE,
                        'error'   => 'LOCKED_PERIOD',
                    ], 403));
                }
            }
        });

        static::deleting(function (Transaction $transaction) {
            if (!static::$bypassPeriodLock) {
                $date = $transaction->transaction_date ?? $transaction->getOriginal('transaction_date');
                if (\App\Services\PeriodClosingService::isDateLocked($date)) {
                    abort(response()->json([
                        'status'  => 'error',
                        'success' => false,
                        'message' => \App\Services\PeriodClosingService::LOCKED_MESSAGE,
                        'error'   => 'LOCKED_PERIOD',
                    ], 403));
                }
            }
        });
    }
}