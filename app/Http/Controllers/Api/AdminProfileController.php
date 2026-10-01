<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminProfileController extends Controller
{
    /**
     * Mengambil detail profil admin yang sedang login.
     * Endpoint: GET /api/admin/profile
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Pengguna tidak terautentikasi',
                'data'    => null,
            ], 401);
        }

        return response()->json([
            'success' => true,
            'message' => 'Profil admin berhasil dimuat',
            'data'    => [
                'id'           => $user->id,
                'nik'          => $user->nik ?? null,
                'name'         => $user->name,
                'email'        => $user->email,
                'phone_number' => $user->phone_number ?? null,
                'avatar'       => $user->avatar ?? null,
                'avatar_url'   => $user->avatar_url ?? null,
                'role'         => $user->role ?? 'admin',
                'is_active'    => (bool) ($user->is_active ?? true),
                'created_at'   => $user->created_at,
                'updated_at'   => $user->updated_at,
            ],
        ], 200);
    }

    /**
     * Memperbarui profil admin yang sedang login (Nama, Email, No HP, Avatar).
     * Endpoint: PUT|POST /api/admin/profile
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Pengguna tidak terautentikasi',
                'data'    => null,
            ], 401);
        }

        // 1. Validasi Request
        $validator = Validator::make($request->all(), [
            'name'         => 'required|string|max:255',
            'email'        => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'phone_number' => 'nullable|string|max:20',
            'avatar'       => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
        ], [
            'name.required'     => 'Nama lengkap wajib diisi.',
            'email.required'    => 'Alamat email wajib diisi.',
            'email.email'       => 'Format email tidak valid.',
            'email.unique'      => 'Alamat email ini sudah digunakan oleh akun lain.',
            'phone_number.max'  => 'Nomor telepon tidak boleh lebih dari 20 karakter.',
            'avatar.image'      => 'File avatar harus berupa gambar.',
            'avatar.mimes'      => 'Format avatar harus jpeg, png, atau jpg.',
            'avatar.max'        => 'Ukuran avatar maksimal 2048 KB (2MB).',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        // 2. Penanganan Upload File Avatar
        if ($request->hasFile('avatar')) {
            $avatarFile = $request->file('avatar');

            if ($avatarFile && $avatarFile->isValid()) {
                // Hapus avatar lama jika ada di storage public disk
                if ($user->avatar && !filter_var($user->avatar, FILTER_VALIDATE_URL) && Storage::disk('public')->exists($user->avatar)) {
                    Storage::disk('public')->delete($user->avatar);
                }

                // Simpan avatar baru ke storage/app/public/avatars
                $avatarPath = $avatarFile->store('avatars', 'public');
                $user->avatar = $avatarPath;
            }
        }

        // 3. Update field data identitas
        $user->name = $request->input('name');
        $user->email = $request->input('email');
        if ($request->has('phone_number')) {
            $user->phone_number = $request->input('phone_number');
        }

        $user->save();

        // 4. Return response JSON 200
        return response()->json([
            'success' => true,
            'message' => 'Profil admin berhasil diperbarui',
            'data'    => [
                'id'           => $user->id,
                'nik'          => $user->nik ?? null,
                'name'         => $user->name,
                'email'        => $user->email,
                'phone_number' => $user->phone_number ?? null,
                'avatar'       => $user->avatar ?? null,
                'avatar_url'   => $user->avatar_url ?? null,
                'role'         => $user->role ?? 'admin',
                'is_active'    => (bool) ($user->is_active ?? true),
                'created_at'   => $user->created_at,
                'updated_at'   => $user->updated_at,
            ],
        ], 200);
    }
}
