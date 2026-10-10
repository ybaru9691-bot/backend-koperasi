<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MemberDetailsFilteringTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_details_mutation_filtering()
    {
        // 1. Create a user and authenticate with Sanctum
        $user = User::create([
            'name' => 'Admin Test',
            'email' => 'admin.test@koperasi.com',
            'password' => bcrypt('123456'),
            'role' => 'admin',
            'nik' => '1234567890123456',
        ]);
        Sanctum::actingAs($user);

        // 2. Create a member
        $member = Member::create([
            'member_number' => 'PELITA-202608-0001',
            'nik' => '1234567890123456',
            'name' => 'John Doe',
            'email' => 'johndoe@koperasi.com',
            'phone' => '081234567890',
            'password' => bcrypt('123456'),
            'pin_code' => '123456',
            'address' => 'Jl. Testing No. 1',
            'status' => 'active',
        ]);

        // 3. Create a cash account
        $account = Account::forceCreate([
            'account_number' => 'KAS-101',
            'account_name' => 'Kas Koperasi',
            'account_type' => 'kas',
            'category' => 'asset',
            'balance' => 1000000.00,
        ]);

        // 4. Create transactions
        // A. Setoran Tabungan Harian (Buku Putih) - type: deposit
        $t1 = Transaction::create([
            'transaction_number' => 'TRX-001',
            'receipt_number' => 'REC-001',
            'member_id' => $member->id,
            'account_id' => $account->id,
            'type' => 'deposit',
            'amount' => 50000.00,
            'transaction_date' => '2026-08-14',
            'description' => 'Setoran Tabungan Harian (Buku Putih)',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        // B. Penarikan Tabungan Harian - type: withdrawal
        $t2 = Transaction::create([
            'transaction_number' => 'TRX-002',
            'receipt_number' => 'REC-002',
            'member_id' => $member->id,
            'account_id' => $account->id,
            'type' => 'withdrawal',
            'amount' => 20000.00,
            'transaction_date' => '2026-08-14',
            'description' => 'Penarikan Tabungan Harian',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        // C. Bunga Simpanan Buku Putih (0.6%) - type: deposit/interest
        $t3 = Transaction::create([
            'transaction_number' => 'TRX-003',
            'receipt_number' => 'REC-003',
            'member_id' => $member->id,
            'account_id' => $account->id,
            'type' => 'deposit',
            'amount' => 600.00,
            'transaction_date' => '2026-08-14',
            'description' => 'Bunga Simpanan Buku Putih (0.6%)',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        // --- TEST 1: Filter 'semua' or empty ---
        $responseAll = $this->getJson("/api/members/{$member->id}/details");
        $responseAll->assertStatus(200);
        $txListAll = $responseAll->json('data.transactions');
        $this->assertCount(3, $txListAll);

        // --- TEST 2: Filter 'bunga' ---
        $responseBunga = $this->getJson("/api/members/{$member->id}/details?filter=bunga");
        $responseBunga->assertStatus(200);
        $txListBunga = $responseBunga->json('data.transactions');
        $this->assertCount(1, $txListBunga);
        $this->assertEquals('TRX-003', $txListBunga[0]['transaction_number']);

        // --- TEST 3: Filter 'setor' & variations ---
        $responseSetor = $this->getJson("/api/members/{$member->id}/details?filter=setor");
        $responseSetor->assertStatus(200);
        $txListSetor = $responseSetor->json('data.transactions');
        $this->assertCount(1, $txListSetor);
        $this->assertEquals('TRX-001', $txListSetor[0]['transaction_number']);

        $responseTypeSetor = $this->getJson("/api/members/{$member->id}/details?type=setor");
        $responseTypeSetor->assertStatus(200);
        $this->assertCount(1, $responseTypeSetor->json('data.transactions'));

        $responseTabSetor = $this->getJson("/api/members/{$member->id}/details?tab=setor");
        $responseTabSetor->assertStatus(200);
        $this->assertCount(1, $responseTabSetor->json('data.transactions'));

        // --- TEST 4: Filter 'tarik' ---
        $responseTarik = $this->getJson("/api/members/{$member->id}/details?filter=tarik");
        $responseTarik->assertStatus(200);
        $txListTarik = $responseTarik->json('data.transactions');
        $this->assertCount(1, $txListTarik);
        $this->assertEquals('TRX-002', $txListTarik[0]['transaction_number']);
    }

    public function test_member_profile_update_fields()
    {
        $user = User::create([
            'name' => 'Admin Profile Test',
            'email' => 'admin.profile@koperasi.com',
            'password' => bcrypt('123456'),
            'role' => 'admin',
            'nik' => '1234567890123477',
        ]);
        Sanctum::actingAs($user);

        $member = Member::create([
            'member_number' => 'PELITA-202608-0099',
            'nik' => '1234567890123499',
            'name' => 'John Profile',
            'email' => 'johnprofile@koperasi.com',
            'phone' => '081234567899',
            'password' => bcrypt('123456'),
            'pin_code' => '123456',
            'status' => 'active',
        ]);

        // Update profile with custom fields
        $payload = [
            'birth_place'       => 'Medan',
            'birth_date'        => '1995-05-15',
            'gender'            => 'Laki-laki',
            'occupation'        => 'Developer',
            'education'         => 'S1',
            'family_status'     => 'Menikah',
            'heir_name'         => 'Jane Doe',
            'heir_relationship' => 'Istri',
            'heir_birth_place'  => 'Jakarta',
            'heir_birth_date'   => '1996-06-16',
            'heir_address'      => 'Jl. Ahli Waris No. 10',
        ];

        $response = $this->putJson("/api/members/{$member->id}", $payload);
        $response->assertStatus(200);

        $memberFresh = $member->fresh();
        $this->assertEquals('Medan', $memberFresh->place_of_birth);
        $this->assertEquals('1995-05-15', $memberFresh->date_of_birth->format('Y-m-d'));
        $this->assertEquals('Laki-laki', $memberFresh->gender);
        $this->assertEquals('Developer', $memberFresh->occupation);
        $this->assertEquals('S1', $memberFresh->education);
        $this->assertEquals('Menikah', $memberFresh->family_status);
        $this->assertEquals('Jane Doe', $memberFresh->heir_name);
        $this->assertEquals('Istri', $memberFresh->heir_relationship);
        $this->assertEquals('Jakarta', $memberFresh->heir_place_of_birth);
        $this->assertEquals('1996-06-16', $memberFresh->heir_date_of_birth->format('Y-m-d'));
        $this->assertEquals('Jl. Ahli Waris No. 10', $memberFresh->heir_address);

        // Update with direct place_of_birth and date_of_birth
        $resDirect = $this->putJson("/api/members/{$member->id}", [
            'place_of_birth' => 'Semarang',
            'date_of_birth'  => '1992-02-20',
        ]);
        $resDirect->assertStatus(200);
        $memberDirect = $member->fresh();
        $this->assertEquals('Semarang', $memberDirect->place_of_birth);
        $this->assertEquals('1992-02-20', $memberDirect->date_of_birth->format('Y-m-d'));

        // Update with Indonesian alias tempat_lahir and tanggal_lahir
        $resAlias = $this->putJson("/api/members/{$member->id}", [
            'tempat_lahir'  => 'Surabaya',
            'tanggal_lahir' => '1993-03-25',
        ]);
        $resAlias->assertStatus(200);
        $memberAlias = $member->fresh();
        $this->assertEquals('Surabaya', $memberAlias->place_of_birth);
        $this->assertEquals('1993-03-25', $memberAlias->date_of_birth->format('Y-m-d'));
    }

    public function test_member_sorting()
    {
        $user = User::create([
            'name' => 'Admin Test Sort',
            'email' => 'admin.sort@koperasi.com',
            'password' => bcrypt('123456'),
            'role' => 'admin',
            'nik' => '1234567890123477',
        ]);
        Sanctum::actingAs($user);

        // Create member B
        Member::create([
            'member_number' => '0002',
            'nik' => '1111111111111111',
            'name' => 'Zachary',
            'email' => 'zack@koperasi.com',
            'phone' => '081234567812',
            'status' => 'active',
        ]);

        // Create member A
        Member::create([
            'member_number' => '0001',
            'nik' => '2222222222222222',
            'name' => 'Alice',
            'email' => 'alice@koperasi.com',
            'phone' => '081234567813',
            'status' => 'active',
        ]);

        // 1. Sort by name: Alice should come first, then Zachary
        $resName = $this->getJson('/api/members?sort=name');
        $resName->assertStatus(200);
        $names = collect($resName->json('data'))->pluck('name')->toArray();
        $this->assertEquals(['Alice', 'Zachary'], $names);

        // 2. Default sort or sort by member_number: Alice (0001) then Zachary (0002)
        $resDefault = $this->getJson('/api/members');
        $resDefault->assertStatus(200);
        $numbers = collect($resDefault->json('data'))->pluck('member_number')->toArray();
        $this->assertEquals(['0001', '0002'], $numbers);
    }

    public function test_update_member_nik_field_success_and_unique_validation()
    {
        $user = User::create([
            'name'     => 'Admin Nik Update',
            'email'    => 'admin.nikup@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'admin',
            'nik'      => '1122334455667799',
        ]);
        Sanctum::actingAs($user);

        $member1 = Member::create([
            'member_number' => '001.001',
            'nik'           => 'TEMP000000000001',
            'name'          => 'Member Temp NIK',
            'email'         => 'tempnik@pelita.local',
            'phone'         => '081234567801',
            'status'        => 'active',
            'password'      => bcrypt('123456'),
        ]);

        $member2 = Member::create([
            'member_number' => '001.002',
            'nik'           => '3201010000000002',
            'name'          => 'Other Member',
            'email'         => 'other@pelita.local',
            'phone'         => '081234567802',
            'status'        => 'active',
            'password'      => bcrypt('123456'),
        ]);

        // 1. Sukses update NIK sementara ke NIK asli (16 digit)
        $res1 = $this->putJson("/api/members/{$member1->id}", [
            'nik'  => '3201019999999999',
            'name' => 'Member Temp NIK Updated',
        ]);
        $res1->assertStatus(200);
        $this->assertEquals('3201019999999999', $member1->fresh()->nik);

        // 2. Sukses update data lain dengan NIK yang sama (ignore self ID)
        $res2 = $this->putJson("/api/members/{$member1->id}", [
            'nik'  => '3201019999999999',
            'name' => 'Member Temp NIK Renamed',
        ]);
        $res2->assertStatus(200);

        // 3. Gagal jika mengupdate NIK ke NIK yang sudah dimiliki member lain
        $res3 = $this->putJson("/api/members/{$member1->id}", [
            'nik' => '3201010000000002', // Milik member2
        ]);
        $res3->assertStatus(422);
        $res3->assertJsonValidationErrors(['nik']);
    }

    public function test_update_member_buku_putih_no_success()
    {
        $user = User::create([
            'name'     => 'Admin BP Update',
            'email'    => 'admin.bpup@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'admin',
            'nik'      => '1122334455667733',
        ]);
        Sanctum::actingAs($user);

        $member = Member::create([
            'member_number' => '001.009',
            'nik'           => '3201010000000009',
            'name'          => 'Member Buku Putih Test',
            'email'         => 'bptest@pelita.local',
            'phone'         => '081234567809',
            'status'        => 'active',
            'password'      => bcrypt('123456'),
            'buku_putih_no' => null,
        ]);

        // 1. Update buku_putih_no via PUT
        $res1 = $this->putJson("/api/members/{$member->id}", [
            'buku_putih_no' => '2021-0017',
        ]);
        $res1->assertStatus(200);
        $this->assertEquals('2021-0017', $member->fresh()->buku_putih_no);
        $this->assertTrue((bool)$member->fresh()->has_buku_putih);

        // 2. Cek endpoint get member detail mengembalikan buku_putih_no
        $resShow = $this->getJson("/api/members/{$member->id}");
        $resShow->assertStatus(200);
        $this->assertEquals('2021-0017', $resShow->json('data.buku_putih_no'));

        $resDetails = $this->getJson("/api/members/{$member->id}/details");
        $resDetails->assertStatus(200);
        $this->assertEquals('2021-0017', $resDetails->json('data.buku_putih_no'));

        // 3. Update dengan alias no_buku_putih via PATCH
        $res2 = $this->patchJson("/api/members/{$member->id}", [
            'no_buku_putih' => '2021-0099',
        ]);
        $res2->assertStatus(200);
        $this->assertEquals('2021-0099', $member->fresh()->buku_putih_no);

        // 4. Update kosong/dash mengembalikan null
        $res3 = $this->putJson("/api/members/{$member->id}", [
            'buku_putih_no' => '-',
        ]);
        $res3->assertStatus(200);
        $this->assertNull($member->fresh()->buku_putih_no);
    }
}

