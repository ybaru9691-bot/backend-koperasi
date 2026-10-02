<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BukuPutihInterestService;
use App\Services\BukuPutihLedgerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BukuPutihMemberStatusToggleTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected AccountingPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'role' => 'manager',
        ]);

        $this->period = AccountingPeriod::create([
            'period_name' => 'Tahun Buku 2026/2027',
            'start_date'  => '2026-06-01',
            'end_date'    => '2027-05-31',
            'status'      => 'open',
            'is_locked'   => false,
        ]);
    }

    public function test_toggle_member_status_api_endpoints()
    {
        Sanctum::actingAs($this->adminUser);

        $member = Member::create([
            'name'                 => 'Test Anggota Aktif',
            'member_number'        => 101,
            'nik'                  => '1234567890101',
            'status'               => 'active',
            'is_white_book_active' => true,
            'daily_savings'        => 1000000,
        ]);

        // 1. Toggle via PATCH /api/buku-putih/members/{id}/toggle-status to inactive
        $response = $this->patchJson("/api/buku-putih/members/{$member->id}/toggle-status", [
            'status' => 'tidak_aktif',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'id'                   => $member->id,
                    'status'               => 'active',
                    'is_white_book_active' => false,
                    'is_active'            => false,
                    'status_label'         => 'TIDAK AKTIF',
                ],
            ]);

        $this->assertDatabaseHas('members', [
            'id'                   => $member->id,
            'status'               => 'active',
            'is_white_book_active' => false,
        ]);

        // 2. Toggle via POST /api/members/{id}/toggle-status (auto toggle back to active)
        $response2 = $this->postJson("/api/members/{$member->id}/toggle-status");
        $response2->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'id'                   => $member->id,
                    'status'               => 'active',
                    'is_white_book_active' => true,
                    'is_active'            => true,
                    'status_label'         => 'AKTIF',
                ],
            ]);

        $this->assertDatabaseHas('members', [
            'id'                   => $member->id,
            'status'               => 'active',
            'is_white_book_active' => true,
        ]);

        // 3. Toggle via PATCH /api/members/{id}/status with 'pasif' -> sets is_white_book_active to false
        $response3 = $this->patchJson("/api/members/{$member->id}/status", [
            'status' => 'pasif',
        ]);

        $response3->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'id'                   => $member->id,
                    'status'               => 'active',
                    'is_white_book_active' => false,
                    'is_active'            => false,
                    'status_label'         => 'TIDAK AKTIF',
                ],
            ]);

        $this->assertDatabaseHas('members', [
            'id'                   => $member->id,
            'status'               => 'active',
            'is_white_book_active' => false,
        ]);
    }

    public function test_inactive_member_gets_zero_interest_in_preview_and_distribution()
    {
        Sanctum::actingAs($this->adminUser);

        // Anggota 1: Aktif dengan saldo Rp 1.000.000
        $activeMember = Member::create([
            'name'          => 'Anggota Aktif Bunga',
            'member_number' => 201,
            'nik'           => '1234567890201',
            'status'        => 'active',
            'daily_savings' => 1000000,
        ]);

        // Transaksi setoran di bulan berjalan (sebelum cut-off 20 September 2026)
        Transaction::create([
            'transaction_number' => 'KM-TEST-001',
            'receipt_number'     => 'KM-TEST-001',
            'member_id'          => $activeMember->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 1000000,
            'beginning_balance'  => 0,
            'ending_balance'     => 1000000,
            'transaction_date'   => '2026-09-10',
            'status'             => 'approved',
        ]);

        // Anggota 2: Dinonaktifkan (status = 'inactive') dengan saldo Rp 2.000.000
        $inactiveMember = Member::create([
            'name'          => 'Anggota Nonaktif Bunga',
            'member_number' => 202,
            'nik'           => '1234567890202',
            'status'        => 'inactive',
            'daily_savings' => 2000000,
        ]);

        Transaction::create([
            'transaction_number' => 'KM-TEST-002',
            'receipt_number'     => 'KM-TEST-002',
            'member_id'          => $inactiveMember->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 2000000,
            'beginning_balance'  => 0,
            'ending_balance'     => 2000000,
            'transaction_date'   => '2026-09-10',
            'status'             => 'approved',
        ]);

        $interestService = app(BukuPutihInterestService::class);
        $preview = $interestService->preview(9, 2026);

        $this->assertEquals(1, $preview['eligible_count']);
        $this->assertEquals(1, $preview['passive_count']);
        $this->assertEquals(6000, $preview['total_interest_amount']); // 0.6% * 1.000.000

        // Temukan detail anggota
        $activeDetail = collect($preview['members'])->firstWhere('member_id', $activeMember->id);
        $this->assertTrue($activeDetail['is_eligible']);
        $this->assertEquals(6000, $activeDetail['interest_amount']);

        $inactiveDetail = collect($preview['members'])->firstWhere('member_id', $inactiveMember->id);
        $this->assertFalse($inactiveDetail['is_eligible']);
        $this->assertEquals(0, $inactiveDetail['interest_amount']);

        // Test Distribusi: Pastikan hanya anggota aktif yang menerima mutasi kredit bunga
        $distributeResult = $interestService->distribute(9, 2026, $this->adminUser->id);
        $this->assertTrue($distributeResult['success']);
        $this->assertEquals(1, $distributeResult['processed_count']);

        $activeMember->refresh();
        $inactiveMember->refresh();

        $this->assertEquals(1006000, (float) $activeMember->daily_savings);
        $this->assertEquals(2000000, (float) $inactiveMember->daily_savings); // Saldo tidak bertambah
    }

    public function test_inactive_member_ledger_and_pdf_reflects_zero_interest()
    {
        Sanctum::actingAs($this->adminUser);

        $inactiveMember = Member::create([
            'name'          => 'Anggota Pasif Ledger',
            'member_number' => 301,
            'nik'           => '1234567890301',
            'status'        => 'inactive', // explicit inactive
            'daily_savings' => 500000,
        ]);

        Transaction::create([
            'transaction_number' => 'KM-IMP-301',
            'receipt_number'     => 'KM-IMP-301',
            'member_id'          => $inactiveMember->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 500000,
            'beginning_balance'  => 0,
            'ending_balance'     => 500000,
            'transaction_date'   => '2026-05-31',
            'status'             => 'approved',
        ]);

        $ledgerService = app(BukuPutihLedgerService::class);
        $ledgerData = $ledgerService->getMemberBukuPutihLedger($inactiveMember->id, $this->period->id);

        $this->assertEquals('TIDAK AKTIF', $ledgerData['status_label']);
        $this->assertFalse($ledgerData['is_active']);
        $this->assertEquals(0.0, $ledgerData['total_interest']);
        $this->assertEquals(500000, $ledgerData['closing_balance']);

        // Verifikasi endpoint getLedger
        $response = $this->getJson("/api/members/{$inactiveMember->id}/buku-putih-ledger?period_id={$this->period->id}");
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'status_label'   => 'TIDAK AKTIF',
                    'is_active'      => false,
                    'total_interest' => 0,
                ],
            ]);

        // Verifikasi export PDF
        $pdfResponse = $this->get("/api/members/{$inactiveMember->id}/buku-putih-ledger/export-pdf?period_id={$this->period->id}");
        $pdfResponse->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $pdfResponse->headers->get('content-type'));
    }
}
