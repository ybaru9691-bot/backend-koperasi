@php
    $logoPath = public_path('images/logo_koperasi.png');
    if (!file_exists($logoPath)) {
        $logoPath = public_path('images/logo.png');
    }
    if (!file_exists($logoPath)) {
        $logoPath = storage_path('app/public/logo_koperasi.png');
    }
    if (!file_exists($logoPath)) {
        $logoPath = storage_path('app/public/logo.png');
    }
    $logoBase64 = '';
    if (file_exists($logoPath)) {
        $logoData = file_get_contents($logoPath);
        $mime = pathinfo($logoPath, PATHINFO_EXTENSION) === 'png' ? 'image/png' : 'image/jpeg';
        $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode($logoData);
    }

    $periodTitle = $period_label ?? 'Semua Periode';
    $rowsList    = $rows ?? [];
    $halIni      = $jumlah_hal_ini ?? [];
    $halLalu     = $saldo_hal_lalu ?? [];
    $sdHalIni    = $jumlah_sd_hal_ini ?? [];
    $posKeu      = $ringkasan_buku_manual ?? [];
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Jurnal Tabelaris 29 Kolom - {{ $periodTitle }}</title>
    <style>
        @page {
            size: a3 landscape;
            margin: 8mm 8mm 8mm 8mm;
        }
        body {
            font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif;
            font-size: 7px;
            color: #000000;
            background-color: #ffffff;
            margin: 0;
            padding: 0;
            line-height: 1.15;
        }
        thead { display: table-header-group; }
        tfoot { display: table-footer-group; }
        tr { page-break-inside: avoid; }

        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }
        .header-table td {
            vertical-align: middle;
            padding: 0;
        }

        .kop-title {
            font-size: 13px;
            font-weight: bold;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin: 0;
        }
        .kop-sub {
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            margin: 2px 0 0 0;
        }

        .table-tabelaris {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 6.5px;
        }
        .table-tabelaris th, .table-tabelaris td {
            border: 1px solid #000000;
            padding: 2px 2px;
            word-wrap: break-word;
            overflow: hidden;
        }
        .table-tabelaris th {
            background-color: #f2f2f2;
            color: #000000;
            font-weight: bold;
            text-align: center;
            text-transform: uppercase;
        }
        .bg-pengeluaran { background-color: #fcf4f4; }
        .bg-kas { background-color: #f0f7ff; }
        .bg-pemasukan { background-color: #f4fcf4; }

        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .font-bold { font-weight: bold; }

        .row-total td {
            font-weight: bold;
            background-color: #f9f9f9;
        }
        .row-grand-total td {
            font-weight: bold;
            background-color: #e8f0fe;
            border-top: 1.5px solid #000 !important;
            border-bottom: 2px double #000 !important;
        }

        .summary-box {
            margin-top: 12px;
            width: 45%;
            border: 1.5px solid #000;
            border-collapse: collapse;
            font-size: 7.5px;
        }
        .summary-box th, .summary-box td {
            border: 1px solid #666;
            padding: 3px 6px;
        }
        .summary-box th {
            background-color: #e9ecef;
            text-align: left;
        }
    </style>
</head>
<body>

    <!-- KOP LAPORAN -->
    <table class="header-table">
        <tr>
            <td style="width: 10%; text-align: left;">
                @if(!empty($logoBase64))
                    <img src="{{ $logoBase64 }}" style="width: 42px; height: auto;" alt="Logo">
                @endif
            </td>
            <td style="width: 80%; text-align: center;">
                <div class="kop-title">KSP CREDO UNION MODIFIKASI PELITA HKBP RESSORT DAME</div>
                <div class="kop-sub">JURNAL TABELARIS 29 KOLOM MANUAL KOPERASI</div>
                <div style="font-size: 9px; font-weight: bold; margin-top: 3px;">Periode: {{ $periodTitle }}</div>
            </td>
            <td style="width: 10%; text-align: right; font-size: 7px;">
                Dicetak: {{ date('d/m/Y H:i') }}
            </td>
        </tr>
    </table>

    <!-- TABEL 29 KOLOM -->
    <table class="table-tabelaris">
        <thead>
            <tr>
                <th colspan="4" style="width: 14%;">IDENTITAS</th>
                <th colspan="9" class="bg-pengeluaran" style="width: 33%;">PENGELUARAN</th>
                <th colspan="2" class="bg-kas" style="width: 8%;">KAS</th>
                <th colspan="14" class="bg-pemasukan" style="width: 45%;">PEMASUKAN</th>
            </tr>
            <tr>
                <!-- Identitas -->
                <th style="width: 2.5%;">Tgl</th>
                <th style="width: 3.5%;">No Bukti</th>
                <th style="width: 3.0%;">NBA</th>
                <th style="width: 5.0%;">Nama / Ket</th>

                <!-- Pengeluaran -->
                <th class="bg-pengeluaran" style="width: 3.8%;">Piutang</th>
                <th class="bg-pengeluaran" style="width: 3.5%;">Pnk SW</th>
                <th class="bg-pengeluaran" style="width: 3.5%;">Pnk SS</th>
                <th class="bg-pengeluaran" style="width: 3.5%;">Pnk SP</th>
                <th class="bg-pengeluaran" style="width: 3.5%;">Pnk SH</th>
                <th class="bg-pengeluaran" style="width: 3.5%;">Pnk SD</th>
                <th class="bg-pengeluaran" style="width: 3.8%;">Inventaris</th>
                <th class="bg-pengeluaran" style="width: 3.8%;">BANK (BRI)</th>
                <th class="bg-pengeluaran" style="width: 3.6%;">BIAYA</th>

                <!-- Kas -->
                <th class="bg-kas" style="width: 4.0%;">DEBET (KM)</th>
                <th class="bg-kas" style="width: 4.0%;">KREDIT (KK)</th>

                <!-- Pemasukan -->
                <th class="bg-pemasukan" style="width: 3.2%;">Dana2</th>
                <th class="bg-pemasukan" style="width: 2.8%;">Up</th>
                <th class="bg-pemasukan" style="width: 3.2%;">SP</th>
                <th class="bg-pemasukan" style="width: 3.2%;">SW</th>
                <th class="bg-pemasukan" style="width: 3.2%;">SS</th>
                <th class="bg-pemasukan" style="width: 3.2%;">SH</th>
                <th class="bg-pemasukan" style="width: 3.2%;">SD</th>
                <th class="bg-pemasukan" style="width: 3.5%;">Ang Pinj</th>
                <th class="bg-pemasukan" style="width: 3.2%;">Jasa Pinj</th>
                <th class="bg-pemasukan" style="width: 3.2%;">Denda</th>
                <th class="bg-pemasukan" style="width: 3.2%;">Prov</th>
                <th class="bg-pemasukan" style="width: 3.0%;">Asuransi</th>
                <th class="bg-pemasukan" style="width: 3.0%;">Lain2</th>
                <th class="bg-pemasukan" style="width: 3.5%;">BRI</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rowsList as $r)
                <tr>
                    <td class="text-center">{{ isset($r['tgl']) ? date('d/m', strtotime($r['tgl'])) : '' }}</td>
                    <td>{{ $r['no_bukti'] ?? '' }}</td>
                    <td class="text-center">{{ $r['nba'] ?? '' }}</td>
                    <td>{{ $r['nama'] ?? '' }}</td>

                    <!-- Pengeluaran -->
                    <td class="text-right">{{ $r['piutang'] > 0 ? number_format($r['piutang'], 0, ',', '.') : '-' }}</td>
                    <td class="text-right">{{ $r['penarikan_sw'] > 0 ? number_format($r['penarikan_sw'], 0, ',', '.') : '-' }}</td>
                    <td class="text-right">{{ $r['penarikan_ss'] > 0 ? number_format($r['penarikan_ss'], 0, ',', '.') : '-' }}</td>
                    <td class="text-right">{{ $r['penarikan_sp'] > 0 ? number_format($r['penarikan_sp'], 0, ',', '.') : '-' }}</td>
                    <td class="text-right">{{ $r['penarikan_sh'] > 0 ? number_format($r['penarikan_sh'], 0, ',', '.') : '-' }}</td>
                    <td class="text-right">{{ $r['penarikan_sd'] > 0 ? number_format($r['penarikan_sd'], 0, ',', '.') : '-' }}</td>
                    <td class="text-right">{{ $r['inventaris'] > 0 ? number_format($r['inventaris'], 0, ',', '.') : '-' }}</td>
                    <td class="text-right">{{ $r['bank_keluar'] > 0 ? number_format($r['bank_keluar'], 0, ',', '.') : '-' }}</td>
                    <td class="text-right">{{ $r['biaya'] > 0 ? number_format($r['biaya'], 0, ',', '.') : '-' }}</td>

                    <!-- Kas -->
                    <td class="text-right font-bold">{{ $r['kas_debet'] > 0 ? number_format($r['kas_debet'], 0, ',', '.') : '-' }}</td>
                    <td class="text-right font-bold">{{ $r['kas_kredit'] > 0 ? number_format($r['kas_kredit'], 0, ',', '.') : '-' }}</td>

                    <!-- Pemasukan -->
                    <td class="text-right">{{ $r['dana_dana'] > 0 ? number_format($r['dana_dana'], 0, ',', '.') : '-' }}</td>
                    <td class="text-right">{{ $r['uang_pangkal'] > 0 ? number_format($r['uang_pangkal'], 0, ',', '.') : '-' }}</td>
                    <td class="text-right">{{ $r['simpanan_sp'] > 0 ? number_format($r['simpanan_sp'], 0, ',', '.') : '-' }}</td>
                    <td class="text-right">{{ $r['simpanan_sw'] > 0 ? number_format($r['simpanan_sw'], 0, ',', '.') : '-' }}</td>
                    <td class="text-right">{{ $r['simpanan_ss'] > 0 ? number_format($r['simpanan_ss'], 0, ',', '.') : '-' }}</td>
                    <td class="text-right">{{ $r['simpanan_sh'] > 0 ? number_format($r['simpanan_sh'], 0, ',', '.') : '-' }}</td>
                    <td class="text-right">{{ $r['simpanan_sd'] > 0 ? number_format($r['simpanan_sd'], 0, ',', '.') : '-' }}</td>
                    <td class="text-right">{{ $r['angsuran_pokok'] > 0 ? number_format($r['angsuran_pokok'], 0, ',', '.') : '-' }}</td>
                    <td class="text-right">{{ $r['jasa_pinjaman'] > 0 ? number_format($r['jasa_pinjaman'], 0, ',', '.') : '-' }}</td>
                    <td class="text-right">{{ $r['denda_penalti'] > 0 ? number_format($r['denda_penalti'], 0, ',', '.') : '-' }}</td>
                    <td class="text-right">{{ $r['provisi_pinjaman'] > 0 ? number_format($r['provisi_pinjaman'], 0, ',', '.') : '-' }}</td>
                    <td class="text-right">{{ $r['asuransi'] > 0 ? number_format($r['asuransi'], 0, ',', '.') : '-' }}</td>
                    <td class="text-right">{{ $r['lain_lain'] > 0 ? number_format($r['lain_lain'], 0, ',', '.') : '-' }}</td>
                    <td class="text-right">{{ $r['bank_masuk'] > 0 ? number_format($r['bank_masuk'], 0, ',', '.') : '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="29" class="text-center">Tidak ada data transaksi pada periode ini.</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <!-- 1. JUMLAH HAL INI -->
            <tr class="row-total">
                <td colspan="4" class="text-left font-bold">JUMLAH HAL INI</td>
                <td class="text-right">{{ number_format($halIni['piutang'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halIni['penarikan_sw'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halIni['penarikan_ss'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halIni['penarikan_sp'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halIni['penarikan_sh'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halIni['penarikan_sd'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halIni['inventaris'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halIni['bank_keluar'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halIni['biaya'] ?? 0, 0, ',', '.') }}</td>

                <td class="text-right">{{ number_format($halIni['kas_debet'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halIni['kas_kredit'] ?? 0, 0, ',', '.') }}</td>

                <td class="text-right">{{ number_format($halIni['dana_dana'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halIni['uang_pangkal'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halIni['simpanan_sp'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halIni['simpanan_sw'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halIni['simpanan_ss'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halIni['simpanan_sh'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halIni['simpanan_sd'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halIni['angsuran_pokok'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halIni['jasa_pinjaman'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halIni['denda_penalti'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halIni['provisi_pinjaman'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halIni['asuransi'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halIni['lain_lain'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halIni['bank_masuk'] ?? 0, 0, ',', '.') }}</td>
            </tr>

            <!-- 2. SALDO HAL LALU -->
            <tr class="row-total">
                <td colspan="4" class="text-left font-bold">SALDO HAL LALU</td>
                <td class="text-right">{{ number_format($halLalu['piutang'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halLalu['penarikan_sw'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halLalu['penarikan_ss'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halLalu['penarikan_sp'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halLalu['penarikan_sh'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halLalu['penarikan_sd'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halLalu['inventaris'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halLalu['bank_keluar'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halLalu['biaya'] ?? 0, 0, ',', '.') }}</td>

                <td class="text-right">{{ number_format($halLalu['kas_debet'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halLalu['kas_kredit'] ?? 0, 0, ',', '.') }}</td>

                <td class="text-right">{{ number_format($halLalu['dana_dana'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halLalu['uang_pangkal'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halLalu['simpanan_sp'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halLalu['simpanan_sw'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halLalu['simpanan_ss'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halLalu['simpanan_sh'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halLalu['simpanan_sd'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halLalu['angsuran_pokok'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halLalu['jasa_pinjaman'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halLalu['denda_penalti'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halLalu['provisi_pinjaman'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halLalu['asuransi'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halLalu['lain_lain'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($halLalu['bank_masuk'] ?? 0, 0, ',', '.') }}</td>
            </tr>

            <!-- 3. JUMLAH S/D HAL INI -->
            <tr class="row-grand-total">
                <td colspan="4" class="text-left font-bold">JUMLAH S/D HAL INI</td>
                <td class="text-right">{{ number_format($sdHalIni['piutang'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($sdHalIni['penarikan_sw'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($sdHalIni['penarikan_ss'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($sdHalIni['penarikan_sp'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($sdHalIni['penarikan_sh'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($sdHalIni['penarikan_sd'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($sdHalIni['inventaris'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($sdHalIni['bank_keluar'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($sdHalIni['biaya'] ?? 0, 0, ',', '.') }}</td>

                <td class="text-right">{{ number_format($sdHalIni['kas_debet'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($sdHalIni['kas_kredit'] ?? 0, 0, ',', '.') }}</td>

                <td class="text-right">{{ number_format($sdHalIni['dana_dana'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($sdHalIni['uang_pangkal'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($sdHalIni['simpanan_sp'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($sdHalIni['simpanan_sw'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($sdHalIni['simpanan_ss'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($sdHalIni['simpanan_sh'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($sdHalIni['simpanan_sd'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($sdHalIni['angsuran_pokok'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($sdHalIni['jasa_pinjaman'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($sdHalIni['denda_penalti'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($sdHalIni['provisi_pinjaman'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($sdHalIni['asuransi'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($sdHalIni['lain_lain'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($sdHalIni['bank_masuk'] ?? 0, 0, ',', '.') }}</td>
            </tr>
        </tfoot>
    </table>

    <!-- KOTAK RINGKASAN POSISI KEUANGAN (BUKU MANUAL) -->
    @if(!empty($posKeu))
        <table class="summary-box">
            <thead>
                <tr>
                    <th colspan="2" style="font-weight: bold; text-align: center;">POSISI KEUANGAN & RINGKASAN SALDO (BUKU MANUAL)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="width: 60%;">1. Saldo Piutang (Akun 1024)</td>
                    <td style="width: 40%; text-align: right; font-weight: bold;">Rp {{ number_format($posKeu['saldo_piutang'] ?? 0, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td>2. Saldo Bank BRI (Akun 1010)</td>
                    <td style="text-align: right; font-weight: bold;">Rp {{ number_format($posKeu['saldo_bri'] ?? 0, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td>3. Total Saham (SP + SW + SS)</td>
                    <td style="text-align: right; font-weight: bold;">Rp {{ number_format($posKeu['saldo_saham']['total_saham'] ?? 0, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td>4. Saldo Simpanan Harian / Buku Putih (Akun 2021)</td>
                    <td style="text-align: right; font-weight: bold;">Rp {{ number_format($posKeu['saldo_sh'] ?? 0, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td>5. Saldo Simpanan Diakonia (Akun 2022)</td>
                    <td style="text-align: right; font-weight: bold;">Rp {{ number_format($posKeu['saldo_diakonia'] ?? 0, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td>6. Saldo Kas Tunai (Akun 1000)</td>
                    <td style="text-align: right; font-weight: bold;">Rp {{ number_format($posKeu['saldo_kas'] ?? 0, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td>7. SHU Berjalan s/d Hari Ini (Laba/Rugi)</td>
                    <td style="text-align: right; font-weight: bold;">Rp {{ number_format($posKeu['shu_berjalan'] ?? 0, 0, ',', '.') }}</td>
                </tr>
            </tbody>
        </table>
    @endif

</body>
</html>
