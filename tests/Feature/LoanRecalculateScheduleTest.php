<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanRecalculateScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ChartOfAccountSeeder::class);

        $this->member = Member::create([
            'member_number'     => 'PELITA-0100',
            'name'              => 'Bpk. Simanjuntak',
            'nik'               => '3201010101010100',
            'phone'             => '081234567100',
            'status'            => 'active',
            'principal_savings' => 200000.00,
            'mandatory_savings' => 100000.00,
            'voluntary_savings' => 500000.00,
            'has_buku_biru'     => true,
        ]);
    }

    /**
     * Skenario 1 (Sesuai Permintaan User):
     * Pinjaman Rp 50.000.000 tenor 10 bulan, rate bunga 2.0% per bulan.
     * Angsuran 1, 2, 3 sudah dibayar dengan total pokok Rp 15.000.000,
     * sehingga sisa pokok aktual menjadi Rp 35.000.000.
     * Bunga angsuran ke-4 harus tepat Rp 35.000.000 x 2% = Rp 700.000 (bukan Rp 875.000 / flat lama).
     * Pokok tiap bulan sisa = Rp 5.000.000, dan ending balance di angsuran ke-10 tepat Rp 0.
     */
    public function test_recalculate_schedule_with_overpaid_principal_computes_exact_interest_and_ends_zero(): void
    {
        $loan = Loan::create([
            'loan_code'           => 'PJ-2026-0100',
            'member_id'           => $this->member->id,
            'amount'              => 50000000.00,
            'interest_rate'       => 2.00, // 2% per bulan
            'interest_method'     => 'declining_balance',
            'duration_months'     => 10,
            'tenor_months'        => 10,
            'monthly_installment' => 6000000.00,
            'remaining_amount'    => 50000000.00,
            'remaining_principal' => 50000000.00,
            'application_date'    => now()->toDateString(),
            'status'              => 'DISBURSED',
        ]);

        // Buat 10 baris jadwal angsuran
        for ($i = 1; $i <= 10; $i++) {
            LoanInstallment::create([
                'loan_id'                 => $loan->id,
                'installment_number'      => $i,
                'beginning_balance'       => 50000000.00 - (($i - 1) * 5000000.00),
                'principal_amount'        => 5000000.00,
                'interest_amount'         => 1000000.00,
                'scheduled_interest'      => 1000000.00,
                'arrears_interest_billed' => 0.00,
                'unpaid_interest'         => 0.00,
                'ending_balance'          => 50000000.00 - ($i * 5000000.00),
                'penalty_fee'             => 0.00,
                'total_amount'            => 6000000.00,
                'due_date'                => now()->addMonths($i)->toDateString(),
                'paid_by_member_id'       => $this->member->id,
                'status'                  => 'unpaid',
            ]);
        }

        // Simulasikan 3 angsuran pertama sudah lunas dengan total pokok 15.000.000
        LoanInstallment::where('loan_id', $loan->id)->whereIn('installment_number', [1, 2, 3])->update([
            'status'           => 'paid',
            'paid_at'          => now(),
            'principal_amount' => 5000000.00,
        ]);

        // Jalankan recalculateSchedule
        $loan->recalculateSchedule();

        // 1. Verifikasi sisa pokok pinjaman
        $loan->refresh();
        $this->assertEquals(35000000.00, (float) $loan->remaining_principal);

        // 2. Verifikasi angsuran ke-4 (angsuran berikutnya yang belum lunas)
        $inst4 = LoanInstallment::where('loan_id', $loan->id)->where('installment_number', 4)->first();
        $this->assertEquals('unpaid', $inst4->status);
        $this->assertEquals(35000000.00, (float) $inst4->beginning_balance);
        // Sesuai contoh user: Rp 35.000.000 x 2% = Rp 700.000 (BUKAN Rp 875.000)
        $this->assertEquals(700000.00, (float) $inst4->scheduled_interest);
        $this->assertEquals(700000.00, (float) $inst4->interest_amount);
        $this->assertEquals(5000000.00, (float) $inst4->principal_amount);
        $this->assertEquals(30000000.00, (float) $inst4->ending_balance);
        $this->assertEquals(5700000.00, (float) $inst4->total_amount);

        // 3. Verifikasi angsuran ke-5: sisa pokok 30.000.000 x 2% = 600.000
        $inst5 = LoanInstallment::where('loan_id', $loan->id)->where('installment_number', 5)->first();
        $this->assertEquals(30000000.00, (float) $inst5->beginning_balance);
        $this->assertEquals(600000.00, (float) $inst5->scheduled_interest);
        $this->assertEquals(5000000.00, (float) $inst5->principal_amount);
        $this->assertEquals(25000000.00, (float) $inst5->ending_balance);

        // 4. Verifikasi angsuran terakhir (ke-10): saldo akhir tepat Rp 0
        $inst10 = LoanInstallment::where('loan_id', $loan->id)->where('installment_number', 10)->first();
        $this->assertEquals(5000000.00, (float) $inst10->beginning_balance);
        $this->assertEquals(5000000.00, (float) $inst10->principal_amount);
        $this->assertEquals(100000.00, (float) $inst10->scheduled_interest); // 5.000.000 x 2% = 100.000
        $this->assertEquals(0.00, (float) $inst10->ending_balance);
    }

    /**
     * Skenario 2:
     * Jika anggota membayar pokok kurang dari target (misal di angsuran 1 hanya bayar pokok Rp 2.000.000 dari target 5.000.000).
     * Sisa pokok menjadi Rp 48.000.000.
     * Bunga angsuran ke-2 otomatis naik proporsional menjadi 48.000.000 x 2% = Rp 960.000.
     * Sisa pokok dibagi rata ke sisa 9 bulan (ceil(48.000.000 / 9) = 5.333.334) dan lunas Rp 0 di akhir tenor.
     */
    public function test_recalculate_schedule_with_underpaid_principal_increases_interest_proportionally(): void
    {
        $loan = Loan::create([
            'loan_code'           => 'PJ-2026-0101',
            'member_id'           => $this->member->id,
            'amount'              => 50000000.00,
            'interest_rate'       => 2.00,
            'interest_method'     => 'declining_balance',
            'duration_months'     => 10,
            'tenor_months'        => 10,
            'monthly_installment' => 6000000.00,
            'remaining_amount'    => 50000000.00,
            'remaining_principal' => 50000000.00,
            'application_date'    => now()->toDateString(),
            'status'              => 'DISBURSED',
        ]);

        for ($i = 1; $i <= 10; $i++) {
            LoanInstallment::create([
                'loan_id'                 => $loan->id,
                'installment_number'      => $i,
                'beginning_balance'       => 50000000.00,
                'principal_amount'        => 5000000.00,
                'interest_amount'         => 1000000.00,
                'scheduled_interest'      => 1000000.00,
                'arrears_interest_billed' => 0.00,
                'unpaid_interest'         => 0.00,
                'ending_balance'          => 45000000.00,
                'penalty_fee'             => 0.00,
                'total_amount'            => 6000000.00,
                'due_date'                => now()->addMonths($i)->toDateString(),
                'paid_by_member_id'       => $this->member->id,
                'status'                  => 'unpaid',
            ]);
        }

        // Angsuran 1 dibayar pokok hanya Rp 2.000.000
        LoanInstallment::where('loan_id', $loan->id)->where('installment_number', 1)->update([
            'status'           => 'paid',
            'paid_at'          => now(),
            'principal_amount' => 2000000.00,
        ]);

        $loan->recalculateSchedule();
        $loan->refresh();

        $this->assertEquals(48000000.00, (float) $loan->remaining_principal);

        // Angsuran 2: awal 48.000.000, bunga = 48.000.000 x 2% = 960.000
        $inst2 = LoanInstallment::where('loan_id', $loan->id)->where('installment_number', 2)->first();
        $this->assertEquals(48000000.00, (float) $inst2->beginning_balance);
        $this->assertEquals(960000.00, (float) $inst2->scheduled_interest);

        // Angsuran 10 berakhir di Rp 0
        $inst10 = LoanInstallment::where('loan_id', $loan->id)->where('installment_number', 10)->first();
        $this->assertEquals(0.00, (float) $inst10->ending_balance);
    }
}
