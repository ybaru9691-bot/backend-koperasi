<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BukuPutihInterestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class InterestController extends Controller
{
    protected BukuPutihInterestService $interestService;

    public function __construct(BukuPutihInterestService $interestService)
    {
        $this->interestService = $interestService;
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
     * Preview kalkulasi pembagian bunga Buku Putih (0,6%)
     * Endpoint: GET /api/manager/interest/preview
     */
    public function preview(Request $request): JsonResponse
    {
        try {
            $month = (int) ($request->input('month') ?? $request->input('bulan') ?? now()->month);
            $year  = (int) ($request->input('year') ?? $request->input('tahun') ?? now()->year);

            $data = $this->interestService->preview($month, $year);

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Preview kalkulasi bunga Buku Putih berhasil dimuat.',
                'data'    => $data,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal memuat preview bunga: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Eksekusi pendistribusian bunga Buku Putih (0,6%) & pencatatan Jurnal Bukti Memorial
     * Endpoint: POST /api/manager/interest/distribute-buku-putih
     */
    public function distribute(Request $request): JsonResponse
    {
        try {
            $month = (int) ($request->input('month') ?? $request->input('bulan') ?? now()->month);
            $year  = (int) ($request->input('year') ?? $request->input('tahun') ?? now()->year);
            $user  = $request->user();
            $userId = $user ? $user->id : null;

            $result = $this->interestService->distribute($month, $year, $userId);

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => $result['message'],
                'data'    => $result,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal mendistribusikan bunga: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Ekspor Cetak PDF Bukti Memorial (BM)
     * Endpoint: GET /api/manager/interest/export-memorial-pdf
     */
    public function exportMemorialPdf(Request $request)
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
            $month = (int) ($request->input('month') ?? $request->input('bulan') ?? now()->month);
            $year  = (int) ($request->input('year') ?? $request->input('tahun') ?? now()->year);

            return $this->interestService->exportPdf($month, $year);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Gagal export PDF Bukti Memorial: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal mencetak Bukti Memorial PDF: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Ekspor Cetak Excel Bukti Memorial (BM)
     * Endpoint: GET /api/manager/interest/export-memorial-excel
     */
    public function exportMemorialExcel(Request $request)
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
            $month = (int) ($request->input('month') ?? $request->input('bulan') ?? now()->month);
            $year  = (int) ($request->input('year') ?? $request->input('tahun') ?? now()->year);

            return $this->interestService->exportExcel($month, $year);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Gagal export Excel Bukti Memorial: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengekspor Bukti Memorial Excel: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Lembar Buku Putih 12 Siklus Bulanan Anggota
     * Endpoint: GET /api/manager/members/{id}/white-book-statement
     */
    public function memberWhiteBookStatement(Request $request, $id): JsonResponse
    {
        try {
            $fiscalYear = $request->has('fiscal_year') ? (int) $request->input('fiscal_year') : null;
            $month      = $request->has('month') ? (int) $request->input('month') : null;
            $year       = $request->has('year') ? (int) $request->input('year') : null;

            $data = $this->interestService->getMemberWhiteBookStatement((int) $id, $fiscalYear, $month, $year);

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Lembar Buku Putih anggota berhasil dimuat.',
                'data'    => $data,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal memuat Lembar Buku Putih anggota: ' . $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Ekspor Cetak PDF Lembar Buku Putih Anggota
     * Endpoint: GET /api/manager/members/{id}/white-book-statement/export-pdf
     */
    public function exportMemberWhiteBookStatementPdf(Request $request, $id)
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
            $fiscalYear = $request->has('fiscal_year') ? (int) $request->input('fiscal_year') : null;
            $month      = $request->has('month') ? (int) $request->input('month') : null;
            $year       = $request->has('year') ? (int) $request->input('year') : null;

            return $this->interestService->exportMemberWhiteBookStatementPdf((int) $id, $fiscalYear, $month, $year);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Gagal export PDF Lembar Buku Putih: ' . $e->getMessage(), [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal mencetak Lembar Buku Putih PDF: ' . $e->getMessage(),
            ], 500);
        }
    }
}
