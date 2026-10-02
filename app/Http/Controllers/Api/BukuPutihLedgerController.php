<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BukuPutihLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

class BukuPutihLedgerController extends Controller
{
    protected BukuPutihLedgerService $ledgerService;

    public function __construct(BukuPutihLedgerService $ledgerService)
    {
        $this->ledgerService = $ledgerService;
    }

    /**
     * Validasi autentikasi untuk download / export (Sanctum session, Bearer header, atau query token)
     */
    private function validateExportAuth(Request $request): bool
    {
        if (auth('sanctum')->check() || $request->user('sanctum')) {
            return true;
        }

        $token = $request->bearerToken() 
            ?? $request->query('token') 
            ?? $request->query('access_token') 
            ?? $request->input('token');

        if (!empty($token)) {
            $accessToken = PersonalAccessToken::findToken($token);
            if ($accessToken) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mengambil data mutasi 12 bulan Buku Putih (Simpanan Harian) anggota pada periode aktif
     * Endpoint: GET /api/members/{id}/buku-putih-ledger?period_id={period_id}
     */
    public function getLedger(Request $request, $id): JsonResponse
    {
        try {
            $periodId   = $request->has('period_id') ? (int) $request->input('period_id') : null;
            $fiscalYear = $request->has('fiscal_year') ? (int) $request->input('fiscal_year') : null;
            $year       = $request->has('year') ? (int) $request->input('year') : null;

            $data = $this->ledgerService->getMemberBukuPutihLedger((int) $id, $periodId, $fiscalYear, $year);

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Data mutasi Buku Putih (Simpanan Harian) 12 bulan berhasil dimuat.',
                'data'    => $data,
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Data anggota tidak ditemukan.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Gagal mengambil data mutasi Buku Putih: ' . $e->getMessage(), [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal memuat mutasi Buku Putih: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cetak & Ekspor PDF Lembar Mutasi Buku Putih (Simpanan Harian) Anggota
     * Endpoint: GET /api/members/{id}/buku-putih-ledger/export-pdf
     */
    public function exportPdf(Request $request, $id)
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        if (!$this->validateExportAuth($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Token autentikasi tidak valid atau telah kadaluarsa.'
            ], 401);
        }

        try {
            $periodId   = $request->has('period_id') ? (int) $request->input('period_id') : null;
            $fiscalYear = $request->has('fiscal_year') ? (int) $request->input('fiscal_year') : null;
            $year       = $request->has('year') ? (int) $request->input('year') : null;

            return $this->ledgerService->exportPdf((int) $id, $periodId, $fiscalYear, $year);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data anggota tidak ditemukan.'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Gagal export PDF Lembar Buku Putih: ' . $e->getMessage(), [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal mencetak Buku Putih PDF: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle status keaktifan anggota Buku Putih (Simpanan Harian)
     * Endpoint: PATCH/POST /api/buku-putih/members/{id}/toggle-status
     *           PATCH/POST /api/members/{id}/toggle-status
     *           PATCH/POST /api/members/{id}/status
     */
    public function toggleStatus(Request $request, $id): JsonResponse
    {
        try {
            $member = \App\Models\Member::findOrFail($id);

            $inactiveStatuses = [
                'inactive', 'non-active', 'pasif', 'keluar', 'resigned', 'blokir', '0',
                'tidak_aktif', 'tidak-aktif', 'non_aktif', 'nonaktif', 'false', 'off'
            ];
            $activeStatuses = ['active', 'aktif', '1', 'true', 'on'];

            $currentIsActive = ($member->is_white_book_active !== false && $member->is_white_book_active !== 0 && $member->is_white_book_active !== '0');

            // 1. Prioritaskan jika request membawa boolean is_active / is_white_book_active / white_book_active
            if ($request->has('is_active') || $request->has('is_white_book_active') || $request->has('white_book_active')) {
                $rawBool = $request->input('is_white_book_active') ?? $request->input('white_book_active') ?? $request->input('is_active');
                if ($rawBool === false || $rawBool === 0 || $rawBool === '0' || $rawBool === 'false') {
                    $newWbActive = false;
                } elseif ($rawBool === true || $rawBool === 1 || $rawBool === '1' || $rawBool === 'true') {
                    $newWbActive = true;
                } else {
                    $newWbActive = $request->boolean('is_active');
                }
            } elseif ($request->has('status') || $request->has('status_buku_putih')) {
                // 2. Jika membawa string status
                $requestedStatus = strtolower(trim((string) ($request->input('status_buku_putih') ?? $request->input('status'))));
                if (in_array($requestedStatus, $inactiveStatuses, true)) {
                    $newWbActive = false;
                } elseif (in_array($requestedStatus, $activeStatuses, true)) {
                    $newWbActive = true;
                } else {
                    $newWbActive = true;
                }
            } else {
                // 3. Auto toggle jika tanpa parameter
                $newWbActive = !$currentIsActive;
            }

            // PERATURAN MUTLAK: Hanya ubah is_white_book_active, JANGAN mematikan members.status utama keanggotaan!
            $member->is_white_book_active = $newWbActive;
            if (empty($member->status) || in_array(strtolower($member->status), ['inactive', 'tidak_aktif', 'pasif'])) {
                $member->status = 'active';
            }
            $member->save();

            \Illuminate\Support\Facades\Cache::forget('member_' . $member->id);

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Status keaktifan Buku Putih berhasil diperbarui.',
                'data'    => [
                    'id'                   => $member->id,
                    'member_number'        => $member->member_number,
                    'name'                 => $member->name,
                    'status'               => $member->status,
                    'is_white_book_active' => (bool) $member->is_white_book_active,
                    'white_book_active'    => (bool) $member->is_white_book_active,
                    'is_active'            => (bool) $member->is_white_book_active,
                    'status_label'         => $member->is_white_book_active ? 'AKTIF' : 'TIDAK AKTIF',
                ],
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Data anggota tidak ditemukan.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Gagal memperbarui status keaktifan anggota: ' . $e->getMessage(), [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal memperbarui status keaktifan: ' . $e->getMessage(),
            ], 500);
        }
    }
}