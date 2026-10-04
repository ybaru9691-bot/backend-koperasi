<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TabelarisReportService;
use App\Services\WorksheetReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TabelarisController extends Controller
{
    protected TabelarisReportService $tabelarisService;

    public function __construct(TabelarisReportService $tabelarisService)
    {
        $this->tabelarisService = $tabelarisService;
    }
    /**
     * Endpoint API: GET /api/v1/tabelaris & GET /api/tabelaris
     * Mengambil data transaksi harian terpetakan ke format 29 kolom tabelaris koperasi.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $startDate = $request->input('start_date');
            $endDate   = $request->input('end_date');
            $singleDate= $request->input('date') ?? $request->input('transaction_date');
            $month     = $request->input('month');
            $year      = $request->input('year');
            $period    = $request->input('period');
            $week      = $request->input('week') ?? $request->input('minggu');

            if ($singleDate && !$startDate && !$endDate) {
                $startDate   = date('Y-m-d', strtotime($singleDate));
                $endDate     = $startDate;
                $periodLabel = date('d F Y', strtotime($startDate));
            } else {
                [$startDate, $endDate, $periodLabel] = WorksheetReportService::resolvePeriodDates(
                    $startDate, $endDate, $period, $month, $year, $week
                );
            }

            // Batasi query range maksimal 31 hari agar tidak terjadi timeout 30 detik / memory exhaustion
            if ($startDate && $endDate) {
                $startCarbon = \Carbon\Carbon::parse($startDate);
                $endCarbon   = \Carbon\Carbon::parse($endDate);

                if ($startCarbon->gt($endCarbon)) {
                    $endDate = $startDate;
                    $endCarbon = $startCarbon->copy();
                }

                if ($startCarbon->diffInDays($endCarbon) > 31) {
                    $endDate = $startCarbon->copy()->addDays(31)->format('Y-m-d');
                    $periodLabel = "{$startDate} s/d {$endDate} (Maksimal 31 Hari)";
                }
            } else {
                $startDate = date('Y-m-01');
                $endDate   = date('Y-m-t');
                $periodLabel = date('d F Y', strtotime($startDate)) . ' s/d ' . date('d F Y', strtotime($endDate));
            }

            // Bungkus kalkulasi berat dengan try-catch terpisah agar tidak fatal crash
            try {
                $result = $this->tabelarisService->generateTabelaris($startDate, $endDate, $periodLabel);
            } catch (\Throwable $calcError) {
                \Illuminate\Support\Facades\Log::error('Kalkulasi Jurnal Tabelaris gagal / timeout: ' . $calcError->getMessage(), [
                    'start_date' => $startDate,
                    'end_date'   => $endDate,
                ]);

                return response()->json([
                    'status'  => 'error',
                    'success' => false,
                    'message' => 'Gagal mengambil data Jurnal Tabelaris: ' . $calcError->getMessage(),
                    'data'    => null,
                ], 500);
            }

            return response()->json([
                'status'  => 'success',
                'success' => true,
                'message' => 'Data Jurnal Tabelaris berhasil diambil',
                'data'    => $result,
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'success' => false,
                'message' => 'Gagal mengambil data Jurnal Tabelaris: ' . $e->getMessage(),
                'data'    => null,
            ], 500);
        }
    }

    /**
     * Endpoint API: GET /api/v1/tabelaris/export-excel & GET /api/tabelaris/export-excel
     * Export spreadsheet Excel native (.xlsx) 29 kolom presisi dengan kop surat dan styling.
     */
    public function exportExcel(Request $request)
    {
        // Support otentikasi via Sanctum bearer token maupun query token untuk browser download
        $token = $request->query('token');
        if ($token && !\Laravel\Sanctum\PersonalAccessToken::findToken($token) && !auth('sanctum')->check()) {
            abort(401, 'Unauthorized: Token autentikasi tidak valid.');
        }

        try {
            $startDate = $request->input('start_date');
            $endDate   = $request->input('end_date');
            $singleDate= $request->input('date') ?? $request->input('transaction_date');
            $month     = $request->input('month');
            $year      = $request->input('year');
            $period    = $request->input('period');
            $week      = $request->input('week') ?? $request->input('minggu');

            if ($singleDate && !$startDate && !$endDate) {
                $startDate   = date('Y-m-d', strtotime($singleDate));
                $endDate     = $startDate;
                $periodLabel = date('d F Y', strtotime($startDate));
            } else {
                [$startDate, $endDate, $periodLabel] = WorksheetReportService::resolvePeriodDates(
                    $startDate, $endDate, $period, $month, $year, $week
                );
            }

            // Batasi query range maksimal 31 hari agar tidak terjadi timeout 30 detik / memory exhaustion
            if ($startDate && $endDate) {
                $startCarbon = \Carbon\Carbon::parse($startDate);
                $endCarbon   = \Carbon\Carbon::parse($endDate);

                if ($startCarbon->gt($endCarbon)) {
                    $endDate = $startDate;
                    $endCarbon = $startCarbon->copy();
                }

                if ($startCarbon->diffInDays($endCarbon) > 31) {
                    $endDate = $startCarbon->copy()->addDays(31)->format('Y-m-d');
                    $periodLabel = "{$startDate} s/d {$endDate} (Maksimal 31 Hari)";
                }
            } else {
                $startDate = date('Y-m-01');
                $endDate   = date('Y-m-t');
                $periodLabel = date('d F Y', strtotime($startDate)) . ' s/d ' . date('d F Y', strtotime($endDate));
            }

            $spreadsheet = $this->tabelarisService->generateExcelSpreadsheet($startDate, $endDate, $periodLabel);
            $cleanLabel = preg_replace('/[^a-zA-Z0-9_-]/', '_', $periodLabel ?? date('Ymd'));
            $fileName   = 'Jurnal_Tabelaris_' . $cleanLabel . '.xlsx';

            $response = new StreamedResponse(function () use ($spreadsheet) {
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
            });

            $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $response->headers->set('Content-Disposition', "attachment; filename=\"{$fileName}\"");
            $response->headers->set('Cache-Control', 'max-age=0');
            $response->headers->set('Pragma', 'public');
            return $response;

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengexport file Excel: ' . $e->getMessage(),
            ], 500);
        }
    }
    /**
     * Endpoint API: GET /api/v1/tabelaris/export-pdf & GET /api/tabelaris/export-pdf
     * Export dokumen PDF landscape 29 kolom presisi format buku manual.
     */
    public function exportPdf(Request $request)
    {
        $token = $request->query('token');
        if ($token && !\Laravel\Sanctum\PersonalAccessToken::findToken($token) && !auth('sanctum')->check()) {
            abort(401, 'Unauthorized: Token autentikasi tidak valid.');
        }

        try {
            $startDate = $request->input('start_date');
            $endDate   = $request->input('end_date');
            $singleDate= $request->input('date') ?? $request->input('transaction_date');
            $month     = $request->input('month');
            $year      = $request->input('year');
            $period    = $request->input('period');
            $week      = $request->input('week') ?? $request->input('minggu');

            if ($singleDate && !$startDate && !$endDate) {
                $startDate   = date('Y-m-d', strtotime($singleDate));
                $endDate     = $startDate;
                $periodLabel = date('d F Y', strtotime($startDate));
            } else {
                [$startDate, $endDate, $periodLabel] = WorksheetReportService::resolvePeriodDates(
                    $startDate, $endDate, $period, $month, $year, $week
                );
            }

            // Batasi query range maksimal 31 hari agar tidak terjadi timeout 30 detik / memory exhaustion
            if ($startDate && $endDate) {
                $startCarbon = \Carbon\Carbon::parse($startDate);
                $endCarbon   = \Carbon\Carbon::parse($endDate);

                if ($startCarbon->gt($endCarbon)) {
                    $endDate = $startDate;
                    $endCarbon = $startCarbon->copy();
                }

                if ($startCarbon->diffInDays($endCarbon) > 31) {
                    $endDate = $startCarbon->copy()->addDays(31)->format('Y-m-d');
                    $periodLabel = "{$startDate} s/d {$endDate} (Maksimal 31 Hari)";
                }
            } else {
                $startDate = date('Y-m-01');
                $endDate   = date('Y-m-t');
                $periodLabel = date('d F Y', strtotime($startDate)) . ' s/d ' . date('d F Y', strtotime($endDate));
            }

            $data = $this->tabelarisService->generateTabelaris($startDate, $endDate, $periodLabel);

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.jurnal_tabelaris_pdf', $data);
            $pdf->setPaper('a3', 'landscape');

            $cleanLabel = preg_replace('/[^a-zA-Z0-9_-]/', '_', $periodLabel ?? date('Ymd'));
            $fileName   = 'Jurnal_Tabelaris_' . $cleanLabel . '.pdf';

            return $pdf->download($fileName);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengexport file PDF: ' . $e->getMessage(),
            ], 500);
        }
    }
}
