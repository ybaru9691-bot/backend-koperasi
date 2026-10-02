<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loan extends Model
{
    use HasFactory;

    protected $fillable = [
        'loan_code',
        'member_id',
        'amount',
        'interest_rate',
        'duration_months',
        'tenor_months',
        'interest_method',
        'monthly_installment',
        'remaining_amount',
        'remaining_principal',
        'status',
        'approved_by',
        'approved_at',
        'manager_approved_by',
        'manager_approved_at',
        'admin_verified_by',
        'admin_verified_at',
        'disbursed_by',
        'disbursed_at',
        'application_date',
        'disbursement_date',
        'due_date',
        'notes',
        'purpose',
        'collateral',
    ];

    protected $casts = [
        'approved_at'         => 'datetime',
        'manager_approved_at' => 'datetime',
        'admin_verified_at'   => 'datetime',
        'disbursed_at'        => 'datetime',
        'amount'              => 'float',
        'interest_rate'       => 'float',
        'monthly_installment' => 'float',
        'remaining_amount'    => 'float',
        'remaining_principal' => 'float',
    ];

    // ── Relasi ────────────────────────────────────────────────

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('installment_number');
    }

    // ── Accessor ───────────────────────────────────────────────

    /**
     * Tenor bulan: cek kolom tenor_months terlebih dahulu, fallback ke duration_months.
     */
    public function getTenorAttribute(): int
    {
        return (int) ($this->attributes['tenor_months'] ?? $this->attributes['duration_months'] ?? 12);
    }

    /**
     * Total Pokok Terbayar dari seluruh angsuran berstatus paid.
     */
    public function getTotalPrincipalPaidAttribute(): float
    {
        if ($this->relationLoaded('installments')) {
            return (float) $this->installments->where('status', 'paid')->sum('principal_amount');
        }
        return (float) $this->installments()->where('status', 'paid')->sum('principal_amount');
    }

    /**
     * Total Jasa / Bunga Terbayar dari seluruh angsuran berstatus paid.
     */
    public function getTotalInterestPaidAttribute(): float
    {
        if ($this->relationLoaded('installments')) {
            return (float) $this->installments->where('status', 'paid')->sum('interest_amount');
        }
        return (float) $this->installments()->where('status', 'paid')->sum('interest_amount');
    }

    /**
     * Total Denda Terbayar dari seluruh angsuran berstatus paid.
     */
    public function getTotalPenaltyPaidAttribute(): float
    {
        if ($this->relationLoaded('installments')) {
            return (float) $this->installments->where('status', 'paid')->sum(function ($inst) {
                return (float) ($inst->penalty_fee ?? $inst->penalty_amount ?? 0);
            });
        }
        return (float) $this->installments()->where('status', 'paid')->sum(\Illuminate\Support\Facades\DB::raw('COALESCE(penalty_fee, penalty_amount, 0)'));
    }

    /**
     * Urutan Angsuran Aktif yang sedang berjalan (count of paid installments + 1).
     */
    public function getActiveInstallmentNumberAttribute(): int
    {
        $paidCount = $this->relationLoaded('installments')
            ? $this->installments->where('status', 'paid')->count()
            : $this->installments()->where('status', 'paid')->count();

        $tenor = $this->tenor;
        return min($tenor, $paidCount + 1);
    }

    // ── Helper Kalkulasi Jadwal Angsuran (Saldo Menurun & Flat) ──

    /**
     * Generate jadwal angsuran (Declining Balance atau Flat) sesuai Kartu Kuning CUM PELITA.
     *
     * Rumus:
     *   - Angsuran Pokok = ceil(Plafon / Tenor) tetap per bulan
     *   - Jasa Bulan ke-k:
     *       * Flat: rate% × Plafon Awal (konstan)
     *       * Declining Balance: rate% × Sisa Saldo Pokok Awal Bulan ke-k
     *   - Total Bayar Bulan ke-k = Angsuran Pokok + Jasa
     *   - Bulan terakhir: angsuran pokok = sisa pokok aktual (untuk menghindari sisa sen)
     *
     * @param  \Carbon\Carbon|null  $disbursementDate  Tanggal pencairan (default: hari ini)
     * @return array<int, array>
     */
    public function generateInstallmentSchedule(?\Carbon\Carbon $disbursementDate = null): array
    {
        $tenor          = $this->tenor;
        $method         = $this->attributes['interest_method'] ?? 'declining_balance';
        $defaultRate    = ($method === 'flat') ? 1.00 : 2.50;
        $rate           = (float) ($this->attributes['interest_rate'] ?? $defaultRate);
        $plafon         = (float) $this->attributes['amount'];
        $principalChunk = (float) ceil($plafon / $tenor);
        $currentBalance = $plafon;
        $flatInterest   = (float) round($plafon * ($rate / 100), 0);
        $schedule       = [];
        $baseDate       = $disbursementDate ?? \Carbon\Carbon::now();

        for ($i = 1; $i <= $tenor; $i++) {
            $jasa           = ($method === 'flat')
                ? $flatInterest
                : (float) round($currentBalance * ($rate / 100), 0);

            $isLastMonth    = ($i === $tenor);
            $endingBalance  = $isLastMonth ? 0.0 : max(0.0, $currentBalance - $principalChunk);
            $actualPrincipal = $currentBalance - $endingBalance;

            $schedule[] = [
                'installment_number' => $i,
                'beginning_balance'  => $currentBalance,
                'principal_amount'   => $actualPrincipal,
                'interest_amount'    => $jasa,
                'ending_balance'     => $endingBalance,
                'penalty_fee'        => 0.0,
                'penalty_amount'     => 0.0,
                'total_amount'       => $actualPrincipal + $jasa,
                'due_date'           => $baseDate->copy()->addMonths($i)->toDateString(),
                'status'             => 'unpaid',
            ];

            $currentBalance = $endingBalance;
        }

        return $schedule;
    }

    /**
     * Rekalkulasi saldo berjalan (running balance) dan jadwal bunga menurun
     * secara berurutan berdasarkan PEMBAYARAN POKOK AKTUAL.
     *
     * Aturan Finansial:
     * 1. Bunga/jasa periode berjalan dihitung dari sisa pokok sebelum pembayaran.
     * 2. Pengurangan sisa saldo pokok menggunakan POKOK AKTUAL yang dibayar anggota (bukan pokok jadwal).
     * 3. Sisa saldo pokok baru menjadi saldo awal (beginning_balance) untuk periode berikutnya.
     * 4. Bunga periode berikutnya otomatis menurun mengikuti saldo pokok baru (saldo menurun 2,5%).
     *
     * @return array
     */
    public function recalculateSchedule(): array
    {
        $installments = $this->installments()->orderBy('installment_number', 'asc')->get();
        if ($installments->isEmpty()) {
            return [];
        }

        $tenor       = $this->tenor;
        $method      = $this->attributes['interest_method'] ?? 'declining_balance';
        $defaultRate = ($method === 'flat') ? 1.00 : 2.50;
        $rate        = (float) ($this->attributes['interest_rate'] ?? $defaultRate);
        $plafon      = (float) $this->attributes['amount'];

        // Tentukan saldo awal pinjaman sebelum angsuran ke-1
        $firstInst = $installments->first();
        $initialBalance = ((float) ($firstInst->beginning_balance ?? 0) > 0)
            ? (float) $firstInst->beginning_balance
            : $plafon;

        $runningBalance = $initialBalance;
        $principalChunk = (float) ceil($initialBalance / max(1, $tenor));
        $lastPaidEndingBalance = null;

        $unpaidInstallments = $installments->where('status', '!=', 'paid')->values();
        $unpaidCount = $unpaidInstallments->count();
        $processedUnpaid = 0;

        foreach ($installments as $inst) {
            $isPaid = $inst->status === 'paid';

            if ($isPaid) {
                $beginBal = $runningBalance;
                $actualPrincipal = (float) $inst->principal_amount;
                $endBal = max(0.0, round($beginBal - $actualPrincipal, 2));

                $inst->beginning_balance = $beginBal;
                $inst->ending_balance    = $endBal;
                $inst->save();

                $runningBalance = $endBal;
                $lastPaidEndingBalance = $endBal;
            } else {
                $processedUnpaid++;
                $beginBal = $runningBalance;

                if ($beginBal <= 0) {
                    $inst->beginning_balance = 0.0;
                    $inst->principal_amount  = 0.0;
                    $inst->interest_amount   = 0.0;
                    $inst->ending_balance    = 0.0;
                    $inst->total_amount      = 0.0;
                    $inst->save();
                    $runningBalance = 0.0;
                } else {
                    $jasa = ($method === 'flat')
                        ? (float) round($plafon * ($rate / 100), 0)
                        : (float) round($beginBal * ($rate / 100), 0);

                    $isLastUnpaid = ($processedUnpaid === $unpaidCount);
                    $chunk = ($isLastUnpaid || $beginBal < $principalChunk) ? $beginBal : $principalChunk;
                    $endBal = max(0.0, round($beginBal - $chunk, 2));
                    $actualPrincipal = round($beginBal - $endBal, 2);

                    $penaltyFee = (float) ($inst->penalty_fee ?? $inst->penalty_amount ?? 0);

                    $inst->beginning_balance = $beginBal;
                    $inst->principal_amount  = $actualPrincipal;
                    $inst->interest_amount   = $jasa;
                    $inst->ending_balance    = $endBal;
                    $inst->total_amount      = $actualPrincipal + $jasa + $penaltyFee;
                    $inst->save();

                    $runningBalance = $endBal;
                }
            }
        }

        // Update sisa pokok di model Loan
        $currentRemaining = $lastPaidEndingBalance !== null ? $lastPaidEndingBalance : $initialBalance;
        $this->remaining_principal = $currentRemaining;
        if (\Illuminate\Support\Facades\Schema::hasColumn('loans', 'remaining_amount')) {
            $this->remaining_amount = $currentRemaining;
        }
        if ($currentRemaining <= 0) {
            $this->status = 'completed';
        }
        $this->save();

        return $installments->toArray();
    }
}