<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    /**
     * Multi-Table Login Handler (Users first -> Members next).
     * Endpoint: POST /api/login
     */
    public function login(Request $request): JsonResponse
    {
        // 1. Validasi Input
        $validator = Validator::make($request->all(), [
            'login'    => 'nullable|string',
            'nik'      => 'nullable|string',
            'email'    => 'nullable|string',
            'phone'    => 'nullable|string',
            'no_hp'    => 'nullable|string',
            'password' => 'nullable|string',
            'pin'      => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi input gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Ambil NIK / Phone / Email & Password / PIN
        $loginInput = $request->input('login')
            ?? $request->input('nik')
            ?? $request->input('email')
            ?? $request->input('phone')
            ?? $request->input('no_hp');

        $secretInput = $request->input('password') ?? $request->input('pin');

        if (empty($loginInput) || empty($secretInput)) {
            return response()->json([
                'success' => false,
                'message' => 'NIK atau Password/PIN wajib diisi.',
                'data'    => null,
            ], 422);
        }

     
        // a. Cek Tabel `users` First (Admin / Manager)  
        $user = User::where('nik', $loginInput)
            ->orWhere('email', $loginInput)
            ->first();

        if ($user && Hash::check($secretInput, $user->password)) {
            $token = $user->createToken('koperasi-token')->plainTextToken;

            return response()->json([
                'success'      => true,
                'message'      => 'Login berhasil',
                'token'        => $token,
                'access_token' => $token,
                'token_type'   => 'Bearer',
                'user'         => [
                    'id'   => $user->id,
                    'name' => $user->name,
                    'role' => $user->role ?? 'admin',
                ],
                'data'         => [
                    'access_token' => $token,
                    'user'         => [
                        'id'   => $user->id,
                        'name' => $user->name,
                        'role' => $user->role ?? 'admin',
                    ],
                ],
            ], 200);
        }

      
        // b. Cek Tabel `members` Next (Anggota)    
        $member = Member::where('nik', $loginInput)
            ->orWhere('phone', $loginInput)
            ->orWhere('email', $loginInput)
            ->orWhere('member_number', $loginInput)
            ->first();

        if ($member) {
            $isPasswordValid = false;

            if ($member->password && Hash::check($secretInput, $member->password)) {
                $isPasswordValid = true;
            } elseif ($member->pin_code && ($secretInput === $member->pin_code || Hash::check($secretInput, $member->pin_code))) {
                $isPasswordValid = true;
            }

            if ($isPasswordValid) {
                $token = $member->createToken('koperasi-token')->plainTextToken;

                return response()->json([
                    'success'      => true,
                    'message'      => 'Login berhasil',
                    'token'        => $token,
                    'access_token' => $token,
                    'token_type'   => 'Bearer',
                    'user'         => [
                        'id'   => $member->id,
                        'name' => $member->name,
                        'role' => 'anggota',
                    ],
                    'data'         => [
                        'access_token' => $token,
                        'user'         => [
                            'id'   => $member->id,
                            'name' => $member->name,
                            'role' => 'anggota',
                        ],
                    ],
                ], 200);
            }
        }

      
        // c. Jika Tidak Ditemukan di Kedua Tabel (HTTP 401 Unauthorized)    
        return response()->json([
            'success' => false,
            'message' => 'NIK atau Password salah',
            'data'    => null,
        ], 401);
    }

    /**
     * Handle user logout and revoke Sanctum tokens.
     */
    public function logout(Request $request): JsonResponse
    {
        if ($request->user() && $request->user()->currentAccessToken()) {
            $request->user()->currentAccessToken()->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logout berhasil',
            'data'    => null,
        ], 200);
    }

    /**
     * Get authenticated user profile (supports User or Member model).
     */
    public function me(Request $request): JsonResponse
    {
        $authUser = $request->user();

        $role = ($authUser instanceof Member)
            ? 'anggota'
            : ($authUser->role ?? 'admin');

        return response()->json([
            'success' => true,
            'message' => 'Data user berhasil diambil',
            'data'    => [
                'id'           => $authUser->id,
                'name'         => $authUser->name,
                'email'        => $authUser->email ?? null,
                'phone_number' => $authUser->phone_number ?? null,
                'avatar'       => $authUser->avatar ?? null,
                'avatar_url'   => $authUser->avatar_url ?? null,
                'nik'          => $authUser->nik ?? null,
                'role'         => $role,
            ],
        ], 200);
    }
}