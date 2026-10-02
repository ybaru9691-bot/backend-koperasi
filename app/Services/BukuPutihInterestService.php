<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Transaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BukuPutihInterestService
{
    public const INTEREST_RATE = 0.006; // 0.6% per bulan

    /**
     * Resolusi parameter tanggal siklus bunga Buku Putih
     */
    public function resolveDates(int $month, int $year): array
    {
        $currentMonthObj = Carbon::createFromDate($year, $month, 1);
        $executionDate   = Carbon::createFromDate($year, $month, 20)->toDateString();
        // Batas Cut-Off Patokan Tanggal 20 pada bulan periode berjalan pukul 23:59:59
        $cutoffDate      = Carbon::createFromDate($year, $month, 20)->toDateString();
        $sixMonthsStart  = Carbon::parse($cutoffDate)->subMonths(6)->toDateString();
        $periodLabel     = $currentMonthObj->locale('id')->isoFormat('MMMM YYYY');
        $targetYm        = $currentMonthObj->format('Ym');
        $voucherNumber   = "BM-INT-{$targetYm}";

        return [
            'month'            => $month,
            'year'             => $year,
            'execution_date'   => $executionDate,
            'cutoff_date'      => $cutoffDate,
            'six_months_start' => $sixMonthsStart,
            'period_label'     => $periodLabel,
            'target_ym'        => $targetYm,
            'voucher_number'   => $voucherNumber,
        ];
    }

    /**
     * Mengecek apakah bunga untuk periode Ym sudah pernah dibagikan ke database
     */
    public function isAlreadyDistributed(string $targetYm, int $month, int $year): bool
    {
        return Transaction::where(function ($q) use ($targetYm, $month, $year) {
            $q->where('receipt_number', 'like', 'INT-' . $targetYm . '-%')
              ->orWhere('receipt_number', 'like', 'BM-INT-' . $targetYm . '-%')
              ->orWhere(function ($sub) use ($month, $year) {
                  $sub->where('description', 'like', '%Bunga%Buku Putih%')
                      ->where('book_type', 'BUKU_PUTIH')
                      ->whereMonth('transaction_date', $month)
                      ->whereYear('transaction_date', $year);
              });
        })->exists();
    }

    /**
     * Hitung kalkulasi preview bunga untuk seluruh anggota tanpa melakukan mutasi ke DB
     */
    public function preview(int $month, int $year): array
    {
        $dates = $this->resolveDates($month, $year);
        $targetYm        = $dates['target_ym'];
        $cutoffDate      = $dates['cutoff_date'];
        $sixMonthsStart  = $dates['six_months_start'];
        $alreadyDistributed = $this->isAlreadyDistributed($targetYm, $month, $year);

        // Ambil semua anggota terdaftar
        $members = Member::orderBy('member_number', 'asc')->get();

        // 1. Pastikan mutasi saldo awal tercatat di tabel transactions jika member memiliki daily_savings > 0
        $existingPutihTxMemberIds = DB::table('transactions')
            ->where('book_type', 'BUKU_PUTIH')
            ->where('status', 'approved')
            ->whereNotNull('member_id')
            ->pluck('member_id')
            ->filter(fn($id) => !is_null($id) && (is_string($id) || is_int($id)))
            ->unique()
            ->flip()
            ->all();

        $accountKas = null;
        foreach ($members as $m) {
            $mDaily = (float) ($m->daily_savings ?? 0.0);
            if ($mDaily > 0 && !isset($existingPutihTxMemberIds[$m->id])) {
                if (!$accountKas) {
                    $accountKas = \App\Models\Account::firstOrCreate(
                        ['account_number' => 'KAS-101'],
                        ['account_name' => 'Kas Koperasi', 'account_type' => 'kas', 'category' => 'asset', 'balance' => 0.00]
                    );
                }
                $initTxDate = $m->created_at ? Carbon::parse($m->created_at)->toDateString() : $dates['cutoff_date'];
                if ($initTxDate > $dates['cutoff_date']) {
                    $initTxDate = $dates['cutoff_date'];
                }
                Transaction::create([
                    'transaction_number' => 'TRX-BP-INIT-' . $m->id . '-' . mt_rand(1000, 9999),
                    'receipt_number'     => 'KM-BP-' . $m->id,
                    'member_id'          => $m->id,
                    'account_id'         => $accountKas->id,
                    'book_type'          => 'BUKU_PUTIH',
                    'type'               => 'deposit',
                    'amount'             => $mDaily,
                    'beginning_balance'  => 0.00,
                    'ending_balance'     => $mDaily,
                    'payment_method'     => 'cash',
                    'transaction_date'   => $initTxDate,
                    'description'        => "Saldo Awal Simpanan Harian (Buku Putih) - {$m->name}",
                    'status'             => 'approved',
                    'approved_at'        => now(),
                ]);
                $existingPutihTxMemberIds[$m->id] = true;
            }
        }

        // Optimasi: Dapatkan mutasi transaksi yang terjadi SETELAH cut-off date (tanggal 20) untuk menghitung saldo cut-off secara akurat.
        // PENTING: Transaksi "Saldo Awal" hasil migrasi data historis DIKECUALIKAN dari query ini meskipun transaction_date-nya
        // jatuh setelah cut-off (karena tanggal import ≠ tanggal efektif saldo historis mengendap).
        // Penanda Saldo Awal migrasi: description LIKE '%Saldo Awal%', transaction_number LIKE 'TRX-IMP-%-PUT-%' /
        // 'TRX-BP-INIT-%', atau receipt_number LIKE 'KM-IMP-P-%' / 'KM-BP-%'.
        $afterCutoffDeposits = DB::table('transactions')
            ->where('book_type', 'BUKU_PUTIH')
            ->where('status', 'approved')
            ->whereNotNull('member_id')
            ->whereIn('type', ['deposit', 'in', 'kas_masuk'])
            ->whereDate('transaction_date', '>', $cutoffDate)
            // Kecualikan transaksi Saldo Awal migrasi — bukan setoran kasir baru
            ->where(function ($q) {
                $q->whereNull('description')
                  ->orWhere(function ($sub) {
                      $sub->where('description', 'not like', '%Saldo Awal%')
                          ->where('description', 'not like', '%saldo awal%');
                  });
            })
            ->where(function ($q) {
                $q->whereNull('transaction_number')
                  ->orWhere(function ($sub) {
                      $sub->where('transaction_number', 'not like', 'TRX-IMP-%-PUT-%')
                          ->where('transaction_number', 'not like', 'TRX-BP-INIT-%');
                  });
            })
            ->where(function ($q) {
                $q->whereNull('receipt_number')
                  ->orWhere(function ($sub) {
                      $sub->where('receipt_number', 'not like', 'KM-IMP-P-%')
                          ->where('receipt_number', 'not like', 'KM-BP-%');
                  });
            })
            ->selectRaw('member_id, SUM(amount) as total')
            ->groupBy('member_id')
            ->pluck('total', 'member_id')
            ->all();

        $afterCutoffWithdrawals = DB::table('transactions')
            ->where('book_type', 'BUKU_PUTIH')
            ->where('status', 'approved')
            ->whereNotNull('member_id')
            ->whereIn('type', ['withdrawal', 'out', 'kas_keluar'])
            ->whereDate('transaction_date', '>', $cutoffDate)
            ->selectRaw('member_id, SUM(amount) as total')
            ->groupBy('member_id')
            ->pluck('total', 'member_id')
            ->all();

        // Optimasi: Dapatkan daftar member_id yang memiliki mutasi transaksi dalam kurun waktu 6 bulan terakhir s.d. cut-off date
        $activeInSixMonths = DB::table('transactions')
            ->where('status', 'approved')
            ->whereNotNull('member_id')
            ->whereDate('transaction_date', '>=', $sixMonthsStart)
            ->whereDate('transaction_date', '<=', $cutoffDate)
            ->pluck('member_id')
            ->filter(fn($id) => !is_null($id) && (is_string($id) || is_int($id)))
            ->unique()
            ->flip()
            ->all();

        // Dapatkan member_id yang memiliki transaksi Saldo Awal migrasi yang dibuat/di-import sejak $sixMonthsStart
        // (termasuk yang di-import setelah cut-off date dengan tanggal import/efektif >= $sixMonthsStart).
        $recentMigrationMemberIds = DB::table('transactions')
            ->where('book_type', 'BUKU_PUTIH')
            ->where('status', 'approved')
            ->whereNotNull('member_id')
            ->whereDate('transaction_date', '>=', $sixMonthsStart)
            ->where(function ($q) {
                $q->where('description', 'like', '%Saldo Awal%')
                  ->orWhere('description', 'like', '%saldo awal%')
                  ->orWhere('receipt_number', 'like', 'KM-IMP-P-%')
                  ->orWhere('receipt_number', 'like', 'KM-BP-%')
                  ->orWhere('transaction_number', 'like', 'TRX-IMP-%-PUT-%')
                  ->orWhere('transaction_number', 'like', 'TRX-BP-INIT-%');
            })
            ->pluck('member_id')
            ->filter(fn($id) => !is_null($id) && (is_string($id) || is_int($id)))
            ->unique()
            ->flip()
            ->all();

        $memberCalculations = [];
        $totalAllCutoffBalance = 0.0;
        $totalEligibleCutoffBalance = 0.0;
        $totalInterestAmount = 0.0;
        $eligibleCount = 0;
        $dormantCount = 0;
        $zeroBalanceCount = 0;

        foreach ($members as $member) {
            // HANYA ambil saldo dari Simpanan Harian (Buku Putih) - Akun 2021
            $currentDailySavings = (float) ($member->daily_savings ?? 0.0);
            $depAfter = (float) ($afterCutoffDeposits[$member->id] ?? 0.0);
            $withAfter = (float) ($afterCutoffWithdrawals[$member->id] ?? 0.0);

            // Saldo cut-off Simpanan Harian per batas tanggal 20.
            // Transaksi Saldo Awal migrasi sudah dikecualikan dari afterCutoffDeposits,
            // sehingga daily_savings langsung mencerminkan saldo historis yang mengendap sejak sebelum cut-off.
            $cutoffBalance = max(0.0, $currentDailySavings - $depAfter + $withAfter);

            $rawStatus = strtolower(trim((string) ($member->status ?? '')));
            $inactiveStatuses = [
                'inactive', 'non-active', 'pasif', 'keluar', 'resigned', 'blokir', '0',
                'tidak_aktif', 'tidak-aktif', 'non_aktif', 'nonaktif'
            ];
            $isExplicitlyInactive = in_array($rawStatus, $inactiveStatuses, true);

            // Cek Status Keaktifan Rekening Buku Putih Khusus
            $isWhiteBookDisabled = ($member->is_white_book_active === false || $member->is_white_book_active === 0 || $member->is_white_book_active === '0');

            // Pengecekan riwayat mutasi transaksi 6 bulan terakhir s.d. cut-off date.
            $hasTxInWindow = isset($activeInSixMonths[$member->id])
                || isset($recentMigrationMemberIds[$member->id])
                || ($member->created_at && Carbon::parse($member->created_at)->toDateString() >= $sixMonthsStart && Carbon::parse($member->created_at)->toDateString() <= $cutoffDate);
            $isDormant = $isExplicitlyInactive || $isWhiteBookDisabled || !$hasTxInWindow;

            if ($isDormant) {
                $dormantCount++;
            }

            if ($cutoffBalance <= 0) {
                $zeroBalanceCount++;
            }

            // Kategori "Anggota Berhak Bunga" HANYA untuk anggota yang:
            // 1. Status TIDAK eksplisit non-aktif (!isExplicitlyInactive)
            // 2. Status Rekening Buku Putih AKTIF (!isWhiteBookDisabled)
            // 3. Memiliki mutasi transaksi dalam 6 bulan terakhir (!isDormant)
            // 4. Saldo Simpanan Harian (Buku Putih) > 0 per tanggal cut-off
            $isEligible = (!$isExplicitlyInactive && !$isWhiteBookDisabled && !$isDormant && $cutoffBalance > 0);
            $interest = $isEligible ? (float) round($cutoffBalance * self::INTEREST_RATE) : 0.0;

            if ($isEligible && $interest > 0) {
                $eligibleCount++;
                $totalInterestAmount += $interest;
                $totalEligibleCutoffBalance += $cutoffBalance;
            }

            $totalAllCutoffBalance += $cutoffBalance;

            $bukuPutihNo = $member->buku_putih_no 
                ?: ($member->member_number ? '2021-' . str_pad((string) $member->member_number, 4, '0', STR_PAD_LEFT) : null);

            $memberCalculations[] = [
                'member_id'            => $member->id,
                'member_number'        => $member->member_number,
                'buku_putih_no'        => $bukuPutihNo,
                'name'                 => $member->name,
                'nik'                  => $member->nik,
                'status'               => $member->status,
                'is_white_book_active' => !$isWhiteBookDisabled,
                'white_book_active'    => !$isWhiteBookDisabled,
                'has_buku_putih'       => (bool) ($member->has_buku_putih || $cutoffBalance > 0),
                'daily_savings'        => $currentDailySavings,
                'current_balance'      => $currentDailySavings,
                'cutoff_balance'       => $cutoffBalance,
                'interest_rate'        => self::INTEREST_RATE,
                'interest_amount'      => $interest,
                'interest'             => $interest,
                'is_dormant'           => $isDormant,
                'is_eligible'          => ($isEligible && $interest > 0),
            ];
        }

        $passiveCount = count($members) - $eligibleCount;

        $now = now();
        $isCutoffReached = ($year < $now->year)
            || ($year == $now->year && $month < $now->month)
            || ($year == $now->year && $month == $now->month && $now->day >= 20);

        $monthName = Carbon::createFromDate($year, $month, 1)->locale('id')->isoFormat('MMMM');
        $cutoffStatus = $isCutoffReached
            ? 'Sudah Cut-Off'
            : 'Menunggu Cut-Off 20 ' . $monthName;

        $canDistribute = $isCutoffReached && !$alreadyDistributed && !\App\Services\PeriodLockService::isLocked($dates['execution_date']);

        $warningMessage = (!$isCutoffReached)
            ? "Distribusi bunga periode berjalan belum dapat dilakukan sebelum tanggal cut-off (tanggal 20)."
            : null;

        $summary = [
            'month'                  => $month,
            'year'                   => $year,
            'period'                 => Carbon::parse($dates['execution_date'])->locale('id')->isoFormat('D MMMM YYYY'),
            'period_label'           => $dates['period_label'],
            'execution_date'         => $dates['execution_date'],
            'cutoff_date'            => $dates['cutoff_date'],
            'cutoff_label'           => 'Transaksi s.d. ' . Carbon::parse($dates['cutoff_date'])->locale('id')->isoFormat('D MMMM YYYY'),
            'cutoff_text'            => 'Transaksi s.d. ' . Carbon::parse($dates['cutoff_date'])->locale('id')->isoFormat('D MMMM YYYY'),
            'six_months_start'       => $dates['six_months_start'],
            'voucher_number'         => $dates['voucher_number'],
            'total_members'          => count($members),
            'total_count'            => count($members),
            'eligible_members'       => $eligibleCount,
            'eligible_count'         => $eligibleCount,
            'dormant_members'        => $dormantCount,
            'passive_members'        => $passiveCount,
            'passive_count'          => $passiveCount,
            'ineligible_members'     => $passiveCount,
            'ineligible_count'       => $passiveCount,
            'bunga_nol_members'      => $passiveCount,
            'zero_balance_members'   => $zeroBalanceCount,
            'total_cutoff_balance'   => $totalAllCutoffBalance,
            'eligible_cutoff_balance'=> $totalEligibleCutoffBalance,
            'total_interest'         => $totalInterestAmount,
            'total_interest_amount'  => $totalInterestAmount,
            'is_already_distributed' => $alreadyDistributed,
            'is_cutoff_reached'      => $isCutoffReached,
            'can_distribute'         => $canDistribute,
            'cutoff_status'          => $cutoffStatus,
            'warning_message'        => $warningMessage,
        ];

        return array_merge($summary, [
            'summary' => $summary,
            'members' => $memberCalculations,
            'details' => $memberCalculations,
        ]);
    }

    /**
     * Eksekusi pendistribusian bunga ke database dan pencatatan Jurnal Bukti Memorial
     */
    public function distribute(int $month, int $year, ?int $userId = null): array
    {
        $now = now();
        $isCurrentMonthPremature = ($year == $now->year && $month == $now->month && $now->day < 20);
        $isFuturePeriod = ($year > $now->year) || ($year == $now->year && $month > $now->month);

        if ($isCurrentMonthPremature || $isFuturePeriod) {
            throw new \Exception("Distribusi bunga periode berjalan belum dapat dilakukan sebelum tanggal cut-off (tanggal 20).", 422);
        }

        $dates = $this->resolveDates($month, $year);
        $executionDate = $dates['execution_date'];

        // 1. Validasi Periode Terkunci
        if (\App\Services\PeriodLockService::isLocked($executionDate)) {
            throw new \Exception("Aksi ditolak: Periode akuntansi tanggal {$executionDate} sudah ditutup / terkunci.");
        }

        // 2. Cegah Duplikasi
        if ($this->isAlreadyDistributed($dates['target_ym'], $month, $year)) {
            throw new \Exception("Bunga Buku Putih untuk periode {$dates['period_label']} sudah pernah dibagikan sebelumnya.");
        }

        $previewData = $this->preview($month, $year);
        $summary = $previewData['summary'];
        $membersData = $previewData['members'];
        $totalInterest = (float) $summary['total_interest_amount'];

        if ($totalInterest <= 0) {
            return [
                'success'           => true,
                'message'           => "Tidak ada bunga yang perlu dibagikan untuk periode {$dates['period_label']} (Total Bunga Rp 0).",
                'summary'           => $summary,
                'processed_count'   => 0,
                'total_interest'    => 0.0,
            ];
        }

        return DB::transaction(function () use ($dates, $summary, $membersData, $totalInterest, $userId, $executionDate) {
            $voucherNumber = $dates['voucher_number'];
            $targetYm = $dates['target_ym'];
            $processedCount = 0;

            // Mutasi Transaksi ke Rekening Simpanan Anggota yang berhak (interest > 0)
            // Catatan: Transaksi ini BUKAN transaksi kas dan TIDAK posting ke Jurnal Umum / Buku Besar.
            // Transaksi ini mutasi memorial internal pada kartu simpanan anggota.
            foreach ($membersData as $item) {
                if ($item['interest_amount'] <= 0) {
                    // Anggota dengan bunga Rp 0 DILEWATI dari mutasi database
                    continue;
                }

                $member = Member::find($item['member_id']);
                if (!$member) {
                    continue;
                }

                $interestAmount = (float) $item['interest_amount'];
                $beginBal = (float) ($member->daily_savings ?? 0);
                $endBal = $beginBal + $interestAmount;

                $trxNumber = 'TRX-INT-' . $targetYm . '-' . str_pad((string) $member->id, 4, '0', STR_PAD_LEFT);
                $receiptNumber = 'BM-INT-' . $targetYm . '-' . $member->id;

                Transaction::create([
                    'transaction_number' => $trxNumber,
                    'receipt_number'     => $receiptNumber,
                    'member_id'          => $member->id,
                    'account_id'         => null,
                    'book_type'          => 'BUKU_PUTIH',
                    'operator_id'        => $userId,
                    'approved_by'        => $userId,
                    'type'               => 'deposit',
                    'category'           => 'bunga_simpanan',
                    'amount'             => $interestAmount,
                    'beginning_balance'  => $beginBal,
                    'ending_balance'     => $endBal,
                    'payment_method'     => 'memorial',
                    'transaction_date'   => $executionDate,
                    'description'        => 'Bunga Simpanan Buku Putih (0.6%) Periode ' . $dates['period_label'],
                    'status'             => 'approved',
                    'approved_at'        => now(),
                ]);

                // Tambahkan saldo rekening Simpanan Harian (Buku Putih) anggota
                $member->increment('daily_savings', $interestAmount);
                $processedCount++;
            }

            return [
                'success'           => true,
                'message'           => "Pembagian Bunga Buku Putih periode {$dates['period_label']} berhasil didistribusikan ke {$processedCount} anggota.",
                'summary'           => array_merge($summary, ['is_already_distributed' => true]),
                'voucher_number'    => $voucherNumber,
                'processed_count'   => $processedCount,
                'total_interest'    => $totalInterest,
            ];
        });
    }

    /**
     * Generate Cetak PDF Bukti Memorial (BM) sesuai standar fisik koperasi
     */
    public function exportPdf(int $month, int $year)
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        $preview = $this->preview($month, $year);
        $summary = $preview['summary'];
        $allMembers = $preview['members'];

        // Filter HANYA anggota yang berhak dan memperoleh nominal bunga > 0 untuk dicetak di rincian Bukti Memorial
        $eligibleMembers = array_values(array_filter($allMembers, function ($m) {
            $amt = (float) ($m['interest_amount'] ?? $m['interest'] ?? 0);
            return $amt > 0;
        }));

        $totalExpense = (float) $summary['total_interest_amount'];

        $viewName = view()->exists('bukti_memorial_buku_putih_pdf')
            ? 'bukti_memorial_buku_putih_pdf'
            : 'exports.bukti_memorial_buku_putih_pdf';

        $pdf = Pdf::loadView($viewName, [
            'summary'      => $summary,
            'members'      => $eligibleMembers,
            'details'      => $eligibleMembers,
            'allMembers'   => $allMembers,
            'totalExpense' => $totalExpense,
        ])
        ->setPaper('a4', 'portrait')
        ->setOption('isHtml5ParserEnabled', true)
        ->setOption('isRemoteEnabled', false);

        $fileName = "Bukti_Memorial_Bunga_Buku_Putih_{$summary['month']}_{$summary['year']}.pdf";

        return $pdf->download($fileName);
    }

    /**
     * Generate Ekspor Excel (.xlsx) Bukti Memorial (BM)
     */
    public function exportExcel(int $month, int $year): StreamedResponse
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        $preview = $this->preview($month, $year);
        $summary = $preview['summary'];
        $allMembers = $preview['members'];

        // Filter HANYA anggota yang berhak dan memperoleh bunga > 0 untuk dicetak di baris rincian Excel
        $eligibleMembers = array_values(array_filter($allMembers, function ($m) {
            $amt = (float) ($m['interest_amount'] ?? $m['interest'] ?? 0);
            return $amt > 0;
        }));

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Bukti Memorial');

        // Styling KOP Koperasi (Yellow & Blue physical aesthetic)
        $sheet->setCellValue('A1', 'KOPERASI CUM PELITA HKBP RESSORT DAME DURI');
        $sheet->setCellValue('A2', 'BUKTI MEMORIAL (BM) - PEMBAGIAN BUNGA SIMPANAN BUKU PUTIH (0,6%)');
        $sheet->setCellValue('A3', 'Periode: ' . $summary['period_label'] . ' | Tanggal Eksekusi: ' . Carbon::parse($summary['execution_date'])->translatedFormat('d F Y'));
        $sheet->setCellValue('A4', 'No. BM: ' . $summary['voucher_number'] . ' | Batas Cut-Off: ' . Carbon::parse($summary['cutoff_date'])->translatedFormat('d F Y'));

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF1E3A8A'));
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A3:A4')->getFont()->setItalic(true)->setSize(10);

        // Header Tabel
        $tableHeaders = ['No', 'No. Perk', 'Nama Perk', 'Keterangan (Nama Anggota)', 'Jumlah Debet (Rp)', 'Jumlah Kredit (Rp)'];
        $sheet->fromArray([$tableHeaders], null, 'A6');

        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => '78350F']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF3C7']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D97706']]],
        ];
        $sheet->getStyle('A6:F6')->applyFromArray($headerStyle);
        $sheet->getRowDimension(6)->setRowHeight(25);

        // Baris Header Jurnal Debet: Beban Bunga (COA 7145 Jasa Simpanan)
        $rowNum = 7;
        $sheet->setCellValue('A' . $rowNum, '1');
        $sheet->setCellValue('B' . $rowNum, '7145');
        $sheet->setCellValue('C' . $rowNum, 'Jasa Simpanan');
        $sheet->setCellValue('D' . $rowNum, 'Beban Bunga Simpanan Harian (0,6%) Periode ' . $summary['period_label']);
        $sheet->setCellValue('E' . $rowNum, (float) $summary['total_interest_amount']);
        $sheet->setCellValue('F' . $rowNum, 0);

        $sheet->getStyle("A{$rowNum}:F{$rowNum}")->getFont()->setBold(true);
        $sheet->getStyle("A{$rowNum}:F{$rowNum}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEF9C3');

        $rowNum++;

        // Baris Anggota Penerima Bunga (Kredit Akun 2021 per anggota)
        $noUrut = 2;
        foreach ($eligibleMembers as $member) {
            $sheet->setCellValue('A' . $rowNum, $noUrut);
            $sheet->setCellValue('B' . $rowNum, '2021');
            $sheet->setCellValue('C' . $rowNum, 'Simpanan Harian');
            $desc = $member['name'];
            if (!empty($member['buku_putih_no'])) {
                $desc .= ' (' . $member['buku_putih_no'] . ')';
            } elseif (!empty($member['member_number'])) {
                $desc .= ' (No: ' . $member['member_number'] . ')';
            }
            $sheet->setCellValue('D' . $rowNum, $desc);
            $sheet->setCellValue('E' . $rowNum, 0);
            $sheet->setCellValue('F' . $rowNum, (float) ($member['interest_amount'] ?? $member['interest'] ?? 0));

            // Format number columns
            $sheet->getStyle("E{$rowNum}:F{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');
            $rowNum++;
            $noUrut++;
        }

        // Baris Total / Balance
        $sheet->setCellValue('A' . $rowNum, 'TOTAL BUKTI MEMORIAL (DEBET & KREDIT SEIMBANG)');
        $sheet->mergeCells("A{$rowNum}:D{$rowNum}");
        $sheet->setCellValue('E' . $rowNum, (float) $summary['total_interest_amount']);
        $sheet->setCellValue('F' . $rowNum, (float) $summary['total_interest_amount']);

        $totalStyle = [
            'font' => ['bold' => true, 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF3C7']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_DOUBLE, 'color' => ['rgb' => '78350F']]],
        ];
        $sheet->getStyle("A{$rowNum}:F{$rowNum}")->applyFromArray($totalStyle);
        $sheet->getStyle("E{$rowNum}:F{$rowNum}")->getNumberFormat()->setFormatCode('#,##0');

        // Border seluruh tabel
        $sheet->getStyle("A6:F{$rowNum}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // Kolom Tanda Tangan Fisik (Hanya Manajer Koperasi)
        $signRow = $rowNum + 3;
        $sheet->setCellValue('F' . $signRow, 'Diketahui,');

        $signRowTitle = $signRow + 1;
        $sheet->setCellValue('F' . $signRowTitle, 'Manajer Koperasi');

        $signRowName = $signRow + 5;
        $sheet->setCellValue('F' . $signRowName, '( ........................................ )');

        $sheet->getStyle("F{$signRow}:F{$signRowName}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Auto width
        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $fileName = "Bukti_Memorial_Bunga_Buku_Putih_{$summary['month']}_{$summary['year']}.xlsx";
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Lembar Tabungan Harian (Buku Putih) 12 Siklus Bulanan (Juni s/d Mei)
     */
    public function getMemberWhiteBookStatement(int $memberId, ?int $fiscalYear = null, ?int $month = null, ?int $year = null): array
    {
        $ledgerService = app(\App\Services\BukuPutihLedgerService::class);
        $data = $ledgerService->getMemberBukuPutihLedger($memberId, null, $fiscalYear, $year);

        $periodLabel = is_array($data['period']) ? ($data['period']['period_label'] ?? '') : ($data['period_info']['period_label'] ?? "Tahun Buku {$data['period']}");
        $endYear = is_array($data['period']) ? ($data['period']['end_year'] ?? $year) : ($data['period_info']['end_year'] ?? $year);

        $isActive = $data['is_active'] ?? true;
        $statusKeaktifan = $data['status_keaktifan'] ?? 'AKTIF';
        $statusLabel = $data['status_label'] ?? ($isActive ? 'AKTIF' : 'TIDAK AKTIF');

        return [
            'member'               => $data['member'],
            'status_label'         => $statusLabel,
            'membership_status'    => $statusLabel,
            'status_keaktifan'     => $statusKeaktifan,
            'is_active'            => $isActive,
            'active_months_count'  => $data['active_months_count'] ?? 0,
            'passive_months_count' => $data['passive_months_count'] ?? 0,
            'fiscal_period_label'  => $periodLabel,
            'fiscal_year'          => $endYear,
            'period'               => $data['period'],
            'period_info'          => $data['period_info'] ?? [],
            'opening_balance'      => $data['opening_balance'],
            'closing_balance'      => $data['closing_balance'],
            'total_deposit'        => $data['total_deposit'],
            'total_withdrawal'     => $data['total_withdrawal'],
            'total_interest'       => $data['total_interest'],
            'cycles'               => $data['cycles'],
            'monthly_records'      => $data['cycles'],
            'rekapitulasi'         => [
                'total_setoran'    => $data['summary']['total_setoran'],
                'total_penarikan'  => $data['summary']['total_penarikan'],
                'total_jasa'       => $data['summary']['total_jasa'],
                'saldo_akhir'      => $data['summary']['saldo_akhir'],
            ],
            'summary'              => $data['summary'],
        ];
    }

    /**
     * Evaluasi Status Keaktifan Anggota Buku Putih (Simpanan Harian)
     * Aturan Bisnis Final:
     * 1. Anggota dikategorikan "TIDAK AKTIF / PASIF" HANYA jika TIDAK ADA transaksi kas (KM/KK) selama >= 6 BULAN BERTURUT-TURUT.
     * 2. Begitu ada transaksi kas riil (KM / KK), hitungan bulan kosong OTOMATIS TER-RESET MENJADI 0 dan status anggota KEMBALI AKTIF.
     * 3. Transaksi bunga memorial (BM) JANGAN dianggap sebagai setoran kas anggota.
     */
    public function evaluateMembershipStatus(array $monthlyCycles): array
    {
        return app(\App\Services\BukuPutihLedgerService::class)->evaluateMembershipStatus($monthlyCycles);
    }

    /**
     * Generate Cetak PDF Lembar Buku Putih Anggota
     */
    public function exportMemberWhiteBookStatementPdf(int $memberId, ?int $fiscalYear = null, ?int $month = null, ?int $year = null)
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        $statement = $this->getMemberWhiteBookStatement($memberId, $fiscalYear, $month, $year);

        $viewName = view()->exists('reports.member_white_book_statement_pdf')
            ? 'reports.member_white_book_statement_pdf'
            : (view()->exists('reports.buku_putih_ledger_pdf') ? 'reports.buku_putih_ledger_pdf' : 'buku_putih_ledger_pdf');

        $pdf = Pdf::loadView($viewName, $statement)
            ->setPaper('a4', 'portrait')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isRemoteEnabled', false);

        $cleanName = preg_replace('/[^A-Za-z0-9_-]/', '_', $statement['member']['name'] ?? 'Anggota');
        $fileName = "Lembar_Buku_Putih_{$cleanName}_{$statement['fiscal_year']}.pdf";

        return $pdf->download($fileName);
    }
}