<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManagerMembersPaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = User::factory()->create([
            'name' => 'Manager Pelita',
            'role' => 'manager',
        ]);

        Sanctum::actingAs($manager);
    }

    public function test_get_manager_members_returns_server_side_pagination()
    {
        // Create 30 members
        for ($i = 1; $i <= 30; $i++) {
            Member::create([
                'member_number'     => sprintf('M-%04d', $i),
                'nik'               => sprintf('320100000000%04d', $i),
                'name'              => 'Anggota ' . sprintf('%02d', $i),
                'phone'             => '0812345678' . sprintf('%02d', $i),
                'status'            => 'active',
                'principal_savings' => 200000,
                'mandatory_savings' => 100000,
                'voluntary_savings' => 50000,
                'daily_savings'     => 25000,
            ]);
        }

        // 1. Default pagination (25 per page)
        $response = $this->getJson('/api/manager/members');

        $response->assertStatus(200)
            ->assertJson([
                'success'      => true,
                'status'       => 'success',
                'current_page' => 1,
                'per_page'     => 25,
                'total'        => 30,
                'last_page'    => 2,
            ]);

        $this->assertCount(25, $response->json('data'));

        // 2. Custom per_page = 10 on page 2
        $responsePage2 = $this->getJson('/api/manager/members?per_page=10&page=2');

        $responsePage2->assertStatus(200)
            ->assertJson([
                'success'      => true,
                'current_page' => 2,
                'per_page'     => 10,
                'total'        => 30,
                'last_page'    => 3,
            ]);

        $this->assertCount(10, $responsePage2->json('data'));
    }

    public function test_manager_members_search_and_status_filtering_with_pagination()
    {
        // Active members
        Member::create([
            'member_number'     => 'PELITA-001',
            'nik'               => '1111222233334444',
            'name'              => 'Bambang Sudirman',
            'phone'             => '081111111111',
            'status'            => 'active',
            'principal_savings' => 200000,
        ]);

        Member::create([
            'member_number'     => 'PELITA-002',
            'nik'               => '5555666677778888',
            'name'              => 'Siti Rahmawati',
            'phone'             => '082222222222',
            'status'            => 'active',
            'principal_savings' => 200000,
        ]);

        // Inactive member
        Member::create([
            'member_number'     => 'PELITA-003',
            'nik'               => '9999000011112222',
            'name'              => 'Bambang Pensiun',
            'phone'             => '083333333333',
            'status'            => 'inactive',
            'principal_savings' => 200000,
        ]);

        // Search for 'Bambang'
        $responseSearch = $this->getJson('/api/manager/members?search=Bambang');
        $responseSearch->assertStatus(200)
            ->assertJson([
                'success' => true,
                'total'   => 2,
            ]);
        $this->assertCount(2, $responseSearch->json('data'));

        // Filter active only + search Bambang
        $responseActiveBambang = $this->getJson('/api/manager/members?search=Bambang&status=active');
        $responseActiveBambang->assertStatus(200)
            ->assertJson([
                'success' => true,
                'total'   => 1,
            ]);
        $this->assertCount(1, $responseActiveBambang->json('data'));
        $this->assertEquals('Bambang Sudirman', $responseActiveBambang->json('data.0.name'));
    }

    public function test_member_number_search_with_leading_zeros_and_plain_numbers()
    {
        // Member with plain number "1"
        Member::create([
            'member_number'     => '1',
            'nik'               => '1000000000000001',
            'name'              => 'Anggota Nomor Satu',
            'phone'             => '081234567801',
            'status'            => 'active',
            'principal_savings' => 200000,
        ]);

        // Member with padded number "0025"
        Member::create([
            'member_number'     => '0025',
            'nik'               => '1000000000000025',
            'name'              => 'Anggota Nomor Dua Puluh Lima',
            'phone'             => '081234567825',
            'status'            => 'active',
            'principal_savings' => 200000,
        ]);

        // 1. Search "0001" finds member with member_number = "1"
        $res0001 = $this->getJson('/api/manager/members?search=0001');
        $res0001->assertStatus(200);
        $this->assertEquals(1, $res0001->json('total'));
        $this->assertEquals('Anggota Nomor Satu', $res0001->json('data.0.name'));

        // 2. Search "25" finds member with member_number = "0025"
        $res25 = $this->getJson('/api/manager/members?search=25');
        $res25->assertStatus(200);
        $this->assertEquals(1, $res25->json('total'));
        $this->assertEquals('Anggota Nomor Dua Puluh Lima', $res25->json('data.0.name'));

        // 3. Test on /api/members/search autocomplete as well
        $resSearch0001 = $this->getJson('/api/members/search?q=0001');
        $resSearch0001->assertStatus(200);
        $this->assertCount(1, $resSearch0001->json('data'));
        $this->assertEquals('Anggota Nomor Satu', $resSearch0001->json('data.0.name'));
    }
}
