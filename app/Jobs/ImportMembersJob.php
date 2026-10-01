<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\Member;
use App\Models\Transaction;
use App\Services\JournalService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportMembersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $filePath;
    public ?int $userId;
    public $timeout = 600;
    public $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(string $filePath, ?int $userId = null)
    {
        $this->filePath = $filePath;
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $fullPath = Storage::disk('local')->path($this->filePath);
        if (!file_exists($fullPath)) {
            $fullPath = $this->filePath;
        }

        if (!file_exists($fullPath)) {
            Log::error("[ImportMembersJob] File not found: {$this->filePath}");
            return;
        }

        Log::info("[ImportMembersJob] Starting import from: {$fullPath}");

        try {
            $spreadsheet = IOFactory::load($fullPath);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, false);

            if (empty($rows)) {
                Log::warning("[ImportMembersJob] No data found in file: {$fullPath}");
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                }
                return;
            }

            // Ambil Periode Akuntansi yang OPEN
            $activePeriod = DB::table('periods')
                ->whereIn('status', ['open', 'OPEN', 'terbuka'])
                ->where('is_locked', false)
                ->latest('id')
                ->first() ?? DB::table('accounting_periods')
                ->whereIn('status', ['open', 'OPEN', 'terbuka'])
                ->where('is_locked', false)
                ->latest('id')
                ->first();

            $accountKas = Account::firstOrCreate(
                ['account_number' => 'KAS-101'],
                [
                    'account_name' => 'Kas Koperasi',
                    'account_type' => 'kas',
                    'category'     => 'asset',
                    'balance'      => 0.00,
                ]
            );

            $journalService = app(JournalService::class);

            // Bangun map header jika baris pertama berisi nama kolom
            $headerMap = [];
            if (!empty($rows)) {
                $firstRow = reset($rows);
                foreach ($firstRow as $colIdx => $headerVal) {
                    $cleanKey = strtolower(trim((string)$headerVal));
                    $cleanKey = preg_replace('/[^a-z0-9_]/', '_', $cleanKey);
                    $cleanKey = trim($cleanKey, '_');
                    if (!empty($cleanKey)) {
                        $headerMap[$colIdx] = $cleanKey;
                    }
                }
            }

            $chunks = array_chunk($rows, 100, true);
            $totalImported = 0;

            foreach ($chunks as $chunk) {
                DB::transaction(function () use ($chunk, $headerMap, $activePeriod, $accountKas, $journalService, &$totalImported) {
                    foreach ($chunk as $index => $row) {
                        // Map key header ke baris jika tersedia
                        if (!empty($headerMap) && is_array($row)) {
                            foreach ($headerMap as $colIdx => $colKey) {
                                if (!isset($row[$colKey]) && isset($row[$colIdx])) {
                                    $row[$colKey] = $row[$colIdx];
                                }
                            }
                        }

                        // 1. Lewati jika index 0 dan berupa baris judul / header kolom
                        if ($index === 0) {
                            $nikVal = $row['nik'] ?? $row['no_ktp'] ?? $row[1] ?? null;
                            $firstCol = strtolower(trim((string)($row['member_number'] ?? $row['no_anggota'] ?? $row[0] ?? '')));
                            $secondCol = strtolower(trim((string)($row['nik'] ?? $row['no_ktp'] ?? $row[1] ?? '')));
                            $thirdCol = strtolower(trim((string)($row['name'] ?? $row['nama'] ?? $row[2] ?? '')));

                            if (in_array($firstCol, ['member_number', 'no_anggota', 'no', 'nomor_anggota']) 
                                || in_array($secondCol, ['nik', 'no_ktp', 'ktp']) 
                                || in_array($thirdCol, ['name', 'nama', 'nama_lengkap'])
                                || (!$nikVal || !is_numeric(trim((string)$nikVal)) || strlen(trim((string)$nikVal)) !== 16)) {
                                if (!is_numeric($firstCol) && !is_numeric($secondCol)) {
                                    continue;
                                }
                            }
                        }

                        // 2. Lewati jika header string di baris berikutnya
                        $firstCol = strtolower(trim((string)($row['member_number'] ?? $row['no_anggota'] ?? $row[0] ?? '')));
                        $secondCol = strtolower(trim((string)($row['nik'] ?? $row['no_ktp'] ?? $row[1] ?? '')));
                        if (in_array($firstCol, ['member_number', 'no_anggota', 'no', 'nomor_anggota']) || in_array($secondCol, ['nik', 'no_ktp', 'ktp'])) {
                            continue;
                        }

                        // 3. Lewati baris kosong
                        if (empty(array_filter($row))) {
                            continue;
                        }

                        // 1. Pemetaan Kolom Berdasarkan Header File Excel
                        $memberNumber = trim((string)($row['member_number'] ?? $row['no_anggota'] ?? $row[0] ?? ''));
                        
                        $nikRaw       = $row['nik'] ?? $row['no_ktp'] ?? $row['ktp'] ?? $row[1] ?? '';
                        $nik          = trim((string)$nikRaw);
                        if (is_numeric($nik) && (str_contains(strtolower($nik), 'e+') || str_contains($nik, '.'))) {
                            $nik = sprintf('%.0f', (float)$nik);
                        }

                        $name         = trim((string)($row['name'] ?? $row['nama'] ?? $row['nama_lengkap'] ?? $row[2] ?? ''));
                        if (empty($name)) {
                            continue;
                        }

                        $rawBukuPutih = $row['buku_putih_no'] ?? $row['buku_putih'] ?? $row['no_buku_putih'] ?? $row['nomor_buku_putih'] ?? $row['rekening_buku_putih'] ?? $row[3] ?? null;
                        $bukuPutihNo  = null;
                        if (!is_null($rawBukuPutih)) {
                            $strBP = trim((string) $rawBukuPutih);
                            if (!in_array(strtolower($strBP), ['', '-', '0', 'null', 'none', 'undefined'])) {
                                $bukuPutihNo = $strBP;
                            }
                        }

                        $phoneVal = $row['phone_number'] ?? $row['phone'] ?? $row['no_hp'] ?? $row['handphone'] ?? $row[4] ?? null;
                        $phone    = !empty($phoneVal) ? trim((string)$phoneVal) : '-';

                        $rawStatus = $row['status'] ?? $row[5] ?? 'active';
                        $status    = !empty($rawStatus) ? strtolower(trim((string)$rawStatus)) : 'active';
                        if (!in_array($status, ['active', 'inactive', 'suspended'])) {
                            $status = 'active';
                        }

                        $mandatory = (float)($row['mandatory_savings'] ?? $row['simpanan_wajib'] ?? $row[6] ?? 0);
                        $voluntary = (float)($row['voluntary_savings'] ?? $row['simpanan_sukarela'] ?? $row[7] ?? 0);
                        $principal = (float)($row['principal_savings'] ?? $row['simpanan_pokok'] ?? $row[8] ?? 0);
                        $rawDaily  = $row['daily_savings'] ?? $row['tabungan_harian'] ?? $row['simpanan_harian'] ?? $row[9] ?? 0;
                        $dailySavings = is_numeric($rawDaily) ? (float)$rawDaily : (float)preg_replace('/[^0-9.]/', '', (string)$rawDaily);
                        if ($dailySavings < 0 || empty($dailySavings)) {
                            $dailySavings = 0.00;
                        }

                        // 2. Aturan Khusus Nilai Pinjaman (Opsional / Default 0)
                        $outstandingLoan = (float)($row['outstanding_loan'] ?? $row['pinjaman'] ?? $row['sisa_pinjaman'] ?? $row[10] ?? 0);
                        if ($outstandingLoan < 0 || empty($outstandingLoan)) {
                            $outstandingLoan = 0.00;
                        }

                        // 3. Penanganan Baris Tanpa Nomor Anggota / NIK (Baris Buku Putih Murni)
                        $hasExplicitShares = ($principal > 0 || $mandatory > 0 || $voluntary > 0);
                        $isPureBukuPutih   = ($dailySavings > 0 || !empty($bukuPutihNo)) && ((empty($memberNumber) && empty($nik)) || (!$hasExplicitShares && (empty($memberNumber) || empty($nik))));

                        if ($isPureBukuPutih) {
                            $principal = 0.00;
                            $mandatory = 0.00;
                            $voluntary = 0.00;

                            if (empty($nik)) {
                                $nik = 'TEMP' . str_pad((string)($index + 1), 12, '0', STR_PAD_LEFT);
                                if (Member::where('nik', $nik)->exists()) {
                                    $nik = 'TMP' . str_pad((string)($index + 1), 4, '0', STR_PAD_LEFT) . rand(10000000, 99999999);
                                    $nik = substr($nik, 0, 16);
                                }
                            }
                        } else {
                            if (empty($memberNumber)) {
                                $count = Member::count() + $totalImported + 1;
                                $memberNumber = str_pad((string)$count, 4, '0', STR_PAD_LEFT);
                            }

                            $existingNik = !empty($nik) && Member::where('nik', $nik)->where('member_number', '!=', $memberNumber)->exists();
                            if ($existingNik || empty($nik)) {
                                $cleanNum = preg_replace('/[^0-9A-Za-z]/', '', $memberNumber);
                                $nik = 'TEMP' . str_pad(substr($cleanNum, -12), 12, '0', STR_PAD_LEFT);
                                if (Member::where('nik', $nik)->where('member_number', '!=', $memberNumber)->exists()) {
                                    $nik = 'TMP' . str_pad((string)($index + 1), 5, '0', STR_PAD_LEFT) . rand(10000000, 99999999);
                                    $nik = substr($nik, 0, 16);
                                }
                            }
                        }

                        $emailVal = $row['email'] ?? null;
                        $email    = !empty($emailVal) ? trim((string)$emailVal) : "{$nik}@pelita.local";

                        $hasBukuBiru = ($principal + $mandatory + $voluntary) > 0 || $outstandingLoan > 0;
                        $hasBukuPutih = $dailySavings > 0 || !empty($bukuPutihNo);
                        if (!$hasBukuBiru && !$hasBukuPutih) {
                            $hasBukuBiru = true;
                        }

                        // Cari apakah anggota sudah terdaftar berdasarkan member_number, buku_putih_no, atau NIK
                        $existingMember = null;
                        if (!empty($memberNumber)) {
                            $existingMember = Member::where('member_number', $memberNumber)->first();
                        }
                        if (!$existingMember && !empty($bukuPutihNo)) {
                            $existingMember = Member::where('buku_putih_no', $bukuPutihNo)->first();
                        }
                        if (!$existingMember && !empty($nik) && !str_starts_with($nik, 'TEMP') && !str_starts_with($nik, 'TMP')) {
                            $existingMember = Member::where('nik', $nik)->first();
                        }

                        if ($existingMember) {
                            if (!empty($bukuPutihNo)) $existingMember->buku_putih_no = $bukuPutihNo;
                            if ($dailySavings > 0) $existingMember->daily_savings = $dailySavings;
                            $existingMember->has_buku_putih = $hasBukuPutih || $existingMember->has_buku_putih;
                            $existingMember->has_buku_biru = $hasBukuBiru || $existingMember->has_buku_biru;
                            if ($principal > 0) $existingMember->principal_savings = $principal;
                            if ($mandatory > 0) $existingMember->mandatory_savings = $mandatory;
                            if ($voluntary > 0) $existingMember->voluntary_savings = $voluntary;
                            if (!empty($name)) $existingMember->name = $name;
                            if (!empty($phone) && $phone !== '-') $existingMember->phone = $phone;
                            if (!empty($status)) $existingMember->status = $status;
                            $existingMember->save();
                            $member = $existingMember;
                        } else {
                            $member = Member::create([
                                'member_number'     => !empty($memberNumber) ? $memberNumber : null,
                                'nik'               => $nik,
                                'name'              => $name,
                                'email'             => $email,
                                'phone'             => $phone,
                                'status'            => $status,
                                'principal_savings' => $principal,
                                'mandatory_savings' => $mandatory,
                                'voluntary_savings' => $voluntary,
                                'daily_savings'     => $dailySavings,
                                'buku_putih_no'     => $bukuPutihNo,
                                'has_buku_biru'     => $hasBukuBiru,
                                'has_buku_putih'    => $hasBukuPutih,
                                'password'          => Hash::make('123456'),
                                'pin_code'          => '123456',
                                'place_of_birth'    => '-',
                                'date_of_birth'     => now()->toDateString(),
                                'gender'            => 'Laki-laki',
                                'church_sector'     => 'HKBP Dame Duri',
                                'address'           => '-',
                            ]);
                        }

                        // 1. Outstanding Loan
                        if ($outstandingLoan > 0) {
                            $loanCode = 'LND-INIT-' . $member->id . '-' . mt_rand(100, 999);
                            \App\Models\Loan::create([
                                'member_id'           => $member->id,
                                'loan_code'           => $loanCode,
                                'amount'              => $outstandingLoan,
                                'interest_rate'       => 1.5,
                                'interest_method'     => 'declining_balance',
                                'duration_months'     => 12,
                                'tenor_months'        => 12,
                                'monthly_installment' => ceil($outstandingLoan / 12),
                                'remaining_amount'    => $outstandingLoan,
                                'remaining_principal' => $outstandingLoan,
                                'application_date'    => now()->toDateString(),
                                'status'              => 'active',
                            ]);

                            $trxLoanNumber = sprintf(
                                'TRX-IMP-%s-%s-%s-%s',
                                now()->format('YmdHis'),
                                $member->id,
                                'LON',
                                Str::upper(Str::random(5))
                            );
                            $baseReceipt = $row['receipt_number'] ?? $row['no_bukti'] ?? null;
                            $receiptNoLoan = $baseReceipt ? $baseReceipt : sprintf(
                                'KK-IMP-LON-%s-%s-%s',
                                now()->format('YmdHis'),
                                $member->id,
                                Str::upper(Str::random(4))
                            );

                            $trxLoan = Transaction::create([
                                'transaction_number' => $trxLoanNumber,
                                'receipt_number'     => $receiptNoLoan,
                                'member_id'          => $member->id,
                                'account_id'         => $accountKas->id,
                                'book_type'          => 'BUKU_BIRU',
                                'operator_id'        => $this->userId,
                                'approved_by'        => $this->userId,
                                'type'               => 'withdrawal',
                                'amount'             => $outstandingLoan,
                                'beginning_balance'  => 0.00,
                                'ending_balance'     => $outstandingLoan,
                                'payment_method'     => 'cash',
                                'transaction_date'   => now()->toDateString(),
                                'description'        => "Pencairan Pinjaman Awal (Outstanding) - {$member->name}",
                                'status'             => 'approved',
                                'approved_at'        => now(),
                            ]);
                            if ($activePeriod && \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'period_id')) {
                                $trxLoan->period_id = $activePeriod->id;
                                $trxLoan->save();
                            }
                            try {
                                $journalService->generateJournal($trxLoan);
                            } catch (\Throwable $eJ) {
                                Log::warning("[ImportMembersJob] Jurnal Loan gagal member ID {$member->id}: " . $eJ->getMessage());
                            }
                        }

                        // 2. Buku Biru Simpanan
                        $savingsTotal = $principal + $mandatory + $voluntary;
                        if ($savingsTotal > 0) {
                            $trxNumber = sprintf(
                                'TRX-IMP-%s-%s-%s-%s',
                                now()->format('YmdHis'),
                                $member->id,
                                'BIR',
                                Str::upper(Str::random(5))
                            );
                            $baseReceipt = $row['receipt_number'] ?? $row['no_bukti'] ?? null;
                            $receiptNoSavings = $baseReceipt ? $baseReceipt : sprintf(
                                'KM-IMP-%s-%s-%s',
                                now()->format('YmdHis'),
                                $member->id,
                                Str::upper(Str::random(4))
                            );

                            $trx = Transaction::create([
                                'transaction_number' => $trxNumber,
                                'receipt_number'     => $receiptNoSavings,
                                'member_id'          => $member->id,
                                'account_id'         => $accountKas->id,
                                'book_type'          => 'BUKU_BIRU',
                                'operator_id'        => $this->userId,
                                'approved_by'        => $this->userId,
                                'type'               => 'deposit',
                                'amount'             => $savingsTotal,
                                'beginning_balance'  => 0.00,
                                'ending_balance'     => $savingsTotal,
                                'payment_method'     => 'cash',
                                'transaction_date'   => now()->toDateString(),
                                'description'        => "Saldo Awal Simpanan (Buku Biru) - {$member->name}",
                                'status'             => 'approved',
                                'approved_at'        => now(),
                            ]);
                            if ($activePeriod && \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'period_id')) {
                                $trx->period_id = $activePeriod->id;
                                $trx->save();
                            }
                            try {
                                $journalService->generateJournal($trx);
                            } catch (\Throwable $eJ) {
                                Log::warning("[ImportMembersJob] Jurnal Buku Biru gagal member ID {$member->id}: " . $eJ->getMessage());
                            }
                        }

                        // 3. Tabungan Harian (Buku Putih)
                        if ($dailySavings > 0) {
                            $existingTrxPutih = Transaction::where('member_id', $member->id)
                                ->where('book_type', 'BUKU_PUTIH')
                                ->where(function ($q) {
                                    $q->where('description', 'like', '%Saldo Awal%')
                                      ->orWhere('receipt_number', 'like', 'KM-IMP-P-%');
                                })->first();

                            // Tanggal efektif saldo historis: akhir siklus Agustus 2026 (2026-08-20)
                            // Menggunakan tanggal ini agar saldo masuk di akhir siklus Agustus,
                            // sehingga bunga periode September & Oktober dapat mengalir secara presisi.
                            $historicalTxDate = Carbon::create(2026, 8, 20)->toDateString();

                            if ($existingTrxPutih) {
                                $existingTrxPutih->amount = $dailySavings;
                                $existingTrxPutih->ending_balance = $dailySavings;
                                $existingTrxPutih->description = "Saldo Awal Buku Putih - {$member->name}";
                                // Koreksi tanggal ke historis jika berbeda
                                if ($existingTrxPutih->transaction_date != $historicalTxDate) {
                                    $existingTrxPutih->transaction_date = $historicalTxDate;
                                }
                                $existingTrxPutih->save();
                            } else {
                                $trxNumberPutih = sprintf(
                                    'TRX-IMP-%s-%s-%s-%s',
                                    now()->format('YmdHis'),
                                    $member->id,
                                    'PUT',
                                    Str::upper(Str::random(5))
                                );
                                $baseReceiptPutih = $row['receipt_number'] ?? $row['no_bukti'] ?? null;
                                $receiptNoPutih = $row['receipt_number_putih'] ?? ($baseReceiptPutih ? $baseReceiptPutih . '-P' : null) ?? sprintf(
                                    'KM-IMP-P-%s-%s-%s',
                                    now()->format('YmdHis'),
                                    $member->id,
                                    Str::upper(Str::random(4))
                                );

                                $trxPutih = Transaction::create([
                                    'transaction_number' => $trxNumberPutih,
                                    'receipt_number'     => $receiptNoPutih,
                                    'member_id'          => $member->id,
                                    'account_id'         => $accountKas->id,
                                    'book_type'          => 'BUKU_PUTIH',
                                    'operator_id'        => $this->userId,
                                    'approved_by'        => $this->userId,
                                    'type'               => 'deposit',
                                    'amount'             => $dailySavings,
                                    'beginning_balance'  => 0.00,
                                    'ending_balance'     => $dailySavings,
                                    'payment_method'     => 'cash',
                                    // Tanggal historis: saldo mengendap sejak akhir Agustus, bukan tanggal import
                                    'transaction_date'   => $historicalTxDate,
                                    'description'        => "Saldo Awal Buku Putih - {$member->name}",
                                    'status'             => 'approved',
                                    'approved_at'        => now(),
                                ]);
                                if ($activePeriod && \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'period_id')) {
                                    $trxPutih->period_id = $activePeriod->id;
                                    $trxPutih->save();
                                }
                                try {
                                    $journalService->generateJournal($trxPutih);
                                } catch (\Throwable $eJ) {
                                    Log::warning("[ImportMembersJob] Jurnal Buku Putih gagal member ID {$member->id}: " . $eJ->getMessage());
                                }
                            }
                        }

                        $totalImported++;
                    }
                });
            }

            Log::info("[ImportMembersJob] Successfully imported {$totalImported} members from {$fullPath}");

        } catch (\Throwable $e) {
            Log::error("[ImportMembersJob] Error processing import: " . $e->getMessage(), [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        } finally {
            // Hapus file temporary setelah selesai
            if (file_exists($fullPath)) {
                @unlink($fullPath);
                Log::info("[ImportMembersJob] Cleaned up temporary file: {$fullPath}");
            }
        }
    }
}
