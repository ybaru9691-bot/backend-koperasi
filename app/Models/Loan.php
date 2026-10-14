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
        'interest_arrears',
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
        'interest_arrears'    => 'float',
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
                'installment_number'      => $i,
                'beginning_balance'       => $currentBalance,
                'principal_amount'        => $actualPrincipal,
                'scheduled_interest'      => $jasa,
                'arrears_interest_billed' => 0.0,
                'unpaid_interest'         => 0.0,
                'interest_amount'         => $jasa,
                'ending_balance'          => $endingBalance,
                'penalty_fee'             => 0.0,
                'penalty_amount'          => 0.0,
                'total_amount'            => $actualPrincipal + $jasa,
                'due_date'                => $baseDate->copy()->addMonths($i)->toDateString(),
                'status'                  => 'unpaid',
            ];

            $currentBalance = $endingBalance;
        }

        return $schedule;
    }

    /**
     * Rekalkulasi saldo berjalan (running balance) dan jadwal bunga menurun
     * secara berurutan berdasarkan PEMBAYARAN POKOK AKTUAL dan akumulasi tunggakan bunga.
     *
     * Aturan Finansial:
     * 1. Bunga/jasa periode berjalan dihitung dari sisa pokok sebelum pembayaran.
     * 2. Pengurangan sisa saldo pokok menggunakan POKOK AKTUAL yang dibayar anggota (bukan pokok jadwal).
     * 3. Sisa saldo pokok baru menjadi saldo awal (beginning_balance) untuk periode berikutnya.
     * 4. Bunga periode berikutnya otomatis menurun mengikuti saldo pokok baru (saldo menurun 2,5%).
     * 5. Sisa bunga yang belum lunas (interest_arrears) ditagihkan pada angsuran aktif berikutnya (arrears_interest_billed).
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
        $method      = $this->interest_method ?? $this->attributes['interest_method'] ?? 'declining_balance';
        $defaultRate = ($method === 'flat') ? 1.00 : 2.50;
        $rate        = (float) ($this->interest_rate ?? $this->attributes['interest_rate'] ?? $defaultRate);
        $monthlyRate = $rate / 100;
        $plafon      = (float) ($this->amount ?? $this->attributes['amount'] ?? 0);

        // Helper detector status angsuran lunas
        $isInstPaid = function ($inst): bool {
            $status = strtolower(trim((string) ($inst->status ?? '')));
            $hasPaidAt = !empty($inst->paid_at);
            $hasReceipt = !empty($inst->receipt_number) && trim((string) $inst->receipt_number) !== '-' && trim((string) $inst->receipt_number) !== 'null';
            return in_array($status, ['paid', 'lunas', 'sudah_bayar', 'selesai']) || $hasPaidAt || $hasReceipt;
        };

        // 1. Tentukan saldo awal pinjaman sebelum angsuran ke-1
        $firstInst = $installments->first();
        $initialBalance = ((float) ($firstInst->beginning_balance ?? 0) > 0)
            ? (float) $firstInst->beginning_balance
            : $plafon;

        $runningBalance = $initialBalance;
        $lastPaidEndingBalance = null;

        // Ambil saldo tunggakan bunga pinjaman saat ini
        $arrearsToBill = (float) ($this->interest_arrears ?? 0);

        // 2. Proses berurutan angsuran yang SUDAH LUNAS
        foreach ($installments as $inst) {
            if ($isInstPaid($inst)) {
                $beginBal = $runningBalance;
                $actualPrincipal = (float) $inst->principal_amount;
                $endBal = max(0.0, round($beginBal - $actualPrincipal, 2));

                $inst->beginning_balance = $beginBal;
                $inst->ending_balance    = $endBal;
                $inst->save();

                $runningBalance = $endBal;
                $lastPaidEndingBalance = $endBal;
            }
        }

        // 3. Basis Sisa Pokok Terkini untuk Jadwal yang Belum Lunas
        $currentRemainingPrincipal = $lastPaidEndingBalance !== null
            ? $lastPaidEndingBalance
            : (float) ($this->remaining_principal ?? $initialBalance);

        $runningBalance = $currentRemainingPrincipal;

        // Ambil daftar angsuran yang belum dibayar
        $unpaidInstallments = $installments->filter(function ($inst) use ($isInstPaid) {
            return !$isInstPaid($inst);
        })->values();
        $unpaidCount = $unpaidInstallments->count();

        // 4. Kalkulasi Jadwal yang Belum Bayar
        for ($idx = 0; $idx < $unpaidCount; $idx++) {
            $inst = $unpaidInstallments[$idx];
            $remainingMonths = $unpaidCount - $idx;
            $beginBal = $runningBalance;

            if ($beginBal <= 0) {
                $billedArrears = ($idx === 0) ? $arrearsToBill : 0.0;
                $inst->beginning_balance       = 0.0;
                $inst->principal_amount        = 0.0;
                $inst->scheduled_interest      = 0.0;
                $inst->arrears_interest_billed = $billedArrears;
                $inst->unpaid_interest         = 0.0;
                $inst->interest_amount         = $billedArrears;
                $inst->ending_balance          = 0.0;
                $inst->total_amount            = $billedArrears;
                $inst->save();

                $runningBalance = 0.0;
            } else {
                // Bunga dihitung murni dari sisa saldo pokok berjalan periode ini (current_balance * monthlyRate)
                $scheduledInterest = round($beginBal * $monthlyRate, 2);

                // Bebankan tunggakan bunga periode sebelumnya hanya pada angsuran aktif pertama yang belum dibayar
                $billedArrears = ($idx === 0) ? $arrearsToBill : 0.0;
                $totalInterestDue = round($scheduledInterest + $billedArrears, 2);

                // Pokok disesuaikan agar sisa tenor membagi habis sisa pokok sampai lunas (Rp 0 di akhir tenor)
                if ($remainingMonths <= 1) {
                    $actualPrincipal = $beginBal;
                    $endBal = 0.0;
                } else {
                    $chunk = (float) ceil($beginBal / $remainingMonths);
                    $endBal = max(0.0, round($beginBal - $chunk, 2));
                    $actualPrincipal = round($beginBal - $endBal, 2);
                }

                $penaltyFee = (float) ($inst->penalty_fee ?? $inst->penalty_amount ?? 0);
                $totalAmount = round($actualPrincipal + $totalInterestDue + $penaltyFee, 2);

                $inst->beginning_balance       = $beginBal;
                $inst->principal_amount        = $actualPrincipal;
                $inst->scheduled_interest      = $scheduledInterest;
                $inst->arrears_interest_billed = $billedArrears;
                $inst->interest_amount         = $totalInterestDue;
                $inst->ending_balance          = $endBal;
                $inst->total_amount            = $totalAmount;
                $inst->save();

                $runningBalance = $endBal;
            }
        }

        // 5. Update sisa pokok di model Loan
        $this->remaining_principal = $currentRemainingPrincipal;
        if (\Illuminate\Support\Facades\Schema::hasColumn('loans', 'remaining_amount')) {
            $this->remaining_amount = $currentRemainingPrincipal;
        }
        if ($currentRemainingPrincipal <= 0 && (float) ($this->interest_arrears ?? 0) <= 0) {
            $this->status = 'completed';
        }
        $this->save();

        return $installments->toArray();
    }
}