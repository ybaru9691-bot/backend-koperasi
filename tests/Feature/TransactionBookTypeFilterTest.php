<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionBookTypeFilterTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected Member $member1;
    protected Member $member2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'role' => 'admin',
        ]);

        $this->member1 = Member::create([
            'name'          => 'Rudy Harianja',
            'member_number' => 101,
            'nik'           => '1234567890101',
            'status'        => 'active',
        ]);

        $this->member2 = Member::create([
            'name'          => 'Demi Manullang',
            'member_number' => 102,
            'nik'           => '1234567890102',
            'status'        => 'active',
        ]);

        // Transaksi Buku Biru (Tanggal lebih baru: 25 September 2026)
        Transaction::create([
            'transaction_number' => 'TRX-IMP-20260925-101-BIR-001',
            'receipt_number'     => 'KM-IMP-20260925-101-001',
            'member_id'          => $this->member1->id,
            'book_type'          => 'BUKU_BIRU',
            'type'               => 'deposit',
            'amount'             => 5000000,
            'description'        => 'Saldo Awal Simpanan (Buku Biru) - Rudy Harianja',
            'transaction_date'   => '2026-09-25',
            'status'             => 'approved',
        ]);

        // Transaksi Buku Putih (Tanggal historis: 20 Agustus 2026)
        Transaction::create([
            'transaction_number' => 'TRX-IMP-20260820-102-PUT-002',
            'receipt_number'     => 'KM-IMP-P-20260820-102-002',
            'member_id'          => $this->member2->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 1337000,
            'description'        => 'Saldo Awal Buku Putih - Demi Manullang',
            'transaction_date'   => '2026-08-20',
            'status'             => 'approved',
        ]);

        // Transaksi Penarikan Buku Putih
        Transaction::create([
            'transaction_number' => 'TRX-IMP-20260821-102-PUT-003',
            'receipt_number'     => 'KK-IMP-P-20260821-102-003',
            'member_id'          => $this->member2->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'withdrawal',
            'amount'             => 200000,
            'description'        => 'Penarikan Simpanan Harian - Demi Manullang',
            'transaction_date'   => '2026-08-21',
            'status'             => 'approved',
        ]);
    }

    public function test_default_without_filter_returns_all_book_types()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/transactions');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $data = $response->json('data.data');
        $this->assertCount(3, $data);
        // Karena default sort transaction_date desc, transaksi Buku Biru (25 Sept) paling atas
        $this->assertEquals('BUKU_BIRU', $data[0]['book_type']);
    }

    public function test_filter_book_type_buku_biru()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/transactions?book_type=BUKU_BIRU');

        $response->assertStatus(200);
        $data = $response->json('data.data');

        $this->assertCount(1, $data);
        $this->assertEquals('BUKU_BIRU', $data[0]['book_type']);
        $this->assertEquals('Rudy Harianja', $data[0]['member_name']);
    }

    public function test_filter_book_type_buku_putih_appears_on_page_one()
    {
        Sanctum::actingAs($this->adminUser);

        // Filter Buku Putih harus menampilkan transaksi Buku Putih langsung di halaman 1
        $response = $this->getJson('/api/transactions?book_type=BUKU_PUTIH');

        $response->assertStatus(200);
        $data = $response->json('data.data');

        $this->assertCount(2, $data);
        foreach ($data as $trx) {
            $this->assertEquals('BUKU_PUTIH', $trx['book_type']);
        }
    }

    public function test_filter_book_type_case_insensitive_and_alias()
    {
        Sanctum::actingAs($this->adminUser);

        $response1 = $this->getJson('/api/transactions?book_type=Buku+Putih');
        $response1->assertStatus(200);
        $this->assertCount(2, $response1->json('data.data'));

        $response2 = $this->getJson('/api/transactions?book_type=buku_biru');
        $response2->assertStatus(200);
        $this->assertCount(1, $response2->json('data.data'));

        // 'Semua' harus mengabaikan filter
        $response3 = $this->getJson('/api/transactions?book_type=Semua');
        $response3->assertStatus(200);
        $this->assertCount(3, $response3->json('data.data'));
    }

    public function test_filter_book_type_combined_with_type_filter()
    {
        Sanctum::actingAs($this->adminUser);

        // Filter: Buku Putih + Kas Keluar (withdrawal)
        $response = $this->getJson('/api/transactions?book_type=BUKU_PUTIH&type=withdrawal');

        $response->assertStatus(200);
        $data = $response->json('data.data');

        $this->assertCount(1, $data);
        $this->assertEquals('BUKU_PUTIH', $data[0]['book_type']);
        $this->assertEquals('withdrawal', $data[0]['type']);
        $this->assertEquals(200000, $data[0]['amount']);
    }

    public function test_filter_book_type_combined_with_search_filter()
    {
        Sanctum::actingAs($this->adminUser);

        // Filter: Buku Putih + search 'Demi'
        $response = $this->getJson('/api/transactions?book_type=BUKU_PUTIH&search=Demi');

        $response->assertStatus(200);
        $data = $response->json('data.data');

        $this->assertCount(2, $data);
        $this->assertEquals('Demi Manullang', $data[0]['member_name']);
    }

    public function test_pagination_retains_book_type_query_param()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/transactions?book_type=BUKU_PUTIH&per_page=1');

        $response->assertStatus(200);
        $firstPageUrl = $response->json('data.first_page_url');
        $this->assertStringContainsString('book_type=BUKU_PUTIH', $firstPageUrl);
    }
}
