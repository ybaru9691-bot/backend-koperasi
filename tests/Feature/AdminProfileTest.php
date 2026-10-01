<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_unauthenticated_user_cannot_access_or_update_admin_profile()
    {
        $responseGet = $this->getJson('/api/admin/profile');
        $responseGet->assertStatus(401);

        $responsePut = $this->putJson('/api/admin/profile', [
            'name'  => 'New Admin',
            'email' => 'newadmin@example.com',
        ]);
        $responsePut->assertStatus(401);
    }

    public function test_admin_can_view_own_profile()
    {
        $admin = User::factory()->create([
            'name'         => 'Admin Pelita',
            'email'        => 'admin@koperasi.com',
            'phone_number' => '081234567890',
            'role'         => 'admin',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/profile');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Profil admin berhasil dimuat',
                'data'    => [
                    'id'           => $admin->id,
                    'name'         => 'Admin Pelita',
                    'email'        => 'admin@koperasi.com',
                    'phone_number' => '081234567890',
                    'role'         => 'admin',
                ],
            ]);
    }

    public function test_admin_can_update_profile_without_avatar()
    {
        $admin = User::factory()->create([
            'name'         => 'Old Name',
            'email'        => 'old@koperasi.com',
            'phone_number' => '081111111111',
            'role'         => 'admin',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->putJson('/api/admin/profile', [
            'name'         => 'Updated Admin Name',
            'email'        => 'updated@koperasi.com',
            'phone_number' => '082222222222',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Profil admin berhasil diperbarui',
                'data'    => [
                    'id'           => $admin->id,
                    'name'         => 'Updated Admin Name',
                    'email'        => 'updated@koperasi.com',
                    'phone_number' => '082222222222',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'id'           => $admin->id,
            'name'         => 'Updated Admin Name',
            'email'        => 'updated@koperasi.com',
            'phone_number' => '082222222222',
        ]);
    }

    public function test_admin_can_update_profile_with_avatar_upload()
    {
        $admin = User::factory()->create([
            'name'         => 'Admin Foto',
            'email'        => 'foto@koperasi.com',
            'role'         => 'admin',
        ]);

        Sanctum::actingAs($admin);

        $file = UploadedFile::fake()->create('avatar.jpg', 100, 'image/jpeg');

        $response = $this->postJson('/api/admin/profile', [
            'name'         => 'Admin Foto Baru',
            'email'        => 'foto@koperasi.com',
            'phone_number' => '08987654321',
            'avatar'       => $file,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Profil admin berhasil diperbarui',
                'data'    => [
                    'name'         => 'Admin Foto Baru',
                    'email'        => 'foto@koperasi.com',
                    'phone_number' => '08987654321',
                ],
            ]);

        $admin->refresh();
        $this->assertNotNull($admin->avatar);
        $this->assertStringStartsWith('avatars/', $admin->avatar);
        $this->assertNotNull($admin->avatar_url);
        Storage::disk('public')->assertExists($admin->avatar);

        // Test updating with a new avatar deletes previous file
        $oldAvatarPath = $admin->avatar;
        $newFile = UploadedFile::fake()->create('avatar2.png', 150, 'image/png');

        $response2 = $this->postJson('/api/admin/profile', [
            'name'         => 'Admin Foto Baru 2',
            'email'        => 'foto@koperasi.com',
            'phone_number' => '08987654321',
            'avatar'       => $newFile,
        ]);

        $response2->assertStatus(200);
        $admin->refresh();
        $this->assertNotEquals($oldAvatarPath, $admin->avatar);
        Storage::disk('public')->assertMissing($oldAvatarPath);
        Storage::disk('public')->assertExists($admin->avatar);
    }

    public function test_validation_rejects_invalid_inputs()
    {
        $otherUser = User::factory()->create([
            'email' => 'taken@koperasi.com',
        ]);

        $admin = User::factory()->create([
            'name'  => 'Current Admin',
            'email' => 'admin@koperasi.com',
            'role'  => 'admin',
        ]);

        Sanctum::actingAs($admin);

        // 1. Missing name & invalid email
        $response1 = $this->putJson('/api/admin/profile', [
            'name'  => '',
            'email' => 'invalid-email',
        ]);
        $response1->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors']);

        // 2. Email already taken by another user
        $response2 = $this->putJson('/api/admin/profile', [
            'name'  => 'Admin Name',
            'email' => 'taken@koperasi.com',
        ]);
        $response2->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        // 3. Avatar not an image
        $textFile = UploadedFile::fake()->create('document.pdf', 500, 'application/pdf');
        $response3 = $this->postJson('/api/admin/profile', [
            'name'   => 'Admin Name',
            'email'  => 'admin@koperasi.com',
            'avatar' => $textFile,
        ]);
        $response3->assertStatus(422)
            ->assertJsonValidationErrors(['avatar']);
    }
}
