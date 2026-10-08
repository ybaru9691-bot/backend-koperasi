<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\JournalDetail;
use App\Models\ChartOfAccount;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class TabelarisReportService
{
    /**
     * Definisi Daftar 29 Kolom Baku Jurnal Tabelaris KSP CUM Pelita HKBP Ress. Dame Duri:
     *
     * IDENTITAS (4 kolom: A-D / 1-4):
     * 1. tgl (Tanggal)
     * 2. no_bukti (No Bukti / Voucher)
     * 3. nba (No Buku Anggota / Member Number)
     * 4. nama (Nama Anggota / Keterangan)
     *
     * PENGELUARAN (9 kolom: E-M / 5-13):
     * 5. piutang (Pencairan Pinjaman Baru - Akun 1024)
     * 6. penarikan_sw (Penarikan Simpanan Wajib)
     * 7. penarikan_ss (Penarikan Simpanan Sukarela)
     * 8. penarikan_sp (Penarikan Simpanan Pokok - saat resign)
     * 9. penarikan_sh (Penarikan Simpanan Harian / Buku Putih - Akun 2021)
     * 10. penarikan_sd (Penarikan Simpanan Diakonia - Akun 2022)
     * 11. inventaris (Pembelian Inventaris / Aset - Akun 1030 / 12xx)
     * 12. bank_keluar (Kas Keluar ke Bank BRI - Akun 1010)
     * 13. biaya (Beban Operasional - Akun 5xxx / 6xxx / 7xxx)
     *
     * KAS (2 kolom: N-O / 14-15) - DI TENGAH memisahkan Pengeluaran & Pemasukan:
     * 14. kas_debet (Kas Masuk / KM)
     * 15. kas_kredit (Kas Keluar / KK)
     *
     * PEMASUKAN (14 kolom: P-AC / 16-29):
     * 16. dana_dana (Dana Sosial / Dana Duka / Cadangan - Akun 2034, 2038)
     * 17. uang_pangkal (Uang Pangkal / Pendaftaran - Akun 4191)
     * 18. simpanan_sp (Setoran Simpanan Pokok - Akun 2020 SP)
     * 19. simpanan_sw (Setoran Simpanan Wajib - Akun 2020 SW)
     * 20. simpanan_ss (Setoran Simpanan Sukarela - Akun 2020 SS)
     * 21. simpanan_sh (Setoran Simpanan Harian / Buku Putih - Akun 2021)
     * 22. simpanan_sd (Setoran Simpanan Diakonia - Akun 2022)
     * 23. angsuran_pokok (Angsuran Pokok Pinjaman - Akun 1024)
     * 24. jasa_pinjaman (Jasa / Bunga Pinjaman - Akun 4180)
     * 25. denda_penalti (Denda Keterlambatan / Penalti - Akun 4182, 4181)
     * 26. provisi_pinjaman (Provisi Pinjaman - Akun 4170)
     * 27. asuransi (Asuransi Pinjaman / Jiwa - Akun 2035)
     * 28. lain_lain (Pendapatan Lain-lain - Akun 4192, 4195, dll)
     * 29. bank_masuk (Penarikan dari Bank BRI ke Kas - Akun 1010)
     *
     * @param string|null $startDate
     * @param string|null $endDate
     * @param string|null $periodLabel
     * @return array
     */
    public function generateTabelaris(?string $startDate = null, ?string $endDate = null, ?string $periodLabel = null): array
    {
        if ($startDate) {
            $startDate = date('Y-m-d', strtotime($startDate));
        }
        if ($endDate) {
            $endDate = date('Y-m-d', strtotime($endDate));
        }

        // 1. Query Transaksi Berjalan (Approved)
        $query = Transaction::query()
            ->with([
                'member:id,name,member_number',
                'account:id,account_name,account_number',
                'journalEntry.details.account:id,account_code,account_type'
            ])
            ->where('status', 'approved');

        if ($startDate && $endDate) {
            $query->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('transaction_date', [$startDate, $endDate])
                  ->orWhere(function ($sub) use ($startDate, $endDate) {
                      $sub->whereNull('transaction_date')
                          ->whereDate('created_at', '>=', $startDate)
                          ->whereDate('created_at', '<=', $endDate);
                  });
            });
        } elseif ($startDate) {
            $query->where(function ($q) use ($startDate) {
                $q->where('transaction_date', '>=', $startDate)
                  ->orWhere(function ($sub) use ($startDate) {
                      $sub->whereNull('transaction_date')
                          ->whereDate('created_at', '>=', $startDate);
                  });
            });
        } elseif ($endDate) {
            $query->where(function ($q) use ($endDate) {
                $q->where('transaction_date', '<=', $endDate)
                  ->orWhere(function ($sub) use ($endDate) {
                      $sub->whereNull('transaction_date')
                          ->whereDate('created_at', '<=', $endDate);
                  });
            });
        }

        $transactions = $query
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $rows = [];

        // Inisialisasi Akumulator JUMLAH HAL INI (25 Kolom Numerik + Aliases)
        $jumlahHalIni = $this->getEmptyNumericSummaryArray();

        foreach ($transactions as $t) {
            $mappedRow = $this->mapTransactionToTabelarisRow($t);
            $rows[] = $mappedRow;

            // Akumulasi Baris Lembar Berjalan
            foreach (array_keys($jumlahHalIni) as $key) {
                $jumlahHalIni[$key] += (float) ($mappedRow[$key] ?? 0.0);
            }
        }

        // 2. Query SALDO HAL LALU (Kumulatif Transaksi Sebelum $startDate)
        $saldoHalLalu = $this->getEmptyNumericSummaryArray();
        if ($startDate) {
            $cacheVersion = Cache::get('tabelaris_cache_version', '1');
            $cacheKey = "tabelaris_saldo_hal_lalu_{$startDate}_{$cacheVersion}";

            $saldoHalLalu = Cache::remember($cacheKey, now()->addHours(6), function () use ($startDate) {
                $summary = $this->getEmptyNumericSummaryArray();

                // Cek cepat keberadaan transaksi sebelum $startDate (memanfaatkan indeks status & transaction_date)
                $hasPrev = Transaction::query()
                    ->where('status', 'approved')
                    ->where(function ($q) use ($startDate) {
                        $q->where('transaction_date', '<', $startDate)
                          ->orWhere(function ($sub) use ($startDate) {
                              $sub->whereNull('transaction_date')
                                  ->whereDate('created_at', '<', $startDate);
                          });
                    })
                    ->exists();

                if (!$hasPrev) {
                    return $summary;
                }

                return $this->calculateSaldoHalLaluAggregated($startDate);
            });
        }

        // 3. Hitung JUMLAH S/D HAL INI (Baris 1 + Baris 2)
        $jumlahSdHalIni = $this->getEmptyNumericSummaryArray();
        foreach (array_keys($jumlahSdHalIni) as $key) {
            $jumlahSdHalIni[$key] = round(($jumlahHalIni[$key] ?? 0.0) + ($saldoHalLalu[$key] ?? 0.0), 2);
        }

        // Hitung total agregat pengeluaran & pemasukan
        $totalPengeluaranHalIni = round(
            $jumlahHalIni['piutang'] +
            $jumlahHalIni['penarikan_sw'] +
            $jumlahHalIni['penarikan_ss'] +
            $jumlahHalIni['penarikan_sp'] +
            $jumlahHalIni['penarikan_sh'] +
            $jumlahHalIni['penarikan_sd'] +
            $jumlahHalIni['inventaris'] +
            $jumlahHalIni['bank_keluar'] +
            $jumlahHalIni['biaya'],
            2
        );

        $totalPemasukanHalIni = round(
            $jumlahHalIni['dana_dana'] +
            $jumlahHalIni['uang_pangkal'] +
            $jumlahHalIni['simpanan_sp'] +
            $jumlahHalIni['simpanan_sw'] +
            $jumlahHalIni['simpanan_ss'] +
            $jumlahHalIni['simpanan_sh'] +
            $jumlahHalIni['simpanan_sd'] +
            $jumlahHalIni['angsuran_pokok'] +
            $jumlahHalIni['jasa_pinjaman'] +
            $jumlahHalIni['denda_penalti'] +
            $jumlahHalIni['provisi_pinjaman'] +
            $jumlahHalIni['asuransi'] +
            $jumlahHalIni['lain_lain'] +
            $jumlahHalIni['bank_masuk'],
            2
        );

        $totalPengeluaranSdHalIni = round(
            $jumlahSdHalIni['piutang'] +
            $jumlahSdHalIni['penarikan_sw'] +
            $jumlahSdHalIni['penarikan_ss'] +
            $jumlahSdHalIni['penarikan_sp'] +
            $jumlahSdHalIni['penarikan_sh'] +
            $jumlahSdHalIni['penarikan_sd'] +
            $jumlahSdHalIni['inventaris'] +
            $jumlahSdHalIni['bank_keluar'] +
            $jumlahSdHalIni['biaya'],
            2
        );

        $totalPemasukanSdHalIni = round(
            $jumlahSdHalIni['dana_dana'] +
            $jumlahSdHalIni['uang_pangkal'] +
            $jumlahSdHalIni['simpanan_sp'] +
            $jumlahSdHalIni['simpanan_sw'] +
            $jumlahSdHalIni['simpanan_ss'] +
            $jumlahSdHalIni['simpanan_sh'] +
            $jumlahSdHalIni['simpanan_sd'] +
            $jumlahSdHalIni['angsuran_pokok'] +
            $jumlahSdHalIni['jasa_pinjaman'] +
            $jumlahSdHalIni['denda_penalti'] +
            $jumlahSdHalIni['provisi_pinjaman'] +
            $jumlahSdHalIni['asuransi'] +
            $jumlahSdHalIni['lain_lain'] +
            $jumlahSdHalIni['bank_masuk'],
            2
        );

        // Keseimbangan Jurnal Tabelaris:
        // Grand Total Debet = Kas Debet + Total Pengeluaran
        // Grand Total Kredit = Kas Kredit + Total Pemasukan
        $grandTotalDebetHalIni  = round($jumlahHalIni['kas_debet'] + $totalPengeluaranHalIni, 2);
        $grandTotalKreditHalIni = round($jumlahHalIni['kas_kredit'] + $totalPemasukanHalIni, 2);

        $grandTotalDebetSdHalIni  = round($jumlahSdHalIni['kas_debet'] + $totalPengeluaranSdHalIni, 2);
        $grandTotalKreditSdHalIni = round($jumlahSdHalIni['kas_kredit'] + $totalPemasukanSdHalIni, 2);

        $isBalanced = (abs($grandTotalDebetHalIni - $grandTotalKreditHalIni) < 0.05) &&
                      (abs($grandTotalDebetSdHalIni - $grandTotalKreditSdHalIni) < 0.05);

        // 4. KOTAK RINGKASAN SALDO BUKU MANUAL (Kiri Bawah)
        $saldoPiutang = round($jumlahSdHalIni['piutang'] - $jumlahSdHalIni['angsuran_pokok'], 2);
        $saldoBri     = round($jumlahSdHalIni['bank_masuk'] - $jumlahSdHalIni['bank_keluar'], 2);

        $saldoSp      = round($jumlahSdHalIni['simpanan_sp'] - $jumlahSdHalIni['penarikan_sp'], 2);
        $saldoSw      = round($jumlahSdHalIni['simpanan_sw'] - $jumlahSdHalIni['penarikan_sw'], 2);
        $saldoSs      = round($jumlahSdHalIni['simpanan_ss'] - $jumlahSdHalIni['penarikan_ss'], 2);
        $totalSaham   = round($saldoSp + $saldoSw + $saldoSs, 2);

        $saldoSh      = round($jumlahSdHalIni['simpanan_sh'] - $jumlahSdHalIni['penarikan_sh'], 2);
        $saldoSd      = round($jumlahSdHalIni['simpanan_sd'] - $jumlahSdHalIni['penarikan_sd'], 2);
        $saldoKas     = round($jumlahSdHalIni['kas_debet'] - $jumlahSdHalIni['kas_kredit'], 2);

        $totalPendapatanSd = round(
            $jumlahSdHalIni['jasa_pinjaman'] +
            $jumlahSdHalIni['denda_penalti'] +
            $jumlahSdHalIni['provisi_pinjaman'] +
            $jumlahSdHalIni['uang_pangkal'] +
            $jumlahSdHalIni['lain_lain'],
            2
        );
        $totalBebanSd = round($jumlahSdHalIni['biaya'], 2);
        $shuSdHariIni = round($totalPendapatanSd - $totalBebanSd, 2);

        $posisiKeuangan = [
            'saldo_kas'            => $saldoKas,
            'saldo_bri'            => $saldoBri,
            'saldo_piutang'        => $saldoPiutang,
            'saldo_sp'             => $saldoSp,
            'saldo_sw'             => $saldoSw,
            'saldo_ss'             => $saldoSs,
            'total_saham'          => $totalSaham,
            'saldo_sh'             => $saldoSh,
            'saldo_sd'             => $saldoSd,
            'shu_berjalan'         => $shuSdHariIni,
            'saldo_piutang_1024'   => $saldoPiutang,
            'saldo_bri_1010'       => $saldoBri,
            'saldo_kas_1000'       => $saldoKas,
            'saldo_sh_2021'        => $saldoSh,
            'saldo_diakonia_2022'  => $saldoSd,
            'shu_sd_hari_ini'      => $shuSdHariIni,
            'saldo_saham_buku_biru' => [
                'simpanan_pokok_sp'   => $saldoSp,
                'simpanan_wajib_sw'   => $saldoSw,
                'simpanan_sukarela_ss'=> $saldoSs,
                'total_saham'         => $totalSaham,
            ],
        ];

        $balanceStatus = [
            'total_kas_debet'    => $jumlahHalIni['kas_debet'],
            'total_kas_kredit'   => $jumlahHalIni['kas_kredit'],
            'total_pengeluaran'  => $totalPengeluaranHalIni,
            'total_pemasukan'    => $totalPemasukanHalIni,
            'grand_total_debet'  => $grandTotalDebetHalIni,
            'grand_total_kredit' => $grandTotalKreditHalIni,
            'is_balanced'        => $isBalanced,
        ];

        $ringkasanBukuManual = [
            'saldo_piutang_1024'  => $saldoPiutang,
            'saldo_bri_1010'      => $saldoBri,
            'saldo_saham_buku_biru' => [
                'simpanan_pokok_sp'   => $saldoSp,
                'simpanan_wajib_sw'   => $saldoSw,
                'simpanan_sukarela_ss'=> $saldoSs,
                'total_saham'         => $totalSaham,
            ],
            'saldo_sh_2021'       => $saldoSh,
            'saldo_diakonia_2022' => $saldoSd,
            'saldo_kas_1000'      => $saldoKas,
            'shu_sd_hari_ini'     => $shuSdHariIni,
        ];

        $jumlahHalIniMerged = array_merge($jumlahHalIni, [
            'total_pengeluaran'  => $totalPengeluaranHalIni,
            'total_pemasukan'    => $totalPemasukanHalIni,
            'grand_total_debet'  => $grandTotalDebetHalIni,
            'grand_total_kredit' => $grandTotalKreditHalIni,
        ]);

        $saldoHalLaluMerged = array_merge($saldoHalLalu, [
            'total_pengeluaran'  => round($saldoHalLalu['piutang'] + $saldoHalLalu['penarikan_sw'] + $saldoHalLalu['penarikan_ss'] + $saldoHalLalu['penarikan_sp'] + $saldoHalLalu['penarikan_sh'] + $saldoHalLalu['penarikan_sd'] + $saldoHalLalu['inventaris'] + $saldoHalLalu['bank_keluar'] + $saldoHalLalu['biaya'], 2),
            'total_pemasukan'    => round($saldoHalLalu['dana_dana'] + $saldoHalLalu['uang_pangkal'] + $saldoHalLalu['simpanan_sp'] + $saldoHalLalu['simpanan_sw'] + $saldoHalLalu['simpanan_ss'] + $saldoHalLalu['simpanan_sh'] + $saldoHalLalu['simpanan_sd'] + $saldoHalLalu['angsuran_pokok'] + $saldoHalLalu['jasa_pinjaman'] + $saldoHalLalu['denda_penalti'] + $saldoHalLalu['provisi_pinjaman'] + $saldoHalLalu['asuransi'] + $saldoHalLalu['lain_lain'] + $saldoHalLalu['bank_masuk'], 2),
        ]);

        $jumlahSdHalIniMerged = array_merge($jumlahSdHalIni, [
            'total_pengeluaran'  => $totalPengeluaranSdHalIni,
            'total_pemasukan'    => $totalPemasukanSdHalIni,
            'grand_total_debet'  => $grandTotalDebetSdHalIni,
            'grand_total_kredit' => $grandTotalKreditSdHalIni,
        ]);

        $summary = [
            // Baris 1: JUMLAH HAL INI
            'jumlah_hal_ini'            => $jumlahHalIniMerged,

            // Baris 2: SALDO HAL LALU
            'saldo_hal_lalu'            => $saldoHalLaluMerged,

            // Baris 3: JUMLAH S/D HAL INI
            'jumlah_sd_hal_ini'         => $jumlahSdHalIniMerged,

            // Posisi Keuangan & Status Keseimbangan
            'posisi_keuangan'           => $posisiKeuangan,
            'balance_status'            => $balanceStatus,

            // Backward Compatibility Aliases
            'total_kas_debet'           => $jumlahHalIni['kas_debet'],
            'total_kas_kredit'          => $jumlahHalIni['kas_kredit'],
            'total_piutang'             => $jumlahHalIni['piutang'],
            'total_penarikan_sw'        => $jumlahHalIni['penarikan_sw'],
            'total_penarikan_ss'        => $jumlahHalIni['penarikan_ss'],
            'total_penarikan_sp'        => $jumlahHalIni['penarikan_sp'],
            'total_penarikan_sh'        => $jumlahHalIni['penarikan_sh'],
            'total_penarikan_sd'        => $jumlahHalIni['penarikan_sd'],
            'total_inventaris'          => $jumlahHalIni['inventaris'],
            'total_bank_keluar'         => $jumlahHalIni['bank_keluar'],
            'total_biaya'               => $jumlahHalIni['biaya'],
            'total_dana_dana'           => $jumlahHalIni['dana_dana'],
            'total_uang_pangkal'        => $jumlahHalIni['uang_pangkal'],
            'total_simpanan_sp'         => $jumlahHalIni['simpanan_sp'],
            'total_simpanan_sw'         => $jumlahHalIni['simpanan_sw'],
            'total_simpanan_ss'         => $jumlahHalIni['simpanan_ss'],
            'total_simpanan_sh'         => $jumlahHalIni['simpanan_sh'],
            'total_simpanan_sd'         => $jumlahHalIni['simpanan_sd'],
            'total_angsuran_pokok'      => $jumlahHalIni['angsuran_pokok'],
            'total_jasa_pinjaman'       => $jumlahHalIni['jasa_pinjaman'],
            'total_denda_penalti'       => $jumlahHalIni['denda_penalti'],
            'total_provisi_pinjaman'    => $jumlahHalIni['provisi_pinjaman'],
            'total_asuransi'            => $jumlahHalIni['asuransi'],
            'total_lain_lain'           => $jumlahHalIni['lain_lain'],
            'total_bank_masuk'          => $jumlahHalIni['bank_masuk'],
            'total_pengeluaran'         => $totalPengeluaranHalIni,
            'total_pemasukan'           => $totalPemasukanHalIni,
            'grand_total_debet'         => $grandTotalDebetHalIni,
            'grand_total_kredit'        => $grandTotalKreditHalIni,
            'is_balanced'               => $isBalanced,
            'total_rows'                => count($rows),

            // Kotak Info Posisi Keuangan Kiri Bawah
            'ringkasan_buku_manual'     => $ringkasanBukuManual,
        ];

        return [
            'period' => [
                'start_date'   => $startDate,
                'end_date'     => $endDate,
                'period_label' => $periodLabel ?: ($startDate ? "{$startDate} s/d {$endDate}" : 'Semua Periode'),
            ],
            'columns'           => $this->getColumnDefinitions(),
            'rows'              => $rows,
            'transactions'      => $rows,
            'jumlah_hal_ini'    => $jumlahHalIniMerged,
            'saldo_hal_lalu'    => $saldoHalLaluMerged,
            'jumlah_sd_hal_ini' => $jumlahSdHalIniMerged,
            'posisi_keuangan'   => $posisiKeuangan,
            'balance_status'    => $balanceStatus,
            'summary'           => $summary,
        ];
    }

    /**
     * Array inisialisasi numerik kosong untuk 25 kolom transaksi + alias kunci.
     */
    private function getEmptyNumericSummaryArray(): array
    {
        return [
            // PENGELUARAN (9 kolom + Aliases)
            'piutang'          => 0.0,
            'penarikan_sw'     => 0.0,
            'tarik_sw'         => 0.0,
            'penarikan_ss'     => 0.0,
            'tarik_ss'         => 0.0,
            'penarikan_sp'     => 0.0,
            'tarik_sp'         => 0.0,
            'penarikan_sh'     => 0.0,
            'tarik_sh'         => 0.0,
            'penarikan_sd'     => 0.0,
            'tarik_sd'         => 0.0,
            'inventaris'       => 0.0,
            'bank_keluar'      => 0.0,
            'biaya'            => 0.0,

            // KAS (2 kolom)
            'kas_debet'        => 0.0,
            'kas_kredit'       => 0.0,

            // PEMASUKAN (14 kolom + Aliases)
            'dana_dana'        => 0.0,
            'uang_pangkal'     => 0.0,
            'up_pangkal'       => 0.0,
            'simpanan_sp'      => 0.0,
            'simpan_sp'        => 0.0,
            'simpanan_sw'      => 0.0,
            'simpan_sw'        => 0.0,
            'simpanan_ss'      => 0.0,
            'simpan_ss'        => 0.0,
            'simpanan_sh'      => 0.0,
            'simpan_sh'        => 0.0,
            'simpanan_sd'      => 0.0,
            'simpan_sd'        => 0.0,
            'angsuran_pokok'   => 0.0,
            'jasa_pinjaman'    => 0.0,
            'denda_penalti'    => 0.0,
            'denda'            => 0.0,
            'provisi_pinjaman' => 0.0,
            'provisi'          => 0.0,
            'asuransi'         => 0.0,
            'lain_lain'        => 0.0,
            'bank_masuk'       => 0.0,
            'bri_masuk'        => 0.0,
        ];
    }

    /**
     * Memetakan satu record transaksi ke dalam format baris 29 kolom tabelaris (Zero Diff Guarded).
     */
    public function mapTransactionToTabelarisRow(Transaction $t, bool $summaryOnly = false): array
    {
        $type = strtolower($t->type ?? '');
        $isKM = in_array($type, ['deposit', 'in', 'kas_masuk', 'km']);
        $isKK = in_array($type, ['withdrawal', 'out', 'kas_keluar', 'kk']);

        $amount = (float) $t->amount;
        $desc   = strtolower($t->description ?? '');
        $isManager = ($t->member_id === null) || str_contains($desc, '[penyesuaian manajer]') || str_contains($desc, 'penyesuaian manajer');

        $row = [
            'id'                => $t->id,
            'tgl'               => $summaryOnly ? '-' : ($t->transaction_date ? Carbon::parse($t->transaction_date)->format('d/m/Y') : ($t->created_at ? $t->created_at->format('d/m/Y') : '-')),
            'transaction_date'  => $summaryOnly ? null : ($t->transaction_date ? Carbon::parse($t->transaction_date)->format('Y-m-d') : ($t->created_at ? $t->created_at->format('Y-m-d') : null)),
            'no_bukti'          => $summaryOnly ? '-' : ($t->receipt_number ?: ($t->formatted_receipt_no ?: $t->transaction_number)),
            'nba'               => $summaryOnly ? '-' : ($t->member ? ($t->member->member_number ?: '-') : '-'),
            'nama'              => $summaryOnly ? '-' : ($t->member ? $t->member->name : ($t->description ?: '[Penyesuaian Manajer]')),
            'description'       => $t->description,
            'type'              => $t->type,

            // PENGELUARAN (9 kolom: E-M)
            'piutang'           => 0.0,
            'penarikan_sw'      => 0.0,
            'penarikan_ss'      => 0.0,
            'penarikan_sp'      => 0.0,
            'penarikan_sh'      => 0.0,
            'penarikan_sd'      => 0.0,
            'inventaris'        => 0.0,
            'bank_keluar'       => 0.0,
            'biaya'             => 0.0,

            // KAS (2 kolom: N-O)
            'kas_debet'         => $isKM ? $amount : 0.0,
            'kas_kredit'        => $isKK ? $amount : 0.0,

            // PEMASUKAN (14 kolom: P-AC)
            'dana_dana'         => 0.0,
            'uang_pangkal'      => 0.0,
            'simpanan_sp'       => 0.0,
            'simpanan_sw'       => 0.0,
            'simpanan_ss'       => 0.0,
            'simpanan_sh'       => 0.0,
            'simpanan_sd'       => 0.0,
            'angsuran_pokok'    => 0.0,
            'jasa_pinjaman'     => 0.0,
            'denda_penalti'     => 0.0,
            'provisi_pinjaman'  => 0.0,
            'asuransi'          => 0.0,
            'lain_lain'         => 0.0,
            'bank_masuk'        => 0.0,
        ];

        // 1. Jika Transaksi Memiliki Relasi JournalEntry dengan Details Lengkap
        if ($t->journalEntry && $t->journalEntry->details->isNotEmpty()) {
            foreach ($t->journalEntry->details as $d) {
                $accCode = $d->account->account_code ?? '';
                $accType = strtoupper($d->account->account_type ?? '');
                $dDesc   = strtolower($d->description ?: $desc);

                if ($isKM && (float) $d->credit > 0) {
                    $val = (float) $d->credit;
                    $this->allocateKmDetailToColumn($row, $accCode, $accType, $dDesc, $val, $t);
                } elseif ($isKK && (float) $d->debit > 0) {
                    $val = (float) $d->debit;
                    $this->allocateKkDetailToColumn($row, $accCode, $accType, $dDesc, $val, $t);
                }
            }
        } else {
            // 2. Fallback: Mapping langsung dari atribut Transaction jika tidak ada JournalEntry
            if ($isKM) {
                $this->allocateKmDetailToColumn($row, '', '', $desc, $amount, $t);
            } elseif ($isKK) {
                $this->allocateKkDetailToColumn($row, '', '', $desc, $amount, $t);
            }
        }

        // 3. ZERO DIFF GUARD: Verifikasi & Rekonsiliasi Presisi agar Kas dan Pos Alokasi Setara
        if ($isKM && $row['kas_debet'] > 0) {
            $sumPemasukan = round(
                $row['dana_dana'] + $row['uang_pangkal'] + $row['simpanan_sp'] +
                $row['simpanan_sw'] + $row['simpanan_ss'] + $row['simpanan_sh'] + $row['simpanan_sd'] +
                $row['angsuran_pokok'] + $row['jasa_pinjaman'] + $row['denda_penalti'] +
                $row['provisi_pinjaman'] + $row['asuransi'] + $row['lain_lain'] + $row['bank_masuk'],
                2
            );

            $diff = round($row['kas_debet'] - $sumPemasukan, 2);
            if (abs($diff) > 0.001) {
                if ($isManager) {
                    $row['lain_lain'] = round(($row['lain_lain'] ?? 0.0) + $diff, 2);
                } elseif ($row['simpanan_ss'] > 0) {
                    $row['simpanan_ss'] += $diff;
                } elseif ($row['lain_lain'] > 0) {
                    $row['lain_lain'] += $diff;
                } else {
                    $row['simpanan_ss'] = round(($row['simpanan_ss'] ?? 0.0) + $diff, 2);
                }
            }
        }

        if ($isKK && $row['kas_kredit'] > 0) {
            $sumPengeluaran = round(
                $row['piutang'] + $row['penarikan_sw'] + $row['penarikan_ss'] +
                $row['penarikan_sp'] + $row['penarikan_sh'] + $row['penarikan_sd'] +
                $row['inventaris'] + $row['bank_keluar'] + $row['biaya'],
                2
            );

            $diff = round($row['kas_kredit'] - $sumPengeluaran, 2);
            if (abs($diff) > 0.001) {
                if ($isManager) {
                    $row['biaya'] = round(($row['biaya'] ?? 0.0) + $diff, 2);
                } elseif ($row['piutang'] > 0) {
                    $row['piutang'] += $diff;
                } elseif ($row['biaya'] > 0) {
                    $row['biaya'] += $diff;
                } elseif ($row['penarikan_ss'] > 0) {
                    $row['penarikan_ss'] += $diff;
                } else {
                    $row['penarikan_ss'] = round(($row['penarikan_ss'] ?? 0.0) + $diff, 2);
                }
            }
        }

        $row['tarik_sw']   = $row['penarikan_sw'];
        $row['tarik_ss']   = $row['penarikan_ss'];
        $row['tarik_sp']   = $row['penarikan_sp'];
        $row['tarik_sh']   = $row['penarikan_sh'];
        $row['tarik_sd']   = $row['penarikan_sd'];
        $row['up_pangkal'] = $row['uang_pangkal'];
        $row['simpan_sp']  = $row['simpanan_sp'];
        $row['simpan_sw']  = $row['simpanan_sw'];
        $row['simpan_ss']  = $row['simpanan_ss'];
        $row['simpan_sh']  = $row['simpanan_sh'];
        $row['simpan_sd']  = $row['simpanan_sd'];
        $row['denda']      = $row['denda_penalti'];
        $row['provisi']    = $row['provisi_pinjaman'];
        $row['bri_masuk']  = $row['bank_masuk'];

        return $row;
    }

    /**
     * Alokasi detail kredit Kas Masuk (KM) ke kolom Pemasukan.
     */
    private function allocateKmDetailToColumn(array &$row, string $accCode, string $accType, string $desc, float $val, Transaction $t): void
    {
        $descLower = strtolower($desc);
        $isManager = ($t->member_id === null) || str_contains($descLower, '[penyesuaian manajer]') || str_contains($descLower, 'penyesuaian manajer');

        // 1. Uang Pangkal (Akun 4191)
        if ($accCode === '4191' || str_contains($descLower, 'uang pangkal') || str_contains($descLower, 'up. pangkal') || str_contains($descLower, 'pendaftaran')) {
            $row['uang_pangkal'] += $val;
            return;
        }

        // 2. Provisi Pinjaman (Akun 4170)
        if ($accCode === '4170' || str_contains($descLower, 'provisi')) {
            $row['provisi_pinjaman'] += $val;
            return;
        }

        // 3. Denda Keterlambatan Angsuran (Akun 4182 HANYA Denda Angsuran)
        if ($accCode === '4182' || (str_contains($descLower, 'denda') && !str_contains($descLower, 'deviden') && !str_contains($descLower, 'dividen'))) {
            $row['denda_penalti'] += $val;
            return;
        }

        // 4. Jasa Pinjaman (Akun 4180 HANYA Pendapatan Jasa Pinjaman)
        if ($accCode === '4180' || str_contains($descLower, 'jasa pinjaman') || str_contains($descLower, 'bunga pinjaman') || str_contains($descLower, 'jasa angsuran') || str_contains($descLower, 'jasa piutang')) {
            $row['jasa_pinjaman'] += $val;
            return;
        }

        // 5. Angsuran Pokok Pinjaman (Akun 1024)
        if ($accCode === '1024' || str_contains($descLower, 'angsuran piutang') || str_contains($descLower, 'angsuran pokok') || str_contains($descLower, 'pokok pinjaman')) {
            $row['angsuran_pokok'] += $val;
            return;
        }

        // 6. Simpanan Harian / Buku Putih (Akun 2021)
        if ($accCode === '2021' || str_contains($descLower, 'buku putih') || str_contains($descLower, 'simpanan harian') || str_contains($descLower, 'tabungan harian')) {
            $row['simpanan_sh'] += $val;
            return;
        }

        // 7. Simpanan Diakonia (Akun 2022)
        if ($accCode === '2022' || str_contains($descLower, 'diakonia') || str_contains($descLower, 'simp diakonia')) {
            $row['simpanan_sd'] += $val;
            return;
        }

        // 8. Dana Duka / Dana Sosial (Akun 2038 / 2034 HANYA Dana Duka & Dana Sosial)
        if (in_array($accCode, ['2034', '2038']) || str_contains($descLower, 'dana duka') || str_contains($descLower, 'dana sosial') || str_contains($descLower, 'duka') || str_contains($descLower, 'sosial') || trim($descLower) === 'dana') {
            $row['dana_dana'] += $val;
            return;
        }

        // 9. Asuransi Investasi / Asuransi (Akun 2032 / 4193 / 2035 / 2036)
        if (in_array($accCode, ['2032', '4193', '2035', '2036']) || str_contains($descLower, 'asuransi')) {
            $row['asuransi'] += $val;
            return;
        }

        // 10. Bank Masuk / Kas Bank BRI (Akun 1010 / 1011 / 1012)
        if (in_array($accCode, ['1010', '1011', '1012']) || (str_contains($descLower, 'bank') && !str_contains($descLower, 'jasa bank') && !str_contains($descLower, 'bunga bank')) || trim($descLower) === 'bank') {
            $row['bank_masuk'] += $val;
            return;
        }

        // 11. Simpanan Pokok / Wajib / Sukarela (Akun 2020 Buku Biru)
        if (!$isManager && ($accCode === '2020' || str_contains($descLower, 'simpanan pokok') || str_contains($descLower, 'simpanan wajib') || str_contains($descLower, 'simpanan sukarela') || str_contains($descLower, 'saham') || str_contains($descLower, 'buku biru'))) {
            if (str_contains($descLower, 'pokok') || str_contains($descLower, 'sp')) {
                $row['simpanan_sp'] += $val;
            } elseif (str_contains($descLower, 'wajib') || str_contains($descLower, 'sw')) {
                $row['simpanan_sw'] += $val;
            } else {
                $row['simpanan_ss'] += $val;
            }
            return;
        }

        // 12. Lain-lain (Finalty 4183, Denda Deviden 4184, Pendapatan Lain 4192, Jasa Bank 4181, dll)
        if (in_array($accCode, ['4183', '4184', '4192', '4181', '4194'])
            || str_contains($descLower, 'finalty') || str_contains($descLower, 'penalti') || str_contains($descLower, 'penalty')
            || str_contains($descLower, 'deviden') || str_contains($descLower, 'dividen')
            || str_contains($descLower, 'pendapatan') || str_contains($descLower, 'lain')
            || str_starts_with($accCode, '4') || $isManager) {
            $row['lain_lain'] += $val;
            return;
        }

        if ($isManager) {
            $row['lain_lain'] += $val;
        } else {
            $row['simpanan_ss'] += $val;
        }
    }

    /**
     * Alokasi detail debet Kas Keluar (KK) ke kolom Pengeluaran.
     */
    private function allocateKkDetailToColumn(array &$row, string $accCode, string $accType, string $desc, float $val, Transaction $t): void
    {
        $descLower = strtolower($desc);
        $isManager = ($t->member_id === null) || str_contains($descLower, '[penyesuaian manajer]') || str_contains($descLower, 'penyesuaian manajer');

        // ── PRIORITAS 1: Pencairan Pinjaman (Akun 1024) ──────────────────────────────
        if ($accCode === '1024' || str_contains($descLower, 'pencairan pinjaman') || str_contains($descLower, 'pencairan') || str_contains($descLower, 'pinjaman')) {
            $row['piutang'] += $val;
            return;
        }

        // ── PRIORITAS 2: Beban / Biaya Operasional (COA kepala 5 / deskripsi beban) ─
        // Dievaluasi SEBELUM penarikan simpanan agar deskripsi seperti
        // "konsumsi harian" / "Beban Operasional Harian" TIDAK tersedot ke penarikan_sh.
        if (($accCode && str_starts_with($accCode, '5'))
            || str_contains($descLower, 'biaya')
            || str_contains($descLower, 'beban')
            || str_contains($descLower, 'atk')
            || str_contains($descLower, 'alat tulis')
            || str_contains($descLower, 'wifi')
            || str_contains($descLower, 'internet')
            || str_contains($descLower, 'listrik')
            || str_contains($descLower, 'air ')
            || str_contains($descLower, 'bpjs')
            || str_contains($descLower, 'perbaikan')
            || str_contains($descLower, 'komunikasi')
            || str_contains($descLower, 'telekomunikasi')
            || str_contains($descLower, 'transport')
            || str_contains($descLower, 'konsumsi')
            || str_contains($descLower, 'gaji')
            || str_contains($descLower, 'honorarium')
            || str_contains($descLower, 'penyusutan')
            || str_contains($descLower, 'b ') // singkatan "B." atau "B Konsumsi"
            || str_contains($descLower, '[penyesuaian manajer]')) {
            $row['biaya'] += $val;
            return;
        }

        // ── PRIORITAS 3: Penarikan Simpanan Harian / Buku Putih (Akun 2021) ─────────
        // DIPERKETAT: hanya akun 2021 atau frasa spesifik simpanan harian / buku putih.
        // Kata 'harian' saja tidak cukup — mencegah "konsumsi harian" masuk ke sini.
        if (!$isManager && ($accCode === '2021'
            || str_contains($descLower, 'penarikan simpanan harian')
            || str_contains($descLower, 'tarik simpanan harian')
            || str_contains($descLower, 'buku putih')
            || str_contains($descLower, 'simpanan harian penarikan')
            || str_contains($descLower, 'tabungan harian'))) {
            $row['penarikan_sh'] += $val;
            return;
        }

        // ── PRIORITAS 4: Penarikan Simpanan Diakonia (Akun 2022) ─────────────────────
        if (!$isManager && ($accCode === '2022'
            || str_contains($descLower, 'diakonia')
            || str_contains($descLower, 'simpanan diakonia penarikan'))) {
            $row['penarikan_sd'] += $val;
            return;
        }

        // ── PRIORITAS 5: Bank Keluar / Setor ke Bank BRI (Akun 1010) ─────────────────
        if (in_array($accCode, ['1010', '1011', '1012'])
            || str_contains($descLower, 'setor bank')
            || str_contains($descLower, 'bank bri')
            || str_contains($descLower, 'bri')) {
            $row['bank_keluar'] += $val;
            return;
        }

        // ── PRIORITAS 6: Inventaris / Aset (Akun 1030 / 12xx) ────────────────────────
        if ($accCode === '1030' || str_starts_with($accCode, '12')
            || str_contains($descLower, 'inventaris')
            || str_contains($descLower, 'peralatan')
            || str_contains($descLower, 'komputer')) {
            $row['inventaris'] += $val;
            return;
        }

        // ── PRIORITAS 7: Penarikan Simpanan Saham / Buku Biru (Akun 2020) ────────────
        if (!$isManager && ($accCode === '2020'
            || str_contains($descLower, 'penarikan')
            || str_contains($descLower, 'resign')
            || str_contains($descLower, 'simpanan'))) {
            if (str_contains($descLower, 'pokok') || str_contains($descLower, 'sp')) {
                $row['penarikan_sp'] += $val;
            } elseif (str_contains($descLower, 'wajib') || str_contains($descLower, 'sw')) {
                $row['penarikan_sw'] += $val;
            } else {
                $row['penarikan_ss'] += $val;
            }
            return;
        }

        // ── FALLBACK ──────────────────────────────────────────────────────────────────
        if ($isManager) {
            $row['biaya'] += $val;
        } else {
            $row['penarikan_ss'] += $val;
        }
    }

    /**
     * Definisi Metadata 29 Kolom Baku (Sesuai Urutan Buku Manual Koperasi):
     * Kolom 1-4 (IDENTITAS), Kolom 5-13 (PENGELUARAN), Kolom 14-15 (KAS DI TENGAH), Kolom 16-29 (PEMASUKAN).
     */
    public function getColumnDefinitions(): array
    {
        return [
            // IDENTITAS (4 kolom: A-D / 1-4)
            ['key' => 'tgl',               'label' => 'Tgl',            'group' => 'IDENTITAS',   'align' => 'center', 'width' => 90],
            ['key' => 'no_bukti',          'label' => 'No Bukti',       'group' => 'IDENTITAS',   'align' => 'center', 'width' => 120],
            ['key' => 'nba',               'label' => 'NBA',            'group' => 'IDENTITAS',   'align' => 'center', 'width' => 90],
            ['key' => 'nama',              'label' => 'Nama',           'group' => 'IDENTITAS',   'align' => 'left',   'width' => 180],

            // PENGELUARAN (9 kolom: E-M / 5-13)
            ['key' => 'piutang',           'label' => 'Piutang',        'group' => 'PENGELUARAN', 'align' => 'right',  'width' => 110],
            ['key' => 'penarikan_sw',      'label' => 'SW',             'group' => 'PENGELUARAN', 'align' => 'right',  'width' => 100],
            ['key' => 'penarikan_ss',      'label' => 'SS',             'group' => 'PENGELUARAN', 'align' => 'right',  'width' => 100],
            ['key' => 'penarikan_sp',      'label' => 'SP',             'group' => 'PENGELUARAN', 'align' => 'right',  'width' => 100],
            ['key' => 'penarikan_sh',      'label' => 'SH',             'group' => 'PENGELUARAN', 'align' => 'right',  'width' => 100],
            ['key' => 'penarikan_sd',      'label' => 'SD',             'group' => 'PENGELUARAN', 'align' => 'right',  'width' => 100],
            ['key' => 'inventaris',        'label' => 'Inventaris',     'group' => 'PENGELUARAN', 'align' => 'right',  'width' => 110],
            ['key' => 'bank_keluar',       'label' => 'BANK',           'group' => 'PENGELUARAN', 'align' => 'right',  'width' => 110],
            ['key' => 'biaya',             'label' => 'BIAYA',          'group' => 'PENGELUARAN', 'align' => 'right',  'width' => 110],

            // KAS (2 kolom: N-O / 14-15) - DI TENGAH MEMISAHKAN PENGELUARAN & PEMASUKAN
            ['key' => 'kas_debet',         'label' => 'DEBET',          'group' => 'KAS',         'align' => 'right',  'width' => 110],
            ['key' => 'kas_kredit',        'label' => 'KREDIT',         'group' => 'KAS',         'align' => 'right',  'width' => 110],

            // PEMASUKAN (14 kolom: P-AC / 16-29)
            ['key' => 'dana_dana',         'label' => 'Dana Dana',      'group' => 'PEMASUKAN',   'align' => 'right',  'width' => 100],
            ['key' => 'uang_pangkal',      'label' => 'Up',             'group' => 'PEMASUKAN',   'align' => 'right',  'width' => 100],
            ['key' => 'simpanan_sp',       'label' => 'SP',             'group' => 'PEMASUKAN',   'align' => 'right',  'width' => 100],
            ['key' => 'simpanan_sw',       'label' => 'SW',             'group' => 'PEMASUKAN',   'align' => 'right',  'width' => 100],
            ['key' => 'simpanan_ss',       'label' => 'SS',             'group' => 'PEMASUKAN',   'align' => 'right',  'width' => 100],
            ['key' => 'simpanan_sh',       'label' => 'SH',             'group' => 'PEMASUKAN',   'align' => 'right',  'width' => 100],
            ['key' => 'simpanan_sd',       'label' => 'SD',             'group' => 'PEMASUKAN',   'align' => 'right',  'width' => 100],
            ['key' => 'angsuran_pokok',    'label' => 'Ang pinj',       'group' => 'PEMASUKAN',   'align' => 'right',  'width' => 110],
            ['key' => 'jasa_pinjaman',     'label' => 'Jasa Pinj',      'group' => 'PEMASUKAN',   'align' => 'right',  'width' => 110],
            ['key' => 'denda_penalti',     'label' => 'Denda & Pinlty', 'group' => 'PEMASUKAN',   'align' => 'right',  'width' => 110],
            ['key' => 'provisi_pinjaman',  'label' => 'Prov Pinj',      'group' => 'PEMASUKAN',   'align' => 'right',  'width' => 110],
            ['key' => 'asuransi',          'label' => 'Asuransi',       'group' => 'PEMASUKAN',   'align' => 'right',  'width' => 100],
            ['key' => 'lain_lain',         'label' => 'Lain2',          'group' => 'PEMASUKAN',   'align' => 'right',  'width' => 100],
            ['key' => 'bank_masuk',        'label' => 'BRI',            'group' => 'PEMASUKAN',   'align' => 'right',  'width' => 110],
        ];
    }

    /**
     * Generator File Excel (.xlsx) Native 29 Kolom Presisi Sesuai Format Buku Manual Koperasi.
     */
    public function generateExcelSpreadsheet(?string $startDate = null, ?string $endDate = null, ?string $periodLabel = null): Spreadsheet
    {
        $data = $this->generateTabelaris($startDate, $endDate, $periodLabel);
        $periodText = $data['period']['period_label'];

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Jurnal Tabelaris');

        // Set Page Setup Landscape A3
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A3);

        // 1. KOP SURAT (Row 1 - 3)
        $sheet->setCellValue('A1', 'KOPERASI SIMPAN PINJAM (KSP) CUM PELITA');
        $sheet->setCellValue('A2', 'JURNAL HARIAN CUM PELITA HKBP RESS. DAME DURI');
        $sheet->setCellValue('A3', 'PERIODE: ' . strtoupper($periodText));

        $sheet->mergeCells('A1:AC1');
        $sheet->mergeCells('A2:AC2');
        $sheet->mergeCells('A3:AC3');

        $sheet->getStyle('A1')->getFont()->setSize(14)->setBold(true)->setName('Arial');
        $sheet->getStyle('A2')->getFont()->setSize(12)->setBold(true)->setName('Arial');
        $sheet->getStyle('A3')->getFont()->setSize(10)->setItalic(true)->setName('Arial');

        $sheet->getStyle('A1:AC3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // 2. HEADER TINGKAT 1 (Row 5: Identitas, Pengeluaran, KAS DI TENGAH, Pemasukan)
        $sheet->setCellValue('A5', 'IDENTITAS');
        $sheet->setCellValue('E5', 'PENGELUARAN');
        $sheet->setCellValue('N5', 'KAS');
        $sheet->setCellValue('P5', 'PEMASUKAN');

        $sheet->mergeCells('A5:D5');
        $sheet->mergeCells('E5:M5');
        $sheet->mergeCells('N5:O5');
        $sheet->mergeCells('P5:AC5');

        // 3. HEADER TINGKAT 2 (Row 6 - 29 Kolom Subheader)
        $headers = [
            'A' => 'Tgl',
            'B' => 'No Bukti',
            'C' => 'NBA',
            'D' => 'Nama',
            'E' => 'Piutang',
            'F' => 'SW',
            'G' => 'SS',
            'H' => 'SP',
            'I' => 'SH',
            'J' => 'SD',
            'K' => 'Inventaris',
            'L' => 'BANK',
            'M' => 'BIAYA',
            'N' => 'DEBET',
            'O' => 'KREDIT',
            'P' => 'Dana Dana',
            'Q' => 'Up',
            'R' => 'SP',
            'S' => 'SW',
            'T' => 'SS',
            'U' => 'SH',
            'V' => 'SD',
            'W' => 'Ang pinj',
            'X' => 'Jasa Pinj',
            'Y' => 'Denda & Pinlty',
            'Z' => 'Prov Pinj',
            'AA'=> 'Asuransi',
            'AB'=> 'Lain2',
            'AC'=> 'BRI',
        ];

        foreach ($headers as $col => $title) {
            $sheet->setCellValue("{$col}6", $title);
        }

        // Styling Group Headers (Row 5 & 6)
        $headerGroupStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]
            ]
        ];

        // Background Group Colors
        $sheet->getStyle('A5:D5')->applyFromArray($headerGroupStyle);
        $sheet->getStyle('A5:D5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2C3E50'); // Navy

        $sheet->getStyle('E5:M5')->applyFromArray($headerGroupStyle);
        $sheet->getStyle('E5:M5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('C0392B'); // Dark Red

        $sheet->getStyle('N5:O5')->applyFromArray($headerGroupStyle);
        $sheet->getStyle('N5:O5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('27AE60'); // Emerald Green (Kas di tengah)

        $sheet->getStyle('P5:AC5')->applyFromArray($headerGroupStyle);
        $sheet->getStyle('P5:AC5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2980B9'); // Ocean Blue

        // Row 6 Subheader Style
        $subHeaderStyle = [
            'font' => ['bold' => true, 'size' => 9],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
                'wrapText'   => true,
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'EAEDED'],
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]
            ]
        ];
        $sheet->getStyle('A6:AC6')->applyFromArray($subHeaderStyle);
        $sheet->getRowDimension(5)->setRowHeight(24);
        $sheet->getRowDimension(6)->setRowHeight(22);

        // 4. DATA ROWS (Mulai Row 7)
        $currentRow = 7;
        foreach ($data['rows'] as $r) {
            $sheet->setCellValue("A{$currentRow}", $r['tgl']);
            $sheet->setCellValue("B{$currentRow}", $r['no_bukti']);
            $sheet->setCellValue("C{$currentRow}", $r['nba']);
            $sheet->setCellValue("D{$currentRow}", $r['nama']);

            // Pengeluaran (E-M)
            $sheet->setCellValue("E{$currentRow}", $r['piutang']);
            $sheet->setCellValue("F{$currentRow}", $r['penarikan_sw']);
            $sheet->setCellValue("G{$currentRow}", $r['penarikan_ss']);
            $sheet->setCellValue("H{$currentRow}", $r['penarikan_sp']);
            $sheet->setCellValue("I{$currentRow}", $r['penarikan_sh']);
            $sheet->setCellValue("J{$currentRow}", $r['penarikan_sd']);
            $sheet->setCellValue("K{$currentRow}", $r['inventaris']);
            $sheet->setCellValue("L{$currentRow}", $r['bank_keluar']);
            $sheet->setCellValue("M{$currentRow}", $r['biaya']);

            // Kas di tengah (N-O)
            $sheet->setCellValue("N{$currentRow}", $r['kas_debet']);
            $sheet->setCellValue("O{$currentRow}", $r['kas_kredit']);

            // Pemasukan (P-AC)
            $sheet->setCellValue("P{$currentRow}", $r['dana_dana']);
            $sheet->setCellValue("Q{$currentRow}", $r['uang_pangkal']);
            $sheet->setCellValue("R{$currentRow}", $r['simpanan_sp']);
            $sheet->setCellValue("S{$currentRow}", $r['simpanan_sw']);
            $sheet->setCellValue("T{$currentRow}", $r['simpanan_ss']);
            $sheet->setCellValue("U{$currentRow}", $r['simpanan_sh']);
            $sheet->setCellValue("V{$currentRow}", $r['simpanan_sd']);
            $sheet->setCellValue("W{$currentRow}", $r['angsuran_pokok']);
            $sheet->setCellValue("X{$currentRow}", $r['jasa_pinjaman']);
            $sheet->setCellValue("Y{$currentRow}", $r['denda_penalti']);
            $sheet->setCellValue("Z{$currentRow}", $r['provisi_pinjaman']);
            $sheet->setCellValue("AA{$currentRow}", $r['asuransi']);
            $sheet->setCellValue("AB{$currentRow}", $r['lain_lain']);
            $sheet->setCellValue("AC{$currentRow}", $r['bank_masuk']);

            $currentRow++;
        }

        $lastDataRow = $currentRow - 1;

        // Styling Data Range
        if ($lastDataRow >= 7) {
            $dataRange = "A7:AC{$lastDataRow}";
            $sheet->getStyle($dataRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D5D8DC');
            $sheet->getStyle("A7:C{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("D7:D{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle("E7:AC{$lastDataRow}")->getNumberFormat()->setFormatCode('#,##0.00;(#,##0.00);"-"');
        }

        // 5. BARIS AKUMULASI 3 TINGKAT STANDAR BUKU MANUAL

        // 5a. Baris 1: JUMLAH HAL INI
        $r1 = $currentRow;
        $sheet->setCellValue("A{$r1}", 'JUMLAH HAL INI');
        $sheet->mergeCells("A{$r1}:D{$r1}");

        $colsMap = [
            'E' => 'piutang', 'F' => 'penarikan_sw', 'G' => 'penarikan_ss', 'H' => 'penarikan_sp', 'I' => 'penarikan_sh', 'J' => 'penarikan_sd', 'K' => 'inventaris', 'L' => 'bank_keluar', 'M' => 'biaya',
            'N' => 'kas_debet', 'O' => 'kas_kredit',
            'P' => 'dana_dana', 'Q' => 'uang_pangkal', 'R' => 'simpanan_sp', 'S' => 'simpanan_sw', 'T' => 'simpanan_ss', 'U' => 'simpanan_sh', 'V' => 'simpanan_sd', 'W' => 'angsuran_pokok', 'X' => 'jasa_pinjaman', 'Y' => 'denda_penalti', 'Z' => 'provisi_pinjaman', 'AA' => 'asuransi', 'AB' => 'lain_lain', 'AC' => 'bank_masuk'
        ];

        foreach ($colsMap as $col => $key) {
            if ($lastDataRow >= 7) {
                $sheet->setCellValue("{$col}{$r1}", "=SUM({$col}7:{$col}{$lastDataRow})");
            } else {
                $sheet->setCellValue("{$col}{$r1}", 0);
            }
        }

        // 5b. Baris 2: SALDO HAL LALU
        $r2 = $currentRow + 1;
        $sheet->setCellValue("A{$r2}", 'SALDO HAL LALU');
        $sheet->mergeCells("A{$r2}:D{$r2}");

        $saldoLalu = $data['summary']['saldo_hal_lalu'];
        foreach ($colsMap as $col => $key) {
            $sheet->setCellValue("{$col}{$r2}", $saldoLalu[$key] ?? 0.0);
        }

        // 5c. Baris 3: JUMLAH S/D HAL INI
        $r3 = $currentRow + 2;
        $sheet->setCellValue("A{$r3}", 'JUMLAH S/D HAL INI');
        $sheet->mergeCells("A{$r3}:D{$r3}");

        foreach ($colsMap as $col => $key) {
            $sheet->setCellValue("{$col}{$r3}", "={$col}{$r1}+{$col}{$r2}");
        }

        // Styling Footer Rows (r1, r2, r3)
        $footerStyle = [
            'font' => ['bold' => true, 'size' => 9],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'EAECEE'],
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]
            ]
        ];

        $grandFooterStyle = [
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '000000']],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'D6DBDF'],
            ],
            'borders' => [
                'top'    => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']],
                'bottom' => ['borderStyle' => Border::BORDER_DOUBLE, 'color' => ['rgb' => '000000']],
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]
            ]
        ];

        $sheet->getStyle("A{$r1}:AC{$r2}")->applyFromArray($footerStyle);
        $sheet->getStyle("A{$r3}:AC{$r3}")->applyFromArray($grandFooterStyle);

        $sheet->getStyle("A{$r1}:A{$r3}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("E{$r1}:AC{$r3}")->getNumberFormat()->setFormatCode('#,##0.00;(#,##0.00);"-"');

        // 6. KOTAK RINGKASAN SALDO BUKU MANUAL & VERIFIKASI KESEIMBANGAN (Row r3 + 2)
        $boxRow = $r3 + 2;

        // KIRI: Kotak Saldo Buku Manual
        $manualBox = $data['summary']['ringkasan_buku_manual'];

        $sheet->setCellValue("B{$boxRow}", 'KOTAK INFO POSISI KEUANGAN (RINGKASAN SALDO BUKU MANUAL)');
        $sheet->getStyle("B{$boxRow}")->getFont()->setBold(true)->setSize(11);

        $sheet->setCellValue("B" . ($boxRow + 1), '1. Saldo Piutang (Akun 1024):');
        $sheet->setCellValue("D" . ($boxRow + 1), $manualBox['saldo_piutang_1024']);

        $sheet->setCellValue("B" . ($boxRow + 2), '2. Saldo BRI (Akun 1010):');
        $sheet->setCellValue("D" . ($boxRow + 2), $manualBox['saldo_bri_1010']);

        $sheet->setCellValue("B" . ($boxRow + 3), '3. Saldo Saham (SP + SW + SS):');
        $sheet->setCellValue("D" . ($boxRow + 3), $manualBox['saldo_saham_buku_biru']['total_saham']);

        $sheet->setCellValue("B" . ($boxRow + 4), '   • Simpanan Pokok (SP):');
        $sheet->setCellValue("D" . ($boxRow + 4), $manualBox['saldo_saham_buku_biru']['simpanan_pokok_sp']);

        $sheet->setCellValue("B" . ($boxRow + 5), '   • Simpanan Wajib (SW):');
        $sheet->setCellValue("D" . ($boxRow + 5), $manualBox['saldo_saham_buku_biru']['simpanan_wajib_sw']);

        $sheet->setCellValue("B" . ($boxRow + 6), '   • Simpanan Sukarela (SS):');
        $sheet->setCellValue("D" . ($boxRow + 6), $manualBox['saldo_saham_buku_biru']['simpanan_sukarela_ss']);

        $sheet->setCellValue("B" . ($boxRow + 7), '4. Saldo SH (Simpanan Harian / Buku Putih):');
        $sheet->setCellValue("D" . ($boxRow + 7), $manualBox['saldo_sh_2021']);

        $sheet->setCellValue("B" . ($boxRow + 8), '5. Saldo Diakonia (Akun 2022):');
        $sheet->setCellValue("D" . ($boxRow + 8), $manualBox['saldo_diakonia_2022']);

        $sheet->setCellValue("B" . ($boxRow + 9), '6. Saldo KAS (Akun 1000):');
        $sheet->setCellValue("D" . ($boxRow + 9), $manualBox['saldo_kas_1000']);

        $sheet->setCellValue("B" . ($boxRow + 10), '7. SHU s/d Hari Ini (Laba Berjalan):');
        $sheet->setCellValue("D" . ($boxRow + 10), $manualBox['shu_sd_hari_ini']);

        $sheet->getStyle("D" . ($boxRow + 1) . ":D" . ($boxRow + 10))->getNumberFormat()->setFormatCode('#,##0.00');

        // KANAN: Verification Box Zero Diff Guard
        $sheet->setCellValue("F{$boxRow}", 'REKAPITULASI KESEIMBANGAN JURNAL TABELARIS:');
        $sheet->getStyle("F{$boxRow}")->getFont()->setBold(true)->setSize(11);

        $sheet->setCellValue("F" . ($boxRow + 1), 'Total Kas Debet:');
        $sheet->setCellValue("H" . ($boxRow + 1), "=N{$r3}");
        $sheet->setCellValue("F" . ($boxRow + 2), 'Total Pengeluaran:');
        $sheet->setCellValue("H" . ($boxRow + 2), "=SUM(E{$r3}:M{$r3})");
        $sheet->setCellValue("F" . ($boxRow + 3), 'GRAND TOTAL DEBET:');
        $sheet->setCellValue("H" . ($boxRow + 3), "=H" . ($boxRow + 1) . "+H" . ($boxRow + 2));

        $sheet->setCellValue("J" . ($boxRow + 1), 'Total Kas Kredit:');
        $sheet->setCellValue("L" . ($boxRow + 1), "=O{$r3}");
        $sheet->setCellValue("J" . ($boxRow + 2), 'Total Pemasukan:');
        $sheet->setCellValue("L" . ($boxRow + 2), "=SUM(P{$r3}:AC{$r3})");
        $sheet->setCellValue("J" . ($boxRow + 3), 'GRAND TOTAL KREDIT:');
        $sheet->setCellValue("L" . ($boxRow + 3), "=L" . ($boxRow + 1) . "+L" . ($boxRow + 2));

        $sheet->setCellValue("F" . ($boxRow + 5), 'STATUS KESEIMBANGAN:');
        $sheet->setCellValue("H" . ($boxRow + 5), '=IF(ABS(H' . ($boxRow + 3) . '-L' . ($boxRow + 3) . ')<0.01,"SEIMBANG (ZERO DIFF GUARD PASS)","ADA SELISIH")');

        $sheet->getStyle("F" . ($boxRow + 3) . ":H" . ($boxRow + 3))->getFont()->setBold(true);
        $sheet->getStyle("J" . ($boxRow + 3) . ":L" . ($boxRow + 3))->getFont()->setBold(true);
        $sheet->getStyle("H" . ($boxRow + 1) . ":H" . ($boxRow + 3))->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("L" . ($boxRow + 1) . ":L" . ($boxRow + 3))->getNumberFormat()->setFormatCode('#,##0.00');

        // Auto-fit Columns
        foreach (range('A', 'Z') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('AA')->setAutoSize(true);
        $sheet->getColumnDimension('AB')->setAutoSize(true);
        $sheet->getColumnDimension('AC')->setAutoSize(true);

        return $spreadsheet;
    }

    /**
     * Hitung Saldo Hal Lalu secara agregasi SQL langsung tanpa iterasi model di memori PHP.
     */
    private function calculateSaldoHalLaluAggregated(string $startDate): array
    {
        $summary = $this->getEmptyNumericSummaryArray();

        // 1. Agregasi Kas Debet dan Kas Kredit langsung dari tabel transactions
        $kasAgg = DB::table('transactions')
            ->where('status', 'approved')
            ->where(function ($q) use ($startDate) {
                $q->where('transaction_date', '<', $startDate)
                  ->orWhere(function ($sub) use ($startDate) {
                      $sub->whereNull('transaction_date')
                          ->whereDate('created_at', '<', $startDate);
                  });
            })
            ->selectRaw("
                COALESCE(SUM(CASE WHEN LOWER(type) IN ('deposit', 'in', 'kas_masuk', 'km') THEN amount ELSE 0 END), 0) as kas_debet,
                COALESCE(SUM(CASE WHEN LOWER(type) IN ('withdrawal', 'out', 'kas_keluar', 'kk') THEN amount ELSE 0 END), 0) as kas_kredit
            ")
            ->first();

        $summary['kas_debet']  = (float) ($kasAgg->kas_debet ?? 0.0);
        $summary['kas_kredit'] = (float) ($kasAgg->kas_kredit ?? 0.0);

        // 2. Agregasi jurnal journal_details joined journal_entries & transactions
        $journalAgg = DB::table('journal_details')
            ->join('journal_entries', 'journal_details.journal_entry_id', '=', 'journal_entries.id')
            ->leftJoin('chart_of_accounts', 'journal_details.account_id', '=', 'chart_of_accounts.id')
            ->leftJoin('transactions', 'journal_entries.transaction_id', '=', 'transactions.id')
            ->where(function ($q) use ($startDate) {
                $q->where('journal_entries.entry_date', '<', $startDate)
                  ->orWhere(function ($sub) use ($startDate) {
                      $sub->whereNull('journal_entries.entry_date')
                          ->where('transactions.transaction_date', '<', $startDate);
                  });
            })
            ->where(function ($q) {
                $q->where('transactions.status', 'approved')
                  ->orWhereNull('transactions.id');
            })
            ->selectRaw("
                COALESCE(chart_of_accounts.account_code, '') as account_code,
                LOWER(COALESCE(journal_details.description, transactions.description, '')) as detail_desc,
                LOWER(COALESCE(transactions.type, '')) as trx_type,
                transactions.member_id,
                COALESCE(SUM(journal_details.debit), 0) as total_debit,
                COALESCE(SUM(journal_details.credit), 0) as total_credit
            ")
            ->groupBy('chart_of_accounts.account_code', 'detail_desc', 'trx_type', 'transactions.member_id')
            ->get();

        foreach ($journalAgg as $j) {
            $code  = trim((string) $j->account_code);
            $desc  = (string) $j->detail_desc;
            $type  = (string) $j->trx_type;
            $isKM  = in_array($type, ['deposit', 'in', 'kas_masuk', 'km']);
            $isKK  = in_array($type, ['withdrawal', 'out', 'kas_keluar', 'kk']);
            $isMgr = ($j->member_id === null) || str_contains($desc, 'penyesuaian manajer');

            // Alokasi Pemasukan (Credit)
            $cVal = (float) $j->total_credit;
            if ($cVal > 0) {
                if ($code === '4191' || str_contains($desc, 'uang pangkal') || str_contains($desc, 'pendaftaran')) {
                    $summary['uang_pangkal'] += $cVal;
                } elseif ($code === '4170' || str_contains($desc, 'provisi')) {
                    $summary['provisi_pinjaman'] += $cVal;
                } elseif ($code === '4182' || (str_contains($desc, 'denda') && !str_contains($desc, 'deviden') && !str_contains($desc, 'dividen'))) {
                    $summary['denda_penalti'] += $cVal;
                } elseif ($code === '4180' || str_contains($desc, 'jasa pinjaman') || str_contains($desc, 'bunga pinjaman')) {
                    $summary['jasa_pinjaman'] += $cVal;
                } elseif ($code === '1024' || str_contains($desc, 'angsuran')) {
                    $summary['angsuran_pokok'] += $cVal;
                } elseif ($code === '2021' || str_contains($desc, 'buku putih') || str_contains($desc, 'simpanan harian')) {
                    $summary['simpanan_sh'] += $cVal;
                } elseif ($code === '2022' || str_contains($desc, 'diakonia')) {
                    $summary['simpanan_sd'] += $cVal;
                } elseif (in_array($code, ['2034', '2038']) || str_contains($desc, 'dana duka') || str_contains($desc, 'dana sosial')) {
                    $summary['dana_dana'] += $cVal;
                } elseif (in_array($code, ['2032', '4193', '2035', '2036']) || str_contains($desc, 'asuransi')) {
                    $summary['asuransi'] += $cVal;
                } elseif (in_array($code, ['1010', '1011', '1012']) || (str_contains($desc, 'bank') && !str_contains($desc, 'jasa bank'))) {
                    $summary['bank_masuk'] += $cVal;
                } elseif (!$isMgr && ($code === '2020' || str_contains($desc, 'simpanan pokok') || str_contains($desc, 'simpanan wajib') || str_contains($desc, 'saham') || str_contains($desc, 'buku biru'))) {
                    if (str_contains($desc, 'pokok') || str_contains($desc, 'sp')) {
                        $summary['simpanan_sp'] += $cVal;
                    } elseif (str_contains($desc, 'wajib') || str_contains($desc, 'sw')) {
                        $summary['simpanan_sw'] += $cVal;
                    } else {
                        $summary['simpanan_ss'] += $cVal;
                    }
                } else {
                    $summary['lain_lain'] += $cVal;
                }
            }

            // Alokasi Pengeluaran (Debit)
            $dVal = (float) $j->total_debit;
            if ($dVal > 0) {
                if ($code === '1024' || str_contains($desc, 'pencairan pinjaman') || str_contains($desc, 'pinjaman')) {
                    $summary['piutang'] += $dVal;
                } elseif (str_starts_with($code, '5') || str_contains($desc, 'biaya') || str_contains($desc, 'beban') || str_contains($desc, 'atk') || str_contains($desc, 'gaji') || str_contains($desc, 'internet') || str_contains($desc, 'listrik') || $isMgr) {
                    $summary['biaya'] += $dVal;
                } elseif ($code === '2021' || str_contains($desc, 'buku putih') || str_contains($desc, 'simpanan harian')) {
                    $summary['penarikan_sh'] += $dVal;
                } elseif ($code === '2022' || str_contains($desc, 'diakonia')) {
                    $summary['penarikan_sd'] += $dVal;
                } elseif (in_array($code, ['1030', '1700', '1741', '1743']) || str_contains($desc, 'inventaris')) {
                    $summary['inventaris'] += $dVal;
                } elseif (in_array($code, ['1010', '1011', '1012']) || str_contains($desc, 'bank')) {
                    $summary['bank_keluar'] += $dVal;
                } elseif ($code === '2020' || str_contains($desc, 'penarikan')) {
                    if (str_contains($desc, 'pokok') || str_contains($desc, 'sp')) {
                        $summary['penarikan_sp'] += $dVal;
                    } elseif (str_contains($desc, 'wajib') || str_contains($desc, 'sw')) {
                        $summary['penarikan_sw'] += $dVal;
                    } else {
                        $summary['penarikan_ss'] += $dVal;
                    }
                } else {
                    $summary['biaya'] += $dVal;
                }
            }
        }

        // 3. Fallback transaksi approved yang belum tercatat di tabel journal_entries
        $orphanTrxs = DB::table('transactions')
            ->where('status', 'approved')
            ->where(function ($q) use ($startDate) {
                $q->where('transaction_date', '<', $startDate)
                  ->orWhere(function ($sub) use ($startDate) {
                      $sub->whereNull('transaction_date')
                          ->whereDate('created_at', '<', $startDate);
                  });
            })
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('journal_entries')
                    ->whereColumn('journal_entries.transaction_id', 'transactions.id');
            })
            ->selectRaw("
                LOWER(COALESCE(type, '')) as trx_type,
                LOWER(COALESCE(category, '')) as trx_cat,
                LOWER(COALESCE(book_type, '')) as book_type,
                LOWER(COALESCE(description, '')) as trx_desc,
                member_id,
                COALESCE(SUM(amount), 0) as total_amount
            ")
            ->groupBy('trx_type', 'trx_cat', 'book_type', 'trx_desc', 'member_id')
            ->get();

        foreach ($orphanTrxs as $ot) {
            $amt  = (float) $ot->total_amount;
            $type = (string) $ot->trx_type;
            $cat  = (string) $ot->trx_cat;
            $bType= (string) $ot->book_type;
            $desc = (string) $ot->trx_desc;
            $isKM = in_array($type, ['deposit', 'in', 'kas_masuk', 'km']);
            $isKK = in_array($type, ['withdrawal', 'out', 'kas_keluar', 'kk']);

            if ($isKM) {
                if ($cat === 'uang_pangkal' || str_contains($desc, 'uang pangkal')) {
                    $summary['uang_pangkal'] += $amt;
                } elseif ($bType === 'buku_putih' || $cat === 'buku_putih' || str_contains($desc, 'buku putih')) {
                    $summary['simpanan_sh'] += $amt;
                } elseif (str_contains($desc, 'pokok') || $cat === 'simpanan_pokok') {
                    $summary['simpanan_sp'] += $amt;
                } elseif (str_contains($desc, 'wajib') || $cat === 'simpanan_wajib') {
                    $summary['simpanan_sw'] += $amt;
                } elseif ($bType === 'buku_biru' || str_contains($desc, 'sukarela') || $cat === 'simpanan_sukarela') {
                    $summary['simpanan_ss'] += $amt;
                } else {
                    $summary['lain_lain'] += $amt;
                }
            } elseif ($isKK) {
                if ($cat === 'pinjaman' || str_contains($desc, 'pinjaman')) {
                    $summary['piutang'] += $amt;
                } elseif ($bType === 'buku_putih' || str_contains($desc, 'buku putih')) {
                    $summary['penarikan_sh'] += $amt;
                } elseif (str_contains($desc, 'pokok')) {
                    $summary['penarikan_sp'] += $amt;
                } elseif (str_contains($desc, 'wajib')) {
                    $summary['penarikan_sw'] += $amt;
                } elseif ($bType === 'buku_biru' || str_contains($desc, 'sukarela')) {
                    $summary['penarikan_ss'] += $amt;
                } else {
                    $summary['biaya'] += $amt;
                }
            }
        }

        // 4. Sinkronisasi Aliases Kunci
        $summary['tarik_sw']   = $summary['penarikan_sw'];
        $summary['tarik_ss']   = $summary['penarikan_ss'];
        $summary['tarik_sp']   = $summary['penarikan_sp'];
        $summary['tarik_sh']   = $summary['penarikan_sh'];
        $summary['tarik_sd']   = $summary['penarikan_sd'];
        $summary['up_pangkal'] = $summary['uang_pangkal'];
        $summary['simpan_sp']  = $summary['simpanan_sp'];
        $summary['simpan_sw']  = $summary['simpanan_sw'];
        $summary['simpan_ss']  = $summary['simpanan_ss'];
        $summary['simpan_sh']  = $summary['simpanan_sh'];
        $summary['simpan_sd']  = $summary['simpanan_sd'];
        $summary['denda']      = $summary['denda_penalti'];
        $summary['provisi']    = $summary['provisi_pinjaman'];
        $summary['bri_masuk']  = $summary['bank_masuk'];

        // 5. Zero Diff Guard untuk Kas Debet & Kas Kredit Saldo Hal Lalu
        $sumPemasukan = round(
            $summary['dana_dana'] + $summary['uang_pangkal'] + $summary['simpanan_sp'] +
            $summary['simpanan_sw'] + $summary['simpanan_ss'] + $summary['simpanan_sh'] + $summary['simpanan_sd'] +
            $summary['angsuran_pokok'] + $summary['jasa_pinjaman'] + $summary['denda_penalti'] +
            $summary['provisi_pinjaman'] + $summary['asuransi'] + $summary['lain_lain'] + $summary['bank_masuk'],
            2
        );
        $diffKm = round($summary['kas_debet'] - $sumPemasukan, 2);
        if (abs($diffKm) > 0.001) {
            $summary['lain_lain'] = round($summary['lain_lain'] + $diffKm, 2);
        }

        $sumPengeluaran = round(
            $summary['piutang'] + $summary['penarikan_sw'] + $summary['penarikan_ss'] +
            $summary['penarikan_sp'] + $summary['penarikan_sh'] + $summary['penarikan_sd'] +
            $summary['inventaris'] + $summary['bank_keluar'] + $summary['biaya'],
            2
        );
        $diffKk = round($summary['kas_kredit'] - $sumPengeluaran, 2);
        if (abs($diffKk) > 0.001) {
            $summary['biaya'] = round($summary['biaya'] + $diffKk, 2);
        }

        foreach (array_keys($summary) as $k) {
            $summary[$k] = round((float) $summary[$k], 2);
        }

        return $summary;
    }
}
