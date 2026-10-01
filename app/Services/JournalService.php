<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\JournalDetail;
use App\Models\JournalEntry;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class JournalService
{
    /**
     * Buat jurnal otomatis dari transaksi yang baru di-approve.
     * Prioritaskan explicit account_code dari request jika ada.
     *
     * @param Transaction $transaction
     * @param string|null $explicitAccountCode
     * @return JournalEntry|null
     */
    public function generateJournal(Transaction $transaction, ?string $explicitAccountCode = null): ?JournalEntry
    {
        // Cegah duplikat jurnal untuk transaksi yang sama
        $existing = JournalEntry::where('transaction_id', $transaction->id)->first();
        if ($existing) {
            Log::info("[JournalService] Jurnal sudah ada untuk transaksi #{$transaction->id}, skip.");
            return $existing;
        }

        // Tentukan akun Kas berdasarkan payment_method
        $cashAccountCode = $this->getCashAccountCode($transaction->payment_method);
        $cashAccount = ChartOfAccount::where('account_code', $cashAccountCode)->first();

        if (!$cashAccount) {
            $msg = "[JournalService] Akun kas '{$cashAccountCode}' tidak ditemukan di COA!";
            Log::warning($msg);
            throw new \Exception($msg);
        }

        // Mapping deskripsi transaksi ke kode perkiraan lawan (prioritaskan explicitAccountCode)
        $lines = $this->mapTransactionToJournalLines($transaction, $cashAccount, $explicitAccountCode);

        if (empty($lines)) {
            $msg = "[JournalService] Error/Gagal mapping COA untuk transaksi #{$transaction->id} ({$transaction->description})";
            Log::error($msg);
            throw new \Exception($msg);
        }

        // Ambil member untuk format deskripsi yang baik
        $transaction->loadMissing('member');
        $memberName = $transaction->member ? $transaction->member->name : 'Umum';
        $descText = $transaction->description ?: 'Transaksi Kasir';
        $fullDesc = str_contains($descText, $memberName) ? $descText : "{$descText} - {$memberName}";

        // Buat jurnal dalam database transaction (atomic)
        return DB::transaction(function () use ($transaction, $lines, $fullDesc) {
            $voucherNumber = $this->generateVoucherNumber($transaction);

            $journal = JournalEntry::create([
                'transaction_id' => $transaction->id,
                'entry_date'     => $transaction->transaction_date ?? $transaction->created_at->toDateString(),
                'voucher_number' => $voucherNumber,
                'description'    => $fullDesc,
                'created_by'     => $transaction->approved_by ?? $transaction->operator_id,
            ]);

            foreach ($lines as $line) {
                JournalDetail::create([
                    'journal_entry_id' => $journal->id,
                    'account_id'       => $line['account_id'],
                    'debit'            => $line['debit'],
                    'credit'           => $line['credit'],
                    'description'      => $line['description'] ?? null,
                ]);
            }

            Log::info("[JournalService] Jurnal #{$journal->voucher_number} berhasil dibuat untuk transaksi #{$transaction->id} ({$journal->details->count()} baris)");
            \Log::info('Journal created for transaction ID: ' . $transaction->id);

            return $journal;
        });
    }

    /**
     * Update dan sinkronisasi jurnal akuntansi saat transaksi dikoreksi/diupdate.
     *
     * @param Transaction $transaction
     * @param string|null $explicitAccountCode
     * @return JournalEntry|null
     */
    public function updateJournal(Transaction $transaction, ?string $explicitAccountCode = null): ?JournalEntry
    {
        $journal = JournalEntry::where('transaction_id', $transaction->id)->first();
        if (!$journal) {
            return $this->generateJournal($transaction, $explicitAccountCode);
        }

        $transaction->loadMissing('member');
        $memberName = $transaction->member ? $transaction->member->name : 'Umum';
        $descText = $transaction->description ?: 'Transaksi Kasir';
        $fullDesc = str_contains($descText, $memberName) ? $descText : "{$descText} - {$memberName}";
        $entryDate = $transaction->transaction_date ? \Carbon\Carbon::parse($transaction->transaction_date)->toDateString() : now()->toDateString();

        // 1. Perbarui tanggal dan deskripsi jurnal di awal agar selalu sinkron dengan transaksi
        $journal->update([
            'entry_date'  => $entryDate,
            'description' => $fullDesc,
        ]);

        $cashAccountCode = $this->getCashAccountCode($transaction->payment_method);
        $cashAccount = ChartOfAccount::where('account_code', $cashAccountCode)->first();

        if (!$cashAccount) {
            $cashAccount = ChartOfAccount::where('account_code', '1000')->first();
        }

        $lines = $this->mapTransactionToJournalLines($transaction, $cashAccount, $explicitAccountCode);
        if (empty($lines)) {
            Log::warning("[JournalService] Gagal mapping COA saat update transaksi #{$transaction->id}");
            return $journal->fresh(['details.account']);
        }

        return DB::transaction(function () use ($journal, $transaction, $lines, $fullDesc, $entryDate) {
            $journal->update([
                'entry_date'  => $entryDate,
                'description' => $fullDesc,
            ]);

            // Hapus detail lama dan masukkan detail baru sesuai nominal yang terupdate
            $journal->details()->delete();

            foreach ($lines as $line) {
                JournalDetail::create([
                    'journal_entry_id' => $journal->id,
                    'account_id'       => $line['account_id'],
                    'debit'            => $line['debit'],
                    'credit'           => $line['credit'],
                    'description'      => $line['description'] ?? null,
                ]);
            }

            Log::info("[JournalService] Jurnal #{$journal->voucher_number} berhasil diperbarui untuk transaksi #{$transaction->id}");
            return $journal->fresh(['details.account']);
        });
    }

    /**
     * Tentukan akun Kas berdasarkan payment_method.
     * cash/tunai → 1000 (Kas), transfer/bank → 1010 (BRI)
     */
    private function getCashAccountCode(?string $paymentMethod): string
    {
        $method = strtolower($paymentMethod ?? 'cash');

        return match (true) {
            str_contains($method, 'transfer'), str_contains($method, 'bank'), str_contains($method, 'bri') => '1010',
            default => '1000',
        };
    }

    /**
     * Map satu transaksi ke baris-baris jurnal (debit & kredit).
     * Return array of: ['account_id', 'debit', 'credit', 'description']
     */
    private function mapTransactionToJournalLines(Transaction $transaction, ChartOfAccount $cashAccount, ?string $explicitAccountCode = null): array
    {
        $transaction->loadMissing('member');
        $memberName = $transaction->member ? $transaction->member->name : 'Umum';
        $descText = $transaction->description ?: 'Transaksi Kasir';
        $fullDesc = str_contains($descText, $memberName) ? $descText : "{$descText} - {$memberName}";

        $amount = (float) $transaction->amount;
        $desc   = strtolower($transaction->description ?? '');
        $type   = strtolower($transaction->type ?? '');
        $lines  = [];

        // ─── 0. PRIORITASKAN EXPLICIT ACCOUNT CODE PAYLOAD DARI FRONTEND/CONTROLLER ───
        $explicitCoa = null;
        if (!empty($explicitAccountCode)) {
            $explicitCoa = ChartOfAccount::where('account_code', trim((string)$explicitAccountCode))->first()
                ?? ChartOfAccount::find($explicitAccountCode);
        }

        // ─── 0.1 KHUSUS POS BANK / KAS BANK BRI (AKUN 1010) ───
        $isBankPos = ($explicitCoa && $explicitCoa->account_code === '1010')
            || (str_contains($desc, 'bank') && !str_contains($desc, 'jasa bank') && !str_contains($desc, 'bunga bank'));

        if ($isBankPos) {
            $briAccount = ChartOfAccount::where('account_code', '1010')->first();
            $kas1000   = ChartOfAccount::where('account_code', '1000')->first();

            if ($briAccount) {
                if (in_array($type, ['deposit', 'in', 'kas_masuk'])) {
                    // Kas Masuk Bank: DEBET 1010 (Kas Bank BRI), KREDIT 1000 (Kas Utama)
                    $lines[] = [
                        'account_id'  => $briAccount->id,
                        'debit'       => $amount,
                        'credit'      => 0,
                        'description' => $fullDesc,
                    ];
                    $lines[] = [
                        'account_id'  => ($cashAccount && $cashAccount->id !== $briAccount->id) ? $cashAccount->id : ($kas1000?->id ?? $briAccount->id),
                        'debit'       => 0,
                        'credit'      => $amount,
                        'description' => $fullDesc,
                    ];
                } else {
                    // Kas Keluar Bank: DEBET 1000 (Kas Utama), KREDIT 1010 (Kas Bank BRI)
                    $lines[] = [
                        'account_id'  => ($cashAccount && $cashAccount->id !== $briAccount->id) ? $cashAccount->id : ($kas1000?->id ?? $briAccount->id),
                        'debit'       => $amount,
                        'credit'      => 0,
                        'description' => $fullDesc,
                    ];
                    $lines[] = [
                        'account_id'  => $briAccount->id,
                        'debit'       => 0,
                        'credit'      => $amount,
                        'description' => $fullDesc,
                    ];
                }
                return $lines;
            }
        }

        if ($explicitCoa && $explicitCoa->id !== $cashAccount->id) {
            if (in_array($type, ['deposit', 'in', 'kas_masuk'])) {
                // Kas Masuk: Debet Kas, Kredit Explicit COA
                $lines[] = [
                    'account_id'  => $cashAccount->id,
                    'debit'       => $amount,
                    'credit'      => 0,
                    'description' => $fullDesc,
                ];
                $lines[] = [
                    'account_id'  => $explicitCoa->id,
                    'debit'       => 0,
                    'credit'      => $amount,
                    'description' => $fullDesc,
                ];
            } else {
                // Kas Keluar: Debet Explicit COA, Kredit Kas
                $lines[] = [
                    'account_id'  => $explicitCoa->id,
                    'debit'       => $amount,
                    'credit'      => 0,
                    'description' => $fullDesc,
                ];
                $lines[] = [
                    'account_id'  => $cashAccount->id,
                    'debit'       => 0,
                    'credit'      => $amount,
                    'description' => $fullDesc,
                ];
            }

            // Jurnalkan denda jika ada
            if ((float) $transaction->denda > 0) {
                $dendaAccount = ChartOfAccount::where('account_code', '4182')->first();
                if ($dendaAccount) {
                    $lines[] = [
                        'account_id'  => $cashAccount->id,
                        'debit'       => (float) $transaction->denda,
                        'credit'      => 0,
                        'description' => 'Denda: ' . ($transaction->denda_reason ?? 'Keterlambatan'),
                    ];
                    $lines[] = [
                        'account_id'  => $dendaAccount->id,
                        'debit'       => 0,
                        'credit'      => (float) $transaction->denda,
                        'description' => 'Pendapatan denda',
                    ];
                }
            }

            return $lines;
        }

        // ─── KAS MASUK (Deposit / Setoran) ───
        if (in_array($type, ['deposit', 'in', 'kas_masuk'])) {
            $receipt = strtolower($transaction->receipt_number ?? '');

            // 0. Khusus Transaksi Impor / Saldo Awal Simpanan (KM-IMP-...)
            $isSaldoAwal = str_contains($desc, 'saldo awal simpanan') 
                || str_contains($desc, 'saldo awal buku putih') 
                || str_contains($desc, 'saldo awal') 
                || str_starts_with($receipt, 'km-imp')
                || $transaction->category === 'saldo_awal';

            if ($isSaldoAwal) {
                $isBukuPutih = $transaction->book_type === 'BUKU_PUTIH' 
                    || str_contains($desc, 'buku putih') 
                    || str_contains($desc, 'harian') 
                    || str_starts_with($receipt, 'km-imp-p')
                    || $transaction->category === 'simpanan_harian';

                $targetCoaCode = $isBukuPutih ? '2021' : '2020';
                $targetCoa = ChartOfAccount::where('account_code', $targetCoaCode)->first();

                if ($targetCoa && $cashAccount) {
                    $lines[] = [
                        'account_id'  => $cashAccount->id,
                        'debit'       => $amount,
                        'credit'      => 0,
                        'description' => $fullDesc,
                    ];
                    $lines[] = [
                        'account_id'  => $targetCoa->id,
                        'debit'       => 0,
                        'credit'      => $amount,
                        'description' => $fullDesc,
                    ];
                    return $lines;
                }
            }

            // Khusus Pendaftaran Anggota Baru / Setoran Awal Multi-Komponen (Split Jurnal: SP, SW, Pangkal, Dana Duka)
            $isRegistration = ($transaction->category === 'pendaftaran' || str_contains($desc, 'setoran simpanan awal') || str_contains($desc, 'pendaftaran') || str_contains($desc, 'registrasi'))
                && $transaction->category !== 'simpanan_harian' && !str_contains($desc, 'tabungan harian');

            if ($isRegistration) {
                $transaction->loadMissing('member');
                $member = $transaction->member;
                if ($member && ($transaction->book_type === 'BUKU_BIRU' || str_contains($desc, 'buku biru'))) {
                    $coaSaham   = ChartOfAccount::where('account_code', '2020')->first();
                    $coaDuka    = ChartOfAccount::where('account_code', '2038')->first() ?? ChartOfAccount::where('account_code', '2034')->first();
                    $coaPangkal = ChartOfAccount::where('account_code', '4191')->first();

                    $sp = (float) ($member->principal_savings ?? 200000.0);
                    $sw = (float) ($member->mandatory_savings ?? 20000.0);
                    $ss = (float) ($member->voluntary_savings ?? 0.0);
                    $duka = (float) ($member->grief_fund ?: ($member->social_fund ?? 20000.0));
                    $pangkal = (float) ($member->registration_fee ?? 20000.0);

                    if ($member->has_buku_biru && $member->has_buku_putih) {
                        $duka = 20000.00;
                        $pangkal = 20000.00;
                    }

                    $saham = $sp + $sw + $ss;
                    $expectedTotal = $saham + $duka + $pangkal;

                    if ($amount != $expectedTotal && $expectedTotal > 0) {
                        if ($amount >= ($duka + $pangkal)) {
                            $saham = $amount - ($duka + $pangkal);
                        } else {
                            $saham = max(0, $amount - $pangkal);
                            $duka = 0;
                        }
                    }

                    // 1. DEBIT Kas/Bank Total
                    $lines[] = [
                        'account_id'  => $cashAccount->id,
                        'debit'       => $amount,
                        'credit'      => 0,
                        'description' => "Setoran Awal Pendaftaran (Buku Biru) - {$memberName}",
                    ];

                    // 2. KREDIT Saham (Simpanan Pokok + Wajib + Sukarela Buku Biru)
                    if ($saham > 0 && $coaSaham) {
                        $sahamDesc = ($ss > 0) 
                            ? "Simpanan Pokok, Wajib & Sukarela (Buku Biru) - {$memberName}" 
                            : "Simpanan Pokok & Wajib (Buku Biru) - {$memberName}";
                        $lines[] = [
                            'account_id'  => $coaSaham->id,
                            'debit'       => 0,
                            'credit'      => $saham,
                            'description' => $sahamDesc,
                        ];
                    }

                    // 3. KREDIT Dana Duka / Sosial
                    if ($duka > 0 && $coaDuka) {
                        $lines[] = [
                            'account_id'  => $coaDuka->id,
                            'debit'       => 0,
                            'credit'      => $duka,
                            'description' => "Cadangan Dana Duka - {$memberName}",
                        ];
                    }

                    // 4. KREDIT Uang Pangkal
                    if ($pangkal > 0 && $coaPangkal) {
                        $lines[] = [
                            'account_id'  => $coaPangkal->id,
                            'debit'       => 0,
                            'credit'      => $pangkal,
                            'description' => "Pendapatan Uang Pangkal - {$memberName}",
                        ];
                    }

                    return $lines;
                }

                if ($member && ($transaction->book_type === 'BUKU_PUTIH' || str_contains($desc, 'buku putih'))) {
                    $coaHarian  = ChartOfAccount::where('account_code', '2021')->first();
                    $coaDuka    = ChartOfAccount::where('account_code', '2038')->first() ?? ChartOfAccount::where('account_code', '2034')->first();
                    $coaPangkal = ChartOfAccount::where('account_code', '4191')->first();

                    $daily = (float) ($member->daily_savings ?? 50000.0);
                    $duka = (float) ($member->grief_fund ?: ($member->social_fund ?? 20000.0));
                    $pangkal = (float) ($member->registration_fee ?? 20000.0);

                    if ($member->has_buku_biru && $member->has_buku_putih) {
                        $duka = 20000.00;
                        $pangkal = 20000.00;
                    }

                    $expectedTotal = $daily + $duka + $pangkal;

                    if ($amount != $expectedTotal && $expectedTotal > 0) {
                        if ($amount >= ($duka + $pangkal)) {
                            $daily = $amount - ($duka + $pangkal);
                        } else {
                            $daily = max(0, $amount - $pangkal);
                            $duka = 0;
                        }
                    }

                    // 1. DEBIT Kas/Bank Total
                    $lines[] = [
                        'account_id'  => $cashAccount->id,
                        'debit'       => $amount,
                        'credit'      => 0,
                        'description' => "Setoran Awal Pendaftaran (Buku Putih) - {$memberName}",
                    ];

                    // 2. KREDIT Simpanan Harian (Buku Putih)
                    if ($daily > 0 && $coaHarian) {
                        $lines[] = [
                            'account_id'  => $coaHarian->id,
                            'debit'       => 0,
                            'credit'      => $daily,
                            'description' => "Simpanan Harian (Buku Putih) - {$memberName}",
                        ];
                    }

                    // 3. KREDIT Dana Duka / Sosial
                    if ($duka > 0 && $coaDuka) {
                        $lines[] = [
                            'account_id'  => $coaDuka->id,
                            'debit'       => 0,
                            'credit'      => $duka,
                            'description' => "Cadangan Dana Duka - {$memberName}",
                        ];
                    }

                    // 4. KREDIT Uang Pangkal
                    if ($pangkal > 0 && $coaPangkal) {
                        $lines[] = [
                            'account_id'  => $coaPangkal->id,
                            'debit'       => 0,
                            'credit'      => $pangkal,
                            'description' => "Pendapatan Uang Pangkal - {$memberName}",
                        ];
                    }

                    return $lines;
                }
            }

            $counterAccount = $this->resolveCounterAccountForDeposit($transaction, $desc, $cashAccount);

            if ($counterAccount) {
                // Debet: Kas (uang masuk)
                $lines[] = [
                    'account_id'  => $cashAccount->id,
                    'debit'       => $amount,
                    'credit'      => 0,
                    'description' => $fullDesc,
                ];
                // Kredit: Akun Lawan
                $lines[] = [
                    'account_id'  => $counterAccount->id,
                    'debit'       => 0,
                    'credit'      => $amount,
                    'description' => $fullDesc,
                ];
            }

            // Jurnalkan denda jika ada
            if ((float) $transaction->denda > 0) {
                $dendaAccount = ChartOfAccount::where('account_code', '4182')->first(); // Denda Keterlambatan
                if ($dendaAccount) {
                    $lines[] = [
                        'account_id'  => $cashAccount->id,
                        'debit'       => (float) $transaction->denda,
                        'credit'      => 0,
                        'description' => 'Denda: ' . ($transaction->denda_reason ?? 'Keterlambatan'),
                    ];
                    $lines[] = [
                        'account_id'  => $dendaAccount->id,
                        'debit'       => 0,
                        'credit'      => (float) $transaction->denda,
                        'description' => 'Pendapatan denda',
                    ];
                }
            }
        }

        // ─── KAS KELUAR (Withdrawal / Pengeluaran) ───
        elseif (in_array($type, ['withdrawal', 'out', 'kas_keluar'])) {
            // 1. Khusus Pengembalian Simpanan Anggota Keluar (Resign / KK-RESIGN / Penutupan Buku Putih)
            if (str_contains($desc, 'resign') || str_contains($desc, 'pengembalian simpanan') || str_contains($desc, 'anggota keluar') 
                || str_contains($desc, 'penutupan rekening') || str_contains($desc, 'tutup buku') || str_contains($desc, 'penutupan buku')
                || str_starts_with($transaction->transaction_number ?? '', 'KK-RESIGN') || str_starts_with($transaction->receipt_number ?? '', 'RESIGN-')
                || str_starts_with($transaction->transaction_number ?? '', 'KK-CLOSE-') || str_starts_with($transaction->receipt_number ?? '', 'CLOSE-')) {
                
                $transaction->loadMissing('member');
                $member = $transaction->member;

                $coaSaham   = ChartOfAccount::where('account_code', '2020')->first();
                $coaHarian  = ChartOfAccount::where('account_code', '2021')->first();
                $coaPenalti = ChartOfAccount::where('account_code', '4183')->first() ?? ChartOfAccount::where('account_code', '4192')->first();
                $coaPiutang = ChartOfAccount::where('account_code', '1024')->first();

                $totalSimpananAwal = (float) ($transaction->beginning_balance ?: $amount);

                $sp = (float) ($member->principal_savings ?? 0);
                $sw = (float) ($member->mandatory_savings ?? 0);
                $ss = (float) ($member->voluntary_savings ?? 0);
                $daily = (float) ($member->daily_savings ?? 0);
                $saham = $sp + $sw + $ss;

                // Jika saldo member sudah 0 di DB saat ini, gunakan breakdown berbasis beginning_balance / book_type
                if (($saham + $daily) <= 0 && $totalSimpananAwal > 0) {
                    if ($transaction->book_type === 'BUKU_PUTIH' || str_contains($desc, 'buku putih')) {
                        $daily = $totalSimpananAwal;
                        $saham = 0;
                    } else {
                        $saham = $totalSimpananAwal;
                        $daily = 0;
                    }
                }

                // Jika transaksi bertipe BUKU_PUTIH murni (misal penutupan buku putih saja)
                if ($transaction->book_type === 'BUKU_PUTIH' || (str_contains($desc, 'buku putih') && !str_contains($desc, 'resign total'))) {
                    $saham = 0;
                    $daily = $totalSimpananAwal > 0 ? $totalSimpananAwal : $daily;
                }

                // 1. DEBIT: Akun Simpanan Anggota (Pokok/Wajib/Sukarela - 2020)
                if ($saham > 0 && $coaSaham) {
                    $lines[] = [
                        'account_id'  => $coaSaham->id,
                        'debit'       => $saham,
                        'credit'      => 0,
                        'description' => "Pengembalian Simpanan Pokok, Wajib & Sukarela (Buku Biru) - {$memberName}",
                    ];
                }

                // 2. DEBIT: Akun Simpanan Harian (Buku Putih - 2021)
                if ($daily > 0 && $coaHarian) {
                    $lines[] = [
                        'account_id'  => $coaHarian->id,
                        'debit'       => $daily,
                        'credit'      => 0,
                        'description' => "Pengembalian Simpanan Harian (Buku Putih) - {$memberName}",
                    ];
                }

                // Fallback jika tidak ada breakdown khusus
                if (empty($lines) && $coaSaham) {
                    $lines[] = [
                        'account_id'  => $coaSaham->id,
                        'debit'       => $totalSimpananAwal,
                        'credit'      => 0,
                        'description' => "Pengembalian Simpanan Anggota Keluar (Resign) - {$memberName}",
                    ];
                }

                $totalDebit = array_sum(array_column($lines, 'debit'));
                $netCashDisbursed = $amount; // Nominal kas bersih yang dicairkan

                // Selisih antara Total Simpanan (Debit) dan Kas Keluar (Kredit) adalah Potongan Penalti & Pinjaman
                $totalDeductions = max(0.0, $totalDebit - $netCashDisbursed);

                // Cek apakah ada penalti/potongan dari deskripsi transaksi
                $penaltyAmount = 0.0;
                if (preg_match('/Potongan (?:Penalti|Administrasi|Biaya)?:\s*Rp\s*([\d\.]+)/i', $transaction->description ?? '', $matches)) {
                    $penaltyAmount = (float) str_replace('.', '', $matches[1]);
                }

                // 3. KREDIT: Akun Pendapatan Penalti / Administrasi (4183 / 4192)
                if ($penaltyAmount > 0 && $coaPenalti) {
                    $lines[] = [
                        'account_id'  => $coaPenalti->id,
                        'debit'       => 0,
                        'credit'      => $penaltyAmount,
                        'description' => "Pendapatan Penalti / Administrasi - {$memberName}",
                    ];
                }

                // 4. KREDIT: Pelunasan Sisa Pinjaman (1024) jika ada potongan pinjaman
                $remainingDeduction = max(0.0, $totalDeductions - $penaltyAmount);
                if ($remainingDeduction > 0 && $coaPiutang) {
                    $lines[] = [
                        'account_id'  => $coaPiutang->id,
                        'debit'       => 0,
                        'credit'      => $remainingDeduction,
                        'description' => "Pelunasan Sisa Pinjaman dari Simpanan Resign - {$memberName}",
                    ];
                }

                // 5. KREDIT: Akun Kas Utama (1000 / 1010) sebesar nominal bersih yang dicairkan
                $lines[] = [
                    'account_id'  => $cashAccount->id,
                    'debit'       => 0,
                    'credit'      => $netCashDisbursed,
                    'description' => "Kas Keluar Pengembalian Simpanan Resign - {$memberName}",
                ];

                return $lines;
            }

            $counterAccount = $this->resolveCounterAccountForWithdrawal($transaction, $desc, $cashAccount);

            if ($counterAccount) {
                // Debet: Akun Lawan (beban/kewajiban naik)
                $lines[] = [
                    'account_id'  => $counterAccount->id,
                    'debit'       => $amount,
                    'credit'      => 0,
                    'description' => $fullDesc,
                ];
                // Kredit: Kas (uang keluar)
                $lines[] = [
                    'account_id'  => $cashAccount->id,
                    'debit'       => 0,
                    'credit'      => $amount,
                    'description' => $fullDesc,
                ];
            }
        }

        return $lines;
    }

    /**
     * Resolve akun lawan untuk transaksi SETORAN (Kas Masuk).
     */
    private function resolveCounterAccountForDeposit(Transaction $transaction, string $desc, ?ChartOfAccount $cashAccount = null): ?ChartOfAccount
    {
        // 1. Cek explicit account_id dari transaksi jika ada (pastikan bukan akun kas)
        if ($transaction->account_id) {
            $acc = ChartOfAccount::find($transaction->account_id);
            if ($acc && (!$cashAccount || $acc->id !== $cashAccount->id)) {
                return $acc;
            }
        }

        // 1.1 Khusus Saldo Awal / Impor Simpanan
        $receipt = strtolower($transaction->receipt_number ?? '');
        if (str_contains($desc, 'saldo awal') || str_starts_with($receipt, 'km-imp')) {
            if ($transaction->book_type === 'BUKU_PUTIH' || str_contains($desc, 'buku putih') || str_contains($desc, 'harian') || str_starts_with($receipt, 'km-imp-p')) {
                return ChartOfAccount::where('account_code', '2021')->first();
            }
            return ChartOfAccount::where('account_code', '2020')->first();
        }

        // 2. Finalty Tabungan / Penalti / Penalty → 4183 (Pendapatan Penalti / Administrasi)
        if (str_contains($desc, 'finalty') || str_contains($desc, 'penalti') || str_contains($desc, 'penalty')) {
            return ChartOfAccount::where('account_code', '4183')->first();
        }

        // 3. Denda Deviden → 4184
        if (str_contains($desc, 'denda deviden') || str_contains($desc, 'denda dividen')) {
            return ChartOfAccount::where('account_code', '4184')->first();
        }

        // 4. Denda Keterlambatan / Denda Angsuran / Denda → 4182
        if (str_contains($desc, 'denda')) {
            return ChartOfAccount::where('account_code', '4182')->first();
        }

        // 5. Provisi Pinjaman → 4170
        if (str_contains($desc, 'provisi')) {
            return ChartOfAccount::where('account_code', '4170')->first();
        }

        // 6. Uang Pangkal / Pendaftaran → 4191
        if (str_contains($desc, 'uang pangkal') || str_contains($desc, 'pendaftaran')
            || str_contains($desc, 'registration') || str_contains($desc, 'pangkal')) {
            return ChartOfAccount::where('account_code', '4191')->first();
        }

        // 7. Dana Duka / Dana Sosial / Dana → 2038 (Dana Duka) / 2034 (Dana Sosial)
        if (str_contains($desc, 'dana duka') || str_contains($desc, 'duka') || str_contains($desc, 'grief')) {
            return ChartOfAccount::where('account_code', '2038')->first();
        }
        if (str_contains($desc, 'dana sosial') || str_contains($desc, 'sosial')) {
            return ChartOfAccount::where('account_code', '2034')->first();
        }
        if (trim($desc) === 'dana' || str_contains($desc, 'dana ')) {
            return ChartOfAccount::where('account_code', '2038')->first();
        }

        // 8. Asuransi Investasi / Asuransi → 2032 (Liability Asuransi) / 4193
        if (str_contains($desc, 'asuransi')) {
            return ChartOfAccount::where('account_code', '2032')->first() ?? ChartOfAccount::where('account_code', '4193')->first();
        }

        // 9. Diakonia → 2022
        if (str_contains($desc, 'diakonia')) {
            return ChartOfAccount::where('account_code', '2022')->first();
        }

        // 10. Jasa Pinjaman / Bunga Pinjaman / Jasa Piutang → 4180 (Kredit Akun Pendapatan Jasa 4180, bukan 1024)
        if (str_contains($desc, 'jasa pinjaman') || str_contains($desc, 'bunga pinjaman')
            || str_contains($desc, 'interest_payment') || str_contains($desc, 'jasa angsuran')
            || str_contains($desc, 'jasa piutang') || (str_contains($desc, 'jasa') && !str_contains($desc, 'simpanan'))) {
            return ChartOfAccount::where('account_code', '4180')->first();
        }

        // 11. Angsuran Pinjaman (Pokok) / Pelunasan Piutang → Kredit 1024 (Piutang berkurang)
        if (str_contains($desc, 'angsuran') || str_contains($desc, 'cicilan')
            || str_contains($desc, 'loan_installment') || str_contains($desc, 'pokok pinjaman')
            || str_contains($desc, 'pelunasan pinjaman') || str_contains($desc, 'bayar pinjaman')
            || str_contains($desc, 'piutang')) {
            return ChartOfAccount::where('account_code', '1024')->first();
        }

        // 12. Bunga Simpanan → 7145
        if (str_contains($desc, 'bunga simpanan') || str_contains($desc, 'interest')) {
            return ChartOfAccount::where('account_code', '7145')->first();
        }

        // 13. Pendapatan Lain-lain
        if (str_contains($desc, 'pendapatan') || str_contains($desc, 'lain-lain')) {
            return ChartOfAccount::where('account_code', '4192')->first();
        }

        // 14. Simpanan Harian / Tabungan Harian (Buku Putih) → 2021
        if (str_contains($desc, 'simpanan harian') || str_contains($desc, 'tabungan harian')
            || str_contains($desc, 'buku putih') || str_contains($desc, 'daily')
            || $transaction->book_type === 'BUKU_PUTIH') {
            return ChartOfAccount::where('account_code', '2021')->first();
        }

        // 15. Khusus Pos Bank / Bank BRI → 1010 (JANGAN pernah masuk ke 2020!)
        if ((str_contains($desc, 'bank') && !str_contains($desc, 'jasa bank') && !str_contains($desc, 'bunga bank')) || trim($desc) === 'bank') {
            return ChartOfAccount::where('account_code', '1010')->first();
        }

        // 16. Simpanan Pokok / Wajib / Sukarela (Buku Biru) → 2020
        if (str_contains($desc, 'simpanan pokok') || str_contains($desc, 'simpanan wajib')
            || str_contains($desc, 'simpanan sukarela') || str_contains($desc, 'sukarela')
            || str_contains($desc, 'sw,ss,sp') || str_contains($desc, 'buku biru')
            || str_contains($desc, 'saham') || $transaction->book_type === 'BUKU_BIRU') {
            return ChartOfAccount::where('account_code', '2020')->first();
        }

        // Default: Simpanan Buku Biru untuk deposit tanpa deskripsi spesifik
        return ChartOfAccount::where('account_code', '2020')->first();
    }

    /**
     * Resolve akun lawan untuk transaksi PENGELUARAN (Kas Keluar).
     */
    private function resolveCounterAccountForWithdrawal(Transaction $transaction, string $desc, ChartOfAccount $cashAccount): ?ChartOfAccount
    {
        // 1. Cek explicit account_id langsung dari transaksi jika ada (pastikan BUKAN akun kas)
        if ($transaction->account_id) {
            $acc = ChartOfAccount::find($transaction->account_id);
            if ($acc && $acc->id !== $cashAccount->id) {
                return $acc;
            }
        }

        // 2. Pencairan Pinjaman / Pinjaman Anggota / Piutang → Debet 1024 (Piutang bertambah)
        if (str_contains($desc, 'pinjaman') || str_contains($desc, 'disbursement') || str_contains($desc, 'piutang') || str_contains($desc, 'pencairan')) {
            return ChartOfAccount::where('account_code', '1024')->first();
        }

        // 3. Penarikan Simpanan Buku Biru → Debet 2020
        if (str_contains($desc, 'penarikan') && (str_contains($desc, 'buku biru') || str_contains($desc, 'sw,ss,sp')
            || str_contains($desc, 'sukarela') || str_contains($desc, 'saham') || $transaction->book_type === 'BUKU_BIRU')) {
            return ChartOfAccount::where('account_code', '2020')->first();
        }

        // 4. Penarikan Simpanan Buku Putih / Harian → Debet 2021
        if (str_contains($desc, 'penarikan') && (str_contains($desc, 'buku putih') || str_contains($desc, 'harian')
            || str_contains($desc, 'tabungan') || $transaction->book_type === 'BUKU_PUTIH')) {
            return ChartOfAccount::where('account_code', '2021')->first();
        }

        // 5. Mapping Beban Operasional berdasarkan keyword
        $expenseMap = [
            'gaji'           => '7110', 'lembur'         => '7116', 'bonus'          => '7117',
            'honor pengurus' => '7160', 'tunjangan rumah'=> '7123', 'transport'      => '7115',
            'atk'            => '7100', 'komunikasi'     => '7101', 'komputer'       => '7102',
            'kendaraan'      => '7103', 'wifi'           => '7108', 'wifie'          => '7108',
            'listrik'        => '7109', 'cetak'          => '7120', 'rek koran'      => '7120',
            'rapat'          => '7161', 'insentive rapat'=> '7122', 'perjalanan dinas tamu' => '7111',
            'perjalanan dinas' => '7112', 'minyak'       => '7114', 'pajak'          => '7131',
            'jasa simpanan'  => '7145', 'bunga simpanan' => '7145', 'tamu'           => '7147',
            'bpjs kesehatan' => '7167', 'bpjs tenaga'    => '7168', 'sumbangan'      => '7169',
            'konsumsi'       => '7170', 'promosi'        => '7171', 'parsel'         => '7171',
            'perbaikan'      => '7121',
        ];

        foreach ($expenseMap as $keyword => $code) {
            if (str_contains($desc, $keyword)) {
                return ChartOfAccount::where('account_code', $code)->first();
            }
        }

        // Default pengeluaran simpanan jika tidak ada match khusus:
        if ($transaction->book_type === 'BUKU_PUTIH' || str_contains($desc, 'buku putih') || str_contains($desc, 'harian')) {
            return ChartOfAccount::where('account_code', '2021')->first();
        }

        return ChartOfAccount::where('account_code', '2020')->first();
    }

    /**
     * Generate nomor voucher jurnal unik: JV-YYYYMMDD-XXXX
     */
    private function generateVoucherNumber(Transaction $transaction): string
    {
        $baseNumber = trim($transaction->receipt_number ?: $transaction->transaction_number ?: '');
        while (preg_match('/^(KM|KK)[-\s_]+(KM|KK)[-\s_]*/i', $baseNumber)) {
            $baseNumber = preg_replace('/^(KM|KK)[-\s_]+/i', '', $baseNumber);
        }

        $type = strtolower($transaction->type ?? '');

        $newVoucher = $baseNumber;
        if (in_array($type, ['deposit', 'in', 'kas_masuk', 'km'])) {
            if (!preg_match('/^KM[-\s_]/i', $baseNumber) && !str_starts_with(strtoupper($baseNumber), 'KM')) {
                $newVoucher = 'KM-' . $baseNumber;
            }
        } elseif (in_array($type, ['withdrawal', 'out', 'kas_keluar', 'kk'])) {
            if (!preg_match('/^KK[-\s_]/i', $baseNumber) && !str_starts_with(strtoupper($baseNumber), 'KK')) {
                $newVoucher = 'KK-' . $baseNumber;
            }
        }

        if ($newVoucher && (str_starts_with(strtoupper($newVoucher), 'KM') || str_starts_with(strtoupper($newVoucher), 'KK') || str_starts_with(strtoupper($newVoucher), 'JV-'))) {
            $originalVoucher = $newVoucher;
            $suffix = 1;
            while (JournalEntry::where('voucher_number', $newVoucher)->where('transaction_id', '!=', $transaction->id)->exists()) {
                $newVoucher = $originalVoucher . '-' . $suffix;
                $suffix++;
            }
            return $newVoucher;
        }

        $date = $transaction->transaction_date
            ? $transaction->transaction_date->format('Ymd')
            : now()->format('Ymd');

        $count = JournalEntry::where('voucher_number', 'like', "JV-{$date}-%")->count() + 1;
        return sprintf('JV-%s-%04d', $date, $count);
    }

    /**
     * Rekapitulasi: hitung saldo per akun untuk laporan Neraca Lajur / Trial Balance
     * Sesuai Master Excel Operasional Koperasi CUM Pelita.
     *
     * @param string|null $startDate Format: Y-m-d
     * @param string|null $endDate   Format: Y-m-d
     * @return array
     */
    public function getTrialBalance(?string $startDate = null, ?string $endDate = null): array
    {
        return app(WorksheetReportService::class)->generateWorksheet($startDate, $endDate);
    }
}
