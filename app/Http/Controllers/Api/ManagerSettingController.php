<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
class ManagerSettingController extends Controller
{
    /**
     * Mengambil persentase alokasi SHU aktif
     * Endpoint: GET /api/manager/settings/shu-percentage
     */
    public function getShuPercentage(Request $request): JsonResponse
    {
        $percentage = (float) Cache::get('setting_shu_percentage', config('koperasi.shu_percentage', 25.0));

        return response()->json([
            'success' => true,
            'status'  => 'success',
            'message' => 'Persentase alokasi SHU berhasil diambil',
            'data'    => [
                'shu_percentage' => $percentage,
                'percentage'     => $percentage,
            ]
        ], 200);
    }

    /**
     * Memperbarui persentase alokasi SHU
     * Endpoint: PUT /api/manager/settings/shu-percentage & POST /api/manager/settings/shu-percentage
     */
    public function updateShuPercentage(Request $request): JsonResponse
    {
        $request->validate([
            'percentage'     => 'nullable|numeric|min:0|max:100',
            'shu_percentage' => 'nullable|numeric|min:0|max:100',
        ]);

        $val = $request->input('percentage') ?? $request->input('shu_percentage');

        if ($val === null) {
            return response()->json([
                'success' => false,
                'message' => 'Parameter percentage atau shu_percentage wajib diisi.',
            ], 422);
        }

        $percentage = (float) $val;

        // Simpan persentase ke cache persisten
        Cache::forever('setting_shu_percentage', $percentage);

        return response()->json([
            'success' => true,
            'status'  => 'success',
            'message' => 'Persentase Alokasi SHU berhasil diperbarui menjadi ' . $percentage . '%.',
            'data'    => [
                'shu_percentage' => $percentage,
                'percentage'     => $percentage,
            ]
        ], 200);
    }
}