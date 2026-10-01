<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnnouncementCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_announcement_category_accepts_lowercase_input_and_normalizes_it(): void
    {
        $manager = User::create([
            'name'     => 'Manager Koperasi',
            'email'    => 'manager_category@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'manager',
            'nik'      => '1122334455667799',
        ]);

        Sanctum::actingAs($manager);

        $createResponse = $this->postJson('/api/announcements', [
            'title'   => 'Pengumuman Uji Coba',
            'content' => 'Isi pengumuman untuk validasi kategori.',
            'category' => 'penting',
        ]);

        $createResponse->assertStatus(201);
        $createResponse->assertJsonPath('data.category', 'PENTING');

        $announcementId = $createResponse->json('data.id');

        $updateResponse = $this->putJson('/api/announcements/' . $announcementId, [
            'category' => 'promo',
        ]);

        $updateResponse->assertStatus(200);
        $updateResponse->assertJsonPath('data.category', 'PROMO');

        $listResponse = $this->getJson('/api/announcements?category=promo');

        $listResponse->assertStatus(200);
        $this->assertNotEmpty($listResponse->json('data'));
        $this->assertSame('PROMO', $listResponse->json('data.0.category'));
    }
}
