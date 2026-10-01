<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\JournalDetail;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\Member;
use App\Models\MemberShuDistribution;
use App\Models\Period;
use App\Models\ShuDistribution;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class PeriodController extends Controller
{
    /**
     * Mengambil status dan detail periode akuntansi aktif
     * Endpoint: GET /api/manager/periods/active
     */
    public function getActivePeriod(Request $request): JsonResponse
    {
        try {
            // 1. Ambil periode aktif dengan: AccountingPeriod::where('is_locked', false)->latest()->first();
            $activePeriod = AccountingPeriod::where('is_locked', false)
                ->whereIn('status', ['open', 'OPEN', 'terbuka'])
                ->latest('id')
                ->first();

            if (!$activePeriod) {
                $activePeriod = AccountingPeriod::where('is_locked', false)->latest('id')->first();
            }

            if (!$activePeriod) {
                $activePeriod = Period::where('is_locked', false)->latest('id')->first();
            }

            // 2. Jika tidak ada sama sekali, ambil record periode tahun 2026/terbaru dan ubah menjadi is_locked = false
            if (!$activePeriod) {
                $latestAcc = AccountingPeriod::where('period_name', 'like', '%2026%')
                    ->orWhere('start_date', '>=', '2026-01-01')
                    ->latest('id')
                    ->first();

                if (!$latestAcc) {
                    $latestAcc = AccountingPeriod::latest('id')->first();
                }

                if ($latestAcc) {
                    $latestAcc->update([
                        'status'    => 'OPEN',
                        'is_locked' => false,
                        'is_active' => true,
                        'closed_at' => null,
                        'closed_by' => null,
                    ]);
                    $activePeriod = $latestAcc;
                } else {
                    $this->initializeDefaultPeriods();
                    $activePeriod = AccountingPeriod::where('is_locked', false)->latest('id')->first();
                }
            }

            if (!$activePeriod) {
                $currentYear = date('Y');
                $start = Carbon::create($currentYear, 6, 1)->format('Y-m-d');
                $end = Carbon::create($currentYear + 1, 5, 31)->format('Y-m-d');
                $activePeriod = AccountingPeriod::create([
                    'period_name' => "Juni {$currentYear} - Mei " . ($currentYear + 1),
                    'start_date'  => $start,
                    'end_date'    => $end,
                    'status'      => 'OPEN',
                    'is_locked'   => false,
                    'is_active'   => true,
                ]);
            }

            // Pastikan entri di tabel periods juga open
            $periodEntry = Period::where('id', $activePeriod->id)
                ->orWhere('period_name', $activePeriod->period_name)
                ->first();
            if ($periodEntry && ($periodEntry->is_locked || $periodEntry->status !== 'open')) {
                $periodEntry->update([
                    'status'    => 'open',
                    'is_locked' => false,
                    'is_active' => true,
                    'closed_at' => null,
                    'closed_by' => null,
                ]);
            }

            $startDateStr = $activePeriod->start_date instanceof Carbon ? $activePeriod->start_date->toDateString() : (string) $activePeriod->start_date;
            $endDateStr   = $activePeriod->end_date instanceof Carbon ? $activePeriod->end_date->toDateString() : (string) $activePeriod->end_date;

            // Hitung total transaksi riil pada rentang tanggal periode
            $totalTransactions = Transaction::whereBetween('transaction_date', [$startDateStr, $endDateStr])->count();
            if ($totalTransactions === 0) {
                $totalTransactions = Transaction::count();
            }

            // Hitung sisa hari dari hari ini ke tanggal akhir periode
            $now = Carbon::now();
            $endDate = Carbon::parse($endDateStr);
            $remainingDays = $now->lt($endDate) ? (int) $now->diffInDays($endDate) : 0;

            // Ambil daftar riwayat periode yang sudah dikunci (LOCKED / CLOSED)
            $historyPeriods = AccountingPeriod::where(function ($q) {
                    $q->whereIn('status', ['closed', 'CLOSED', 'locked', 'LOCKED', 'dikunci'])
                      ->orWhere('is_locked', true);
                })
                ->where('id', '!=', $activePeriod->id)
                ->where('period_name', '!=', $activePeriod->period_name)
                ->orderBy('end_date', 'desc')
                ->get()
                ->map(function ($p) {
                    $sDate = $p->start_date instanceof Carbon ? $p->start_date->toDateString() : (string) $p->start_date;
                    $eDate = $p->end_date instanceof Carbon ? $p->end_date->toDateString() : (string) $p->end_date;
                    $txCount = Transaction::whereBetween('transaction_date', [$sDate, $eDate])->count();

                    return [
                        'id'                   => 'PER-' . Carbon::parse($sDate)->format('Y') . '-' . Carbon::parse($eDate)->format('Y'),
                        'period_id'            => $p->id,
                        'name'                 => $p->period_name,
                        'period_name'          => $p->period_name,
                        'nama_periode'         => $p->period_name,
                        'start_date'           => $sDate,
                        'end_date'             => $eDate,
                        'formatted_start_date' => Carbon::parse($sDate)->format('d F Y'),
                        'formatted_end_date'   => Carbon::parse($eDate)->format('d F Y'),
                        'status'               => 'dikunci',
                        'is_locked'            => true,
                        'is_active'            => false,
                        'total_transactions'   => $txCount,
                        'remaining_days'       => 0,
                        'closed_at'            => $p->closed_at ? $p->closed_at->toIso8601String() : null,
                    ];
                });

            $activePeriodPayload = [
                'id'                   => $activePeriod->id,
                'name'                 => $activePeriod->period_name,
                'period_name'          => $activePeriod->period_name,
                'nama_periode'         => $activePeriod->period_name,
                'start_date'           => Carbon::parse($startDateStr)->format('d F Y'),
                'end_date'             => Carbon::parse($endDateStr)->format('d F Y'),
                'formatted_start_date' => Carbon::parse($startDateStr)->format('d F Y'),
                'formatted_end_date'   => Carbon::parse($endDateStr)->format('d F Y'),
                'raw_start_date'       => $startDateStr,
                'raw_end_date'         => $endDateStr,
                'status'               => 'OPEN',
                'is_locked'            => false,
                'is_active'            => true,
                'total_transactions'   => $totalTransactions,
                'days_remaining'       => $remainingDays,
                'remaining_days'       => $remainingDays,
                'notes'                => $activePeriod->notes,
            ];

            return response()->json([
                'success'        => true,
                'status'         => 'success',
                'message'        => 'Data periode aktif berhasil diambil',
                'active_period'  => [
                    'id'          => $activePeriod->id,
                    'name'        => $activePeriod->period_name,
                    'period_name' => $activePeriod->period_name,
                    'start_date'  => $startDateStr,
                    'end_date'    => $endDateStr,
                    'is_locked'   => false,
                    'is_active'   => true,
                    'status'      => 'open',
                ],
                'locked_periods' => $historyPeriods,
                'data'           => array_merge($activePeriodPayload, [
                    'active_period'          => array_merge($activePeriodPayload, [
                        'status' => 'open',
                    ]),
                    'locked_periods'         => $historyPeriods,
                    'history_locked_periods' => $historyPeriods,
                ])
            ], 200);

        } catch (\Exception $e) {
            Log::error('[PeriodController] Error getActivePeriod: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data periode aktif: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper untuk menemukan entri periode berdasarkan ID numerik maupun string kode seperti PER-2025-2026
     */
    private function resolvePeriod($id)
    {
        if (empty($id)) return null;

        $accPeriod = AccountingPeriod::find($id);
        if (!$accPeriod) {
            $period = Period::find($id);
            if ($period) {
                $accPeriod = AccountingPeriod::where('id', $period->id)
                    ->orWhere('period_name', $period->period_name)
                    ->first();
            }
        }
        if (!$accPeriod && is_string($id)) {
            if (preg_match('/PER-(\d{4})-(\d{4})/', $id, $matches)) {
                $startYear = $matches[1];
                $accPeriod = AccountingPeriod::where('start_date', 'like', $startYear . '%')
                    ->orWhere('period_name', 'like', '%' . $startYear . '%')
                    ->first();
            } else {
                $accPeriod = AccountingPeriod::where('period_name', 'like', '%' . $id . '%')->first();
            }
        }
        return $accPeriod;
    }

    /**
     * Membuka kunci periode secara manual
     * Endpoint: POST /api/manager/periods/{id}/unlock
     */
    public function unlockPeriod(Request $request, $id = null): JsonResponse
    {
        try {
            $periodId = $id ?? $request->route('id') ?? $request->input('id');
            $period = $this->resolvePeriod($periodId);

            if (!$period) {
                return response()->json([
                    'success' => false,
                    'message' => 'Periode tidak ditemukan (ID: ' . $periodId . ')'
                ], 404);
            }

            // Kunci semua periode lain
            AccountingPeriod::query()->update([
                'is_active' => false,
                'is_locked' => true,
                'status'    => 'closed',
                'closed_at' => now(),
            ]);

            Period::query()->update([
                'is_active' => false,
                'is_locked' => true,
                'status'    => 'closed',
                'closed_at' => now(),
            ]);

            // Aktifkan periode yang dipilih
            $period->update([
                'is_active' => true,
                'is_locked' => false,
                'status'    => 'open',
                'closed_at' => null,
                'closed_by' => null,
            ]);

            $pModel = Period::where('id', $period->id)->orWhere('period_name', $period->period_name)->first();
            if ($pModel) {
                $pModel->update([
                    'is_active' => true,
                    'is_locked' => false,
                    'status'    => 'open',
                    'closed_at' => null,
                    'closed_by' => null,
                ]);
            }

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Periode berhasil dibuka kembali',
                'data'    => $period
            ], 200);

        } catch (\Exception $e) {
            Log::error('[PeriodController] Error unlockPeriod: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuka kunci periode: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Menghapus periode akuntansi
     * Endpoint: DELETE /api/manager/periods/{id}
     */
    public function destroy(Request $request, $id = null): JsonResponse
    {
        try {
            $periodId = $id ?? $request->route('id') ?? $request->input('id');
            $period = $this->resolvePeriod($periodId);

            if (!$period) {
                return response()->json([
                    'success' => false,
                    'message' => 'Periode tidak ditemukan (ID: ' . $periodId . ')'
                ], 404);
            }

            $pModel = Period::where('id', $period->id)->orWhere('period_name', $period->period_name)->first();
            if ($pModel) {
                $pModel->delete();
            }
            $period->delete();

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Periode berhasil dihapus'
            ], 200);
        } catch (\Exception $e) {
            Log::error('[PeriodController] Error destroy: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus periode: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Membuka periode baru secara eksplisit (Fix route open-period)
     * Endpoint: POST /api/manager/periods/open-period & POST /api/manager/periods/buka-periode
     * Body: { name, start_date, end_date }
     */
    public function openPeriod(Request $request): JsonResponse
    {
        // Normalisasi input name vs period_name
        if ($request->filled('name') && !$request->filled('period_name')) {
            $request->merge(['period_name' => $request->input('name')]);
        } elseif ($request->filled('period_name') && !$request->filled('name')) {
            $request->merge(['name' => $request->input('period_name')]);
        }

        $request->validate([
            'name'       => 'required|string|max:100',
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        try {
            $sDate = Carbon::parse($request->input('start_date'));
            $eDate = Carbon::parse($request->input('end_date'));

            // Validasi Durasi Periode (Wajib 12 Bulan Baku)
            if (!\App\Services\PeriodClosingService::validatePeriodDuration($sDate, $eDate)) {
                return response()->json([
                    'success' => false,
                    'status'  => 'error',
                    'message' => 'Durasi periode akuntansi harus tepat 12 bulan (1 tahun buku). Contoh: 01 Juni 2026 s/d 31 Mei 2027.',
                    'errors'  => [
                        'end_date' => ['Durasi periode akuntansi harus tepat 12 bulan (1 tahun buku).']
                    ]
                ], 422);
            }

            DB::beginTransaction();

            $periodName = $request->input('name') ?? $request->input('period_name');
            if (empty($periodName)) {
                $periodName = $sDate->locale('id')->isoFormat('MMMM YYYY') . ' - ' . $eDate->locale('id')->isoFormat('MMMM YYYY');
            }

            // Buat atau perbarui periode di tabel periods
            $period = Period::firstOrCreate(
                ['period_name' => $periodName],
                [
                    'start_date'  => $sDate->toDateString(),
                    'end_date'    => $eDate->toDateString(),
                    'status'      => 'open',
                    'is_locked'   => false,
                    'is_active'   => true,
                    'notes'       => $request->input('notes') ?? 'Periode baru dibuka oleh Manajer',
                ]
            );

            $period->update([
                'start_date'  => $sDate->toDateString(),
                'end_date'    => $eDate->toDateString(),
                'status'      => 'open',
                'is_locked'   => false,
                'is_active'   => true,
                'closed_at'   => null,
                'closed_by'   => null,
            ]);

            // Buat atau perbarui periode di tabel accounting_periods
            $newPeriod = AccountingPeriod::firstOrCreate(
                ['period_name' => $periodName],
                [
                    'start_date'  => $sDate->toDateString(),
                    'end_date'    => $eDate->toDateString(),
                    'status'      => 'OPEN',
                    'is_locked'   => false,
                    'is_active'   => true,
                    'notes'       => $request->input('notes') ?? 'Periode baru dibuka oleh Manajer',
                ]
            );

            $newPeriod->update([
                'start_date'  => $sDate->toDateString(),
                'end_date'    => $eDate->toDateString(),
                'status'      => 'OPEN',
                'is_locked'   => false,
                'is_active'   => true,
                'closed_at'   => null,
                'closed_by'   => null,
            ]);

            // Hanya kunci periode LAMA selain periode baru ini:
            AccountingPeriod::where('id', '!=', $newPeriod->id)->update([
                'is_active' => false,
                'is_locked' => true,
                'status'    => 'LOCKED',
                'closed_at' => now(),
            ]);

            Period::where('id', '!=', $period->id)->update([
                'is_active' => false,
                'is_locked' => true,
                'status'    => 'closed',
                'closed_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Periode baru berhasil dibuka!',
                'data'    => [
                    'id'             => $newPeriod->id,
                    'period_id'      => $period->id,
                    'period_name'    => $periodName,
                    'nama_periode'   => $periodName,
                    'start_date'     => $sDate->format('d F Y'),
                    'end_date'       => $eDate->format('d F Y'),
                    'raw_start_date' => $sDate->toDateString(),
                    'raw_end_date'   => $eDate->toDateString(),
                    'status'         => 'OPEN',
                    'is_locked'      => false,
                    'is_active'      => true,
                ]
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PeriodController] Error openPeriod: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuka periode: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Membuat periode akuntansi baru dengan rentang tanggal fleksibel (Custom Start & End Date)
     * Endpoint: POST /api/manager/periods/create & POST /api/manager/periods
     * Body: { name, period_name, start_date, end_date, notes, set_active }
     */
    public function createPeriod(Request $request): JsonResponse
    {
        // Normalisasi input name vs period_name
        if ($request->filled('name') && !$request->filled('period_name')) {
            $request->merge(['period_name' => $request->input('name')]);
        } elseif ($request->filled('period_name') && !$request->filled('name')) {
            $request->merge(['name' => $request->input('period_name')]);
        }

        $request->validate([
            'name'       => 'required|string|max:100',
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
            'status'     => 'nullable|string|in:open,OPEN,closed,CLOSED,LOCKED,locked,terbuka,dikunci',
            'notes'      => 'nullable|string',
            'set_active' => 'nullable|boolean',
        ]);

        try {
            $sDate = Carbon::parse($request->input('start_date'));
            $eDate = Carbon::parse($request->input('end_date'));

            // Validasi Durasi Periode (Wajib 12 Bulan Baku)
            if (!\App\Services\PeriodClosingService::validatePeriodDuration($sDate, $eDate)) {
                return response()->json([
                    'success' => false,
                    'status'  => 'error',
                    'message' => 'Durasi periode akuntansi harus tepat 12 bulan (1 tahun buku). Contoh: 01 Juni 2026 s/d 31 Mei 2027.',
                    'errors'  => [
                        'end_date' => ['Durasi periode akuntansi harus tepat 12 bulan (1 tahun buku).']
                    ]
                ], 422);
            }

            DB::beginTransaction();

            $periodName = $request->input('period_name') ?? $request->input('name');
            if (empty($periodName)) {
                $periodName = $sDate->locale('id')->isoFormat('MMMM YYYY') . ' - ' . $eDate->locale('id')->isoFormat('MMMM YYYY');
            }

            $inputStatus = strtolower($request->input('status') ?? 'open');
            $status = in_array($inputStatus, ['closed', 'locked', 'dikunci']) ? 'closed' : 'open';
            $setActive = $request->input('set_active', true);

            $period = Period::firstOrCreate(
                ['period_name' => $periodName],
                [
                    'start_date'  => $sDate->toDateString(),
                    'end_date'    => $eDate->toDateString(),
                    'status'      => $status,
                    'is_locked'   => ($status !== 'open'),
                    'is_active'   => ($status === 'open'),
                    'notes'       => $request->input('notes') ?? 'Periode Akuntansi Dibuat Kustom',
                ]
            );

            $period->update([
                'start_date'  => $sDate->toDateString(),
                'end_date'    => $eDate->toDateString(),
                'status'      => $status,
                'is_locked'   => ($status !== 'open'),
                'is_active'   => ($status === 'open'),
            ]);

            $accPeriod = AccountingPeriod::firstOrCreate(
                ['period_name' => $periodName],
                [
                    'start_date'  => $sDate->toDateString(),
                    'end_date'    => $eDate->toDateString(),
                    'status'      => $status === 'open' ? 'OPEN' : 'LOCKED',
                    'is_locked'   => ($status !== 'open'),
                    'is_active'   => ($status === 'open'),
                    'notes'       => $request->input('notes') ?? 'Periode Akuntansi Dibuat Kustom',
                ]
            );

            $accPeriod->update([
                'start_date'  => $sDate->toDateString(),
                'end_date'    => $eDate->toDateString(),
                'status'      => $status === 'open' ? 'OPEN' : 'LOCKED',
                'is_locked'   => ($status !== 'open'),
                'is_active'   => ($status === 'open'),
            ]);

            // Jika status open / dijadikan periode aktif, kunci periode selain ini
            if ($status === 'open' && $setActive) {
                Period::where('id', '!=', $period->id)->update([
                    'status'    => 'closed',
                    'is_locked' => true,
                    'is_active' => false,
                    'closed_at' => Carbon::now(),
                ]);
                AccountingPeriod::where('id', '!=', $accPeriod->id)->update([
                    'status'    => 'LOCKED',
                    'is_locked' => true,
                    'is_active' => false,
                    'closed_at' => Carbon::now(),
                ]);
            }

            DB::commit();

            $now = Carbon::now();
            $remainingDays = $now->lt($eDate) ? (int) $now->diffInDays($eDate) : 0;
            $txCount = Transaction::whereBetween('transaction_date', [$sDate->toDateString(), $eDate->toDateString()])->count();

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Periode akuntansi baru berhasil dibuat dengan rentang tanggal fleksibel',
                'data'    => [
                    'id'                 => $period->id,
                    'period_name'        => $period->period_name,
                    'nama_periode'       => $period->period_name,
                    'start_date'         => $sDate->format('d F Y'),
                    'end_date'           => $eDate->format('d F Y'),
                    'raw_start_date'     => $sDate->toDateString(),
                    'raw_end_date'       => $eDate->toDateString(),
                    'status'             => $status === 'open' ? 'OPEN' : 'LOCKED',
                    'is_locked'          => $status !== 'open',
                    'is_active'          => $status === 'open',
                    'total_transactions' => $txCount,
                    'days_remaining'     => $remainingDays,
                    'remaining_days'     => $remainingDays,
                    'notes'              => $period->notes,
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PeriodController] Error createPeriod: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat periode akuntansi: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eksekusi Tutup Buku & Distribusi SHU
     * Endpoint: POST /api/manager/periods/close-period & POST /api/manager/periods/tutup-buku
     */
    public function closePeriod(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }

        // Verifikasi hak akses: hanya Manajer, Ketua, Pengurus, Admin, atau Superadmin yang diizinkan tutup buku
        $allowedRoles = ['manager', 'ketua', 'admin', 'superadmin', 'pengurus'];
        if ($user->role && !in_array(strtolower($user->role), $allowedRoles)) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Aksi tutup buku hanya dapat dilakukan oleh Manajer atau Ketua Koperasi.'
            ], 403);
        }

        DB::beginTransaction();
        try {
            // 1. Ambil Periode Aktif
            $activePeriod = Period::whereIn('status', ['open', 'OPEN', 'terbuka'])->first();

            if (!$activePeriod) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ditemukan periode akuntansi aktif yang dapat ditutup.'
                ], 400);
            }

            // 2. Kunci Periode Aktif (LOCKED)
            $activePeriod->update([
                'status'    => 'closed',
                'is_locked' => true,
                'is_active' => false,
                'closed_by' => $user ? $user->id : null,
                'closed_at' => Carbon::now(),
                'notes'     => $request->input('notes') ?? 'Tutup Buku & Distribusi SHU Selesai',
            ]);

            AccountingPeriod::where('id', $activePeriod->id)
                ->orWhere('period_name', $activePeriod->period_name)
                ->update([
                    'status'    => 'LOCKED',
                    'is_locked' => true,
                    'is_active' => false,
                    'closed_by' => $user ? $user->id : null,
                    'closed_at' => Carbon::now(),
                    'notes'     => $request->input('notes') ?? 'Tutup Buku & Distribusi SHU Selesai',
                ]);

            // 3. Hitung SHU Bersih Koperasi Periode Tersebut
            $startDateStr = $activePeriod->start_date instanceof Carbon ? $activePeriod->start_date->toDateString() : (string) $activePeriod->start_date;
            $endDateStr   = $activePeriod->end_date instanceof Carbon ? $activePeriod->end_date->toDateString() : (string) $activePeriod->end_date;

            $revAccountIds = ChartOfAccount::whereIn('account_code', ['4180', '4170', '4182', '4191'])->pluck('id');
            $expAccountIds = ChartOfAccount::where('account_type', 'EXPENSE')->pluck('id');

            $jRev = (float) JournalDetail::whereIn('account_id', $revAccountIds)
                ->whereHas('journalEntry', function ($q) use ($startDateStr, $endDateStr) {
                    $q->whereBetween('entry_date', [$startDateStr, $endDateStr]);
                })
                ->sum('credit');

            $jExp = (float) JournalDetail::whereIn('account_id', $expAccountIds)
                ->whereHas('journalEntry', function ($q) use ($startDateStr, $endDateStr) {
                    $q->whereBetween('entry_date', [$startDateStr, $endDateStr]);
                })
                ->sum('debit');

            $totalRevenue = $jRev;
            $totalExpense = $jExp;

            if ($totalRevenue == 0) {
                $totalRevenue = (float) Transaction::where('status', 'approved')
                    ->whereBetween('transaction_date', [$startDateStr, $endDateStr])
                    ->where(function ($q) {
                        $q->where('description', 'like', '%jasa pinjaman%')
                          ->orWhere('description', 'like', '%bunga pinjaman%')
                          ->orWhere('description', 'like', '%provisi%')
                          ->orWhere('description', 'like', '%denda keterlambatan%');
                    })
                    ->sum('amount');
            }

            $netShu = max(0.0, $totalRevenue - $totalExpense);
            $shuPercentage = (float) Cache::get('setting_shu_percentage', config('koperasi.shu_percentage', 25.0));
            $alokasiShuPool = (float) round(($shuPercentage / 100) * $netShu, 2);

            // 4. Proses Distribusi SHU ke Seluruh Anggota Penuh (Buku Biru) yang Memenuhi Syarat
            $sixMonthsAgo = Carbon::parse($endDateStr)->subMonths(6)->toDateString();

            $activeMembers = Member::where('status', 'active')
                ->where('has_buku_biru', true)
                ->get()
                ->filter(function ($member) use ($sixMonthsAgo) {
                    // a. Pengecekan Buku Biru & Status Aktif
                    if (!$member->has_buku_biru || $member->status !== 'active') {
                        return false;
                    }

                    // b. Pengecekan Menunggak Cicilan Pinjaman >= 6 Bulan
                    $hasOverdueLoan = Loan::where('member_id', $member->id)
                        ->whereIn('status', ['approved', 'disbursed', 'active'])
                        ->where('due_date', '<=', $sixMonthsAgo)
                        ->where('remaining_amount', '>', 0)
                        ->exists();

                    if ($hasOverdueLoan) {
                        return false;
                    }

                    $hasOverdueInstallment = \App\Models\LoanInstallment::whereHas('loan', function ($q) use ($member) {
                            $q->where('member_id', $member->id);
                        })
                        ->where('status', 'unpaid')
                        ->where('due_date', '<=', $sixMonthsAgo)
                        ->exists();

                    if ($hasOverdueInstallment) {
                        return false;
                    }

                    // c. Pengecekan Menunggak Simpanan Wajib >= 6 Bulan
                    $regDate = $member->created_at ? Carbon::parse($member->created_at) : null;
                    if ($regDate && $regDate->lt(Carbon::parse($sixMonthsAgo))) {
                        $hasRecentDeposit = Transaction::where('member_id', $member->id)
                            ->where('status', 'approved')
                            ->where('type', 'deposit')
                            ->where('transaction_date', '>=', $sixMonthsAgo)
                            ->exists();

                        if (!$hasRecentDeposit && ($member->mandatory_savings ?? 0) <= 20000) {
                            return false;
                        }
                    }

                    $saham = (float) (($member->principal_savings ?? 0) + ($member->mandatory_savings ?? 0));
                    return $saham > 0;
                });

            $totalSahamKoperasi = $activeMembers
                ->sum(function ($m) {
                    return (float) (($m->principal_savings ?? 0) + ($m->mandatory_savings ?? 0));
                }) ?: 1;

            $totalDistributed = 0.0;
            $processedCount = 0;

            Transaction::withoutPeriodLock(function () use (
                $activeMembers,
                $activePeriod,
                $totalSahamKoperasi,
                $alokasiShuPool,
                $user,
                &$totalDistributed,
                &$processedCount
            ) {
                foreach ($activeMembers as $member) {
                    $sahamAnggota = (float) (($member->principal_savings ?? 0) + ($member->mandatory_savings ?? 0));
                    
                    // Jasa Saham = (Saham Anggota / Total Saham Koperasi) * Alokasi SHU Pool
                    $jasaSaham = $totalSahamKoperasi > 0 ? round(($sahamAnggota / $totalSahamKoperasi) * $alokasiShuPool, 2) : 0;
                    $deviden = $jasaSaham; // Deviden sebanding dengan jasa modal saham
                    $grossShu = $jasaSaham;

                    // Potongan Standar AD/ART (Contoh: Dana Kematian / Duka Rp 20.000)
                    $potonganDuka = $grossShu > 20000 ? 20000.00 : 0.00;
                    $potonganWajib = 0.00;
                    $totalPotongan = $potonganDuka + $potonganWajib;
                    $netShuMember = max(0.0, $grossShu - $totalPotongan);

                    // Catat Detail Distribusi Anggota
                    MemberShuDistribution::create([
                        'period_id'      => $activePeriod->id,
                        'member_id'      => $member->id,
                        'jasa_saham'     => $jasaSaham,
                        'deviden'        => $deviden,
                        'gross_shu'      => $grossShu,
                        'potongan_duka'  => $potonganDuka,
                        'potongan_wajib' => $potonganWajib,
                        'total_potongan' => $totalPotongan,
                        'net_shu'        => $netShuMember,
                        'status'         => 'distributed',
                        'distributed_at' => Carbon::now(),
                    ]);

                    \App\Models\ShuDistribution::create([
                        'period_id'       => $activePeriod->id,
                        'period_name'     => $activePeriod->period_name,
                        'member_id'       => $member->id,
                        'member_name'     => $member->name,
                        'member_number'   => $member->member_number,
                        'simpanan_pokok'  => (float) ($member->principal_savings ?? 0),
                        'simpanan_wajib'  => (float) ($member->mandatory_savings ?? 0),
                        'total_saham'     => $sahamAnggota,
                        'jasa_saham'      => $jasaSaham,
                        'deviden'         => $deviden,
                        'gross_shu'       => $grossShu,
                        'potongan_duka'   => $potonganDuka,
                        'potongan_wajib'  => $potonganWajib,
                        'total_potongan'  => $totalPotongan,
                        'net_shu'         => $netShuMember,
                        'status'          => 'distributed',
                        'distributed_at'  => Carbon::now(),
                        'notes'           => "Distribusi SHU Periode {$activePeriod->period_name}",
                    ]);

                    // Jika SHU bersih > 0, kreditkan ke saldo Simpanan Sukarela anggota
                    if ($netShuMember > 0) {
                        $trxNumber = 'KM-SHU-' . date('Ymd') . '-' . str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                        Transaction::create([
                            'transaction_number' => $trxNumber,
                            'receipt_number'     => 'SHU-' . $activePeriod->id . '-' . $member->id,
                            'member_id'          => $member->id,
                            'book_type'          => 'BUKU_BIRU',
                            'operator_id'        => $user ? $user->id : null,
                            'approved_by'        => $user ? $user->id : null,
                            'type'               => 'deposit',
                            'amount'             => $netShuMember,
                            'beginning_balance'  => (float) ($member->voluntary_savings ?? 0),
                            'ending_balance'     => (float) (($member->voluntary_savings ?? 0) + $netShuMember),
                            'payment_method'     => 'cash',
                            'transaction_date'   => Carbon::now()->toDateString(),
                            'description'        => "Pembagian SHU Periode {$activePeriod->period_name}",
                            'status'             => 'approved',
                            'approved_at'        => Carbon::now(),
                        ]);

                        $member->increment('voluntary_savings', $netShuMember);
                        $totalDistributed += $netShuMember;
                    }

                    $processedCount++;
                }
            });

            // 5. Buka Periode Baru Tanpa Duplikasi
            $prevEndDate   = Carbon::parse($endDateStr);
            $nextStartDate = $prevEndDate->copy()->addDay();
            $nextEndDate   = $nextStartDate->copy()->addYear()->subDay();

            $startMonthName = $nextStartDate->locale('id')->isoFormat('MMMM YYYY');
            $endMonthName   = $nextEndDate->locale('id')->isoFormat('MMMM YYYY');
            $nextPeriodName = "{$startMonthName} - {$endMonthName}";

            $newPeriod = Period::firstOrCreate(
                ['period_name' => $nextPeriodName],
                [
                    'start_date' => $nextStartDate->toDateString(),
                    'end_date'   => $nextEndDate->toDateString(),
                    'status'     => 'open',
                    'is_locked'  => false,
                    'is_active'  => true,
                    'notes'      => 'Periode baru dibuka otomatis setelah Tutup Buku',
                ]
            );

            $newPeriod->update([
                'start_date' => $nextStartDate->toDateString(),
                'end_date'   => $nextEndDate->toDateString(),
                'status'     => 'open',
                'is_locked'  => false,
                'is_active'  => true,
                'closed_at'  => null,
                'closed_by'  => null,
            ]);

            $newAccPeriod = AccountingPeriod::firstOrCreate(
                ['period_name' => $nextPeriodName],
                [
                    'start_date' => $nextStartDate->toDateString(),
                    'end_date'   => $nextEndDate->toDateString(),
                    'status'     => 'OPEN',
                    'is_locked'  => false,
                    'is_active'  => true,
                    'notes'      => 'Periode baru dibuka otomatis setelah Tutup Buku',
                ]
            );

            $newAccPeriod->update([
                'start_date' => $nextStartDate->toDateString(),
                'end_date'   => $nextEndDate->toDateString(),
                'status'     => 'OPEN',
                'is_locked'  => false,
                'is_active'  => true,
                'closed_at'  => null,
                'closed_by'  => null,
            ]);

            // Kunci semua periode lama selain periode baru yang aktif
            Period::where('id', '!=', $newPeriod->id)->update([
                'status'    => 'closed',
                'is_locked' => true,
                'is_active' => false,
            ]);

            AccountingPeriod::where('id', '!=', $newAccPeriod->id)->update([
                'status'    => 'LOCKED',
                'is_locked' => true,
                'is_active' => false,
            ]);

            // 6. Rollover Saldo Simpanan Anggota (SP, SW, SS) ke Periode Baru
            $rolloverResult = \App\Services\PeriodClosingService::rolloverMemberSavings($activePeriod, $newPeriod, $user);

            DB::commit();

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Periode akuntansi berhasil ditutup, SHU didistribusikan, dan periode baru telah dibuka.',
                'data'    => [
                    'closed_period' => [
                        'id'          => $activePeriod->id,
                        'period_name' => $activePeriod->period_name,
                        'status'      => 'LOCKED',
                        'closed_at'   => $activePeriod->closed_at->toIso8601String(),
                    ],
                    'new_period' => [
                        'id'          => $newAccPeriod->id,
                        'period_name' => $newAccPeriod->period_name,
                        'start_date'  => $newPeriod->start_date->format('d F Y'),
                        'end_date'    => $newPeriod->end_date->format('d F Y'),
                        'status'      => 'OPEN',
                        'is_locked'   => false,
                        'is_active'   => true,
                    ],
                    'distribution_summary' => [
                        'total_members_processed' => $processedCount,
                        'total_shu_distributed'   => $totalDistributed,
                        'alokasi_shu_pool'        => $alokasiShuPool,
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PeriodController] Gagal tutup buku: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengeksekusi tutup buku: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Riwayat Periode Akuntansi
     * Endpoint: GET /api/manager/periods/history & GET /api/manager/periods
     */
    public function history(Request $request): JsonResponse
    {
        $periods = Period::orderBy('end_date', 'desc')->get();

        return response()->json([
            'success' => true,
            'status'  => 'success',
            'data'    => $periods
        ], 200);
    }

    /**
     * Mengambil riwayat distribusi SHU untuk anggota tertentu
     * Endpoint: GET /api/members/{id}/shu-history & GET /api/member/shu-history
     */
    public function getMemberShuHistory(Request $request, $id = null): JsonResponse
    {
        $memberId = $id;
        if (!$memberId) {
            $user = $request->user();
            if ($user instanceof Member) {
                $memberId = $user->id;
            } else {
                $memberId = Member::where('user_id', $user?->id)->value('id');
            }
        }

        if (!$memberId) {
            return response()->json([
                'success' => false,
                'message' => 'Anggota tidak ditemukan'
            ], 404);
        }

        $distributions = \App\Models\ShuDistribution::where('member_id', $memberId)
            ->orderBy('distributed_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'status'  => 'success',
            'data'    => $distributions
        ], 200);
    }

    /**
     * Inisialisasi data periode awal jika database masih kosong
     */
    private function initializeDefaultPeriods(): void
    {
        // 1. Periode Arsip 2023 - 2024 (Locked)
        Period::firstOrCreate(
            ['period_name' => 'Juni 2023 - Mei 2024'],
            [
                'start_date' => '2023-06-01',
                'end_date'   => '2024-05-31',
                'status'     => 'closed',
                'is_locked'  => true,
                'is_active'  => false,
                'closed_at'  => '2024-05-31 23:59:59',
                'notes'      => 'Arsip Tutup Buku 2023-2024',
            ]
        );

        AccountingPeriod::firstOrCreate(
            ['period_name' => 'Juni 2023 - Mei 2024'],
            [
                'start_date' => '2023-06-01',
                'end_date'   => '2024-05-31',
                'status'     => 'LOCKED',
                'is_locked'  => true,
                'is_active'  => false,
                'closed_at'  => '2024-05-31 23:59:59',
                'notes'      => 'Arsip Tutup Buku 2023-2024',
            ]
        );

        // 2. Periode Arsip 2024 - 2025 (Locked)
        Period::firstOrCreate(
            ['period_name' => 'Juni 2024 - Mei 2025'],
            [
                'start_date' => '2024-06-01',
                'end_date'   => '2025-05-31',
                'status'     => 'closed',
                'is_locked'  => true,
                'is_active'  => false,
                'closed_at'  => '2025-05-31 23:59:59',
                'notes'      => 'Arsip Tutup Buku 2024-2025',
            ]
        );

        AccountingPeriod::firstOrCreate(
            ['period_name' => 'Juni 2024 - Mei 2025'],
            [
                'start_date' => '2024-06-01',
                'end_date'   => '2025-05-31',
                'status'     => 'LOCKED',
                'is_locked'  => true,
                'is_active'  => false,
                'closed_at'  => '2025-05-31 23:59:59',
                'notes'      => 'Arsip Tutup Buku 2024-2025',
            ]
        );

        // 3. Periode Berjalan 2026 - 2027 (Active/Open)
        Period::firstOrCreate(
            ['period_name' => 'Juni 2026 - Mei 2027'],
            [
                'start_date' => '2026-06-01',
                'end_date'   => '2027-05-31',
                'status'     => 'open',
                'is_locked'  => false,
                'is_active'  => true,
                'notes'      => 'Tahun Buku Berjalan 2026 - 2027',
            ]
        );

        AccountingPeriod::firstOrCreate(
            ['period_name' => 'Juni 2026 - Mei 2027'],
            [
                'start_date' => '2026-06-01',
                'end_date'   => '2027-05-31',
                'status'     => 'OPEN',
                'is_locked'  => false,
                'is_active'  => true,
                'notes'      => 'Tahun Buku Berjalan 2026 - 2027',
            ]
        );
    }
}