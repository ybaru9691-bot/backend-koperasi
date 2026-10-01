<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionSearchFilterTest extends TestCase
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
            'name'          => 'Budi Santoso',
            'member_number' => 1001,
            'nik'           => '12345678901001',
            'status'        => 'active',
        ]);

        $this->member2 = Member::create([
            'name'          => 'Siti Rahma',
            'member_number' => 1002,
            'nik'           => '12345678901002',
            'status'        => 'active',
        ]);

        // Transaksi 1
        Transaction::create([
            'transaction_number' => 'TRX-2026-001',
            'receipt_number'     => 'KM-0101',
            'member_id'          => $this->member1->id,
            'type'               => 'deposit',
            'amount'             => 150000,
            'description'        => 'Setoran Simpanan Wajib Bulan Mei',
            'transaction_date'   => '2026-05-10',
            'status'             => 'approved',
        ]);

        // Transaksi 2
        Transaction::create([
            'transaction_number' => 'TRX-2026-002',
            'receipt_number'     => 'KK-0202',
            'member_id'          => $this->member2->id,
            'type'               => 'withdrawal',
            'amount'             => 50000,
            'description'        => 'Penarikan Simpanan Harian',
            'transaction_date'   => '2026-05-12',
            'status'             => 'approved',
        ]);
    }

    public function test_search_by_receipt_number()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/transactions?search=KM-0101');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('KM-0101', $data[0]['receipt_number']);
        $this->assertEquals('Budi Santoso', $data[0]['member_name']);
    }

    public function test_search_by_description()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/transactions?search=Simpanan+Wajib');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('Setoran Simpanan Wajib Bulan Mei', $data[0]['description']);
    }

    public function test_search_by_member_name()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/transactions?search=Siti+Rahma');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('KK-0202', $data[0]['receipt_number']);
        $this->assertEquals('Siti Rahma', $data[0]['member_name']);
    }

    public function test_search_by_member_number()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/transactions?search=1001');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('Budi Santoso', $data[0]['member_name']);
    }

    public function test_pagination_retains_search_query_param()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/transactions?search=KM-0101&per_page=1');

        $response->assertStatus(200);
        $nextPageUrl = $response->json('data.next_page_url');
        $firstPageUrl = $response->json('data.first_page_url');

        $this->assertStringContainsString('search=KM-0101', $firstPageUrl);
    }
}
