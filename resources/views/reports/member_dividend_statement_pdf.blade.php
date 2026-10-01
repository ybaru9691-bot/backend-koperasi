@php
    $logoPath = public_path('images/logo.png');
    if (!file_exists($logoPath)) {
        $logoPath = public_path('images/logo_koperasi.png');
    }
    $logoBase64 = '';
    if (file_exists($logoPath)) {
        $logoData = file_get_contents($logoPath);
        $logoBase64 = 'data:image/png;base64,' . base64_encode($logoData);
    }
    $monthly_rows = $monthly_records ?? $monthly_rows ?? [];
    $benchmarks = $monthly_parameters ?? $coop_benchmarks ?? $benchmarks ?? [];
    $summary_results = $rekapitulasi ?? $summary_results ?? [];
    $year = $fiscal_period_label ?? $fiscal_year ?? $year ?? date('Y');
    $saldo_awal_data = $saldo_awal ?? null;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Lembar Saham & Deviden - {{ $member['name'] ?? 'Anggota' }}</title>
    <style>
        @page {
            margin: 6mm 7mm 6mm 7mm;
            size: a4 portrait;
        }
        body {
            font-family: 'DejaVu Sans', 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 7.2px;
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
            margin-bottom: 8px;
        }
        .header-title h1 {
            margin: 0;
            font-size: 11px;
            color: #000000;
            font-weight: bold;
            letter-spacing: 0.2px;
        }
        .header-title h2 {
            margin: 1px 0 0 0;
            font-size: 9px;
            color: #000000;
            font-weight: bold;
        }
        .member-info-box {
            font-size: 8px;
            line-height: 1.35;
            width: 100%;
            border-collapse: collapse;
        }
        .member-info-box td {
            padding: 1px 2px;
            vertical-align: top;
        }
        .table-main {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-top: 4px;
            font-size: 6.8px;
        }
        .table-main th {
            background-color: #ffffff;
            color: #000000;
            font-weight: bold;
            text-align: center;
            border: 1px solid #000000;
            padding: 2.5px 1px;
            text-transform: uppercase;
        }
        .table-main td {
            border: 1px solid #000000;
            padding: 2px 2px;
            word-wrap: break-word;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        
        .footer-total td {
            font-weight: bold;
            border-top: 1.5px solid #000000 !important;
            border-bottom: 1.5px solid #000000 !important;
        }
        
        .bottom-section {
            width: 100%;
            margin-top: 4px;
        }
        .bottom-table-container {
            width: 100%;
            border-collapse: collapse;
        }
        .benchmark-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 6.3px;
        }
        .benchmark-table th {
            background-color: #ffffff;
            color: #000000;
            font-weight: bold;
            text-align: center;
            border: 1px solid #000000;
            padding: 2px 1px;
        }
        .benchmark-table td {
            border: 1px solid #000000;
            padding: 1.5px 2px;
            text-align: right;
        }
        
        .recap-box {
            border: 1px solid #000000;
            padding: 4px 6px;
            background-color: #ffffff;
        }
        .recap-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 7.5px;
        }
        .recap-table td {
            padding: 2px 2px;
        }
        .recap-highlight td {
            border-top: 1px solid #000000;
            font-weight: bold;
            font-size: 9px;
            padding-top: 4px;
        }
        
        .signature-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            page-break-inside: avoid;
        }
        .signature-table td.sign-space {
            width: 65%;
        }
        .signature-table td.sign-cell {
            width: 35%;
            text-align: center;
            vertical-align: top;
            padding: 2px;
            font-size: 7.5px;
        }
        .sign-title {
            font-weight: bold;
            margin-bottom: 30px;
        }
        .sign-name {
            font-weight: bold;
            border-top: 1px dotted #000000;
            display: inline-block;
            padding-top: 2px;
            min-width: 140px;
        }
    </style>
</head>
<body>
    <!-- 1. KOP & IDENTITAS ANGGOTA -->
    <table class="header-table">
        <tr>
            <td style="width: 8%; text-align: left; vertical-align: middle;">
                @if(!empty($logoBase64))
                    <img src="{{ $logoBase64 }}" style="width: 38px; height: auto;" alt="Logo">
                @endif
            </td>
            <td class="header-title" style="width: 48%; padding-left: 4px; vertical-align: middle;">
                <h1>CREDO UNION MODIFIKASI PELITA</h1>
                <h2>HKBP RESSORT DAME</h2>
            </td>
            <td style="width: 44%; text-align: right; vertical-align: top;">
                <table class="member-info-box">
                    <tr>
                        <td style="width: 30%; text-align: left; font-weight: bold; white-space: nowrap;">Nama</td>
                        <td style="width: 4%; text-align: center;">:</td>
                        <td style="width: 66%; text-align: left; font-weight: bold;">{{ strtoupper($member['name'] ?? '-') }}</td>
                    </tr>
                    <tr>
                        <td style="text-align: left; font-weight: bold; white-space: nowrap;">No.Anggota</td>
                        <td style="text-align: center;">:</td>
                        <td style="text-align: left; font-weight: bold;">{{ $member['member_number'] ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td style="text-align: left; font-weight: bold; white-space: nowrap;">Alamat</td>
                        <td style="text-align: center;">:</td>
                        <td style="text-align: left;">{{ (!empty($member['address']) && $member['address'] !== '-') ? $member['address'] : (!empty($member['church_sector']) ? $member['church_sector'] : ($member['alamat'] ?? '-')) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- 2. TABEL UTAMA MUTASI SAHAM ANGGOTA (12 BULAN) -->
    <table class="table-main">
        <thead>
            <tr>
                <th rowspan="2" style="width: 8%;">Tanggal</th>
                <th rowspan="2" style="width: 8.5%;">No. Bukti</th>
                <th colspan="3" style="width: 19.5%;">SETORAN</th>
                <th colspan="3" style="width: 15%;">PENARIKAN</th>
                <th colspan="3" style="width: 22%;">SALDO</th>
                <th rowspan="2" style="width: 9%;">TOTAL SAHAM</th>
                <th rowspan="2" style="width: 8%;">JASA</th>
                <th rowspan="2" style="width: 10%;">DEVIDEN</th>
            </tr>
            <tr>
                <!-- SETORAN -->
                <th style="width: 6.5%;">SW</th>
                <th style="width: 6.5%;">SS</th>
                <th style="width: 6.5%;">SP</th>
                <!-- PENARIKAN -->
                <th style="width: 5%;">SW</th>
                <th style="width: 5%;">SS</th>
                <th style="width: 5%;">SP</th>
                <!-- SALDO -->
                <th style="width: 7.3%;">SW</th>
                <th style="width: 7.3%;">SS</th>
                <th style="width: 7.4%;">SP</th>
            </tr>
        </thead>
        <tbody>
            <!-- BARIS SALDO AWAL -->
            @if(!empty($saldo_awal_data))
            <tr>
                <td align="center" class="text-center"></td>
                <td align="center" class="text-center"></td>
                <td align="right" class="text-right"></td>
                <td align="right" class="text-right"></td>
                <td align="right" class="text-right"></td>
                <td align="right" class="text-right"></td>
                <td align="right" class="text-right"></td>
                <td align="right" class="text-right"></td>
                <td align="right" class="text-right">{{ number_format($saldo_awal_data['saldo_sw'] ?? 0, 0, ',', '.') }}</td>
                <td align="right" class="text-right">{{ number_format($saldo_awal_data['saldo_ss'] ?? 0, 0, ',', '.') }}</td>
                <td align="right" class="text-right">{{ number_format($saldo_awal_data['saldo_sp'] ?? 0, 0, ',', '.') }}</td>
                <td align="right" class="text-right" style="font-weight: bold;">{{ number_format($saldo_awal_data['total_saham'] ?? 0, 0, ',', '.') }}</td>
                <td align="right" class="text-right"></td>
                <td align="right" class="text-right"></td>
            </tr>
            @endif

            @php
                $totSetorSw = 0; $totSetorSs = 0; $totSetorSp = 0;
                $totTarikSw = 0; $totTarikSs = 0; $totTarikSp = 0;
                $totJasa = 0; $totDev = 0;
                $lastSw = !empty($saldo_awal_data) ? ($saldo_awal_data['saldo_sw'] ?? 0) : 0;
                $lastSs = !empty($saldo_awal_data) ? ($saldo_awal_data['saldo_ss'] ?? 0) : 0;
                $lastSp = !empty($saldo_awal_data) ? ($saldo_awal_data['saldo_sp'] ?? 0) : 0;
                $lastSaham = !empty($saldo_awal_data) ? ($saldo_awal_data['total_saham'] ?? 0) : 0;
            @endphp

            @foreach($monthly_rows as $row)
                @if(!empty($row['is_saldo_awal']))
                    @continue
                @endif
                @php
                    $setorSw = (float)($row['setoran_sw'] ?? 0);
                    $setorSs = (float)($row['setoran_ss'] ?? 0);
                    $setorSp = (float)($row['setoran_sp'] ?? 0);
                    $tarikSw = (float)($row['penarikan_sw'] ?? 0);
                    $tarikSs = (float)($row['penarikan_ss'] ?? 0);
                    $tarikSp = (float)($row['penarikan_sp'] ?? 0);
                    $saldoSw = (float)($row['saldo_sw'] ?? 0);
                    $saldoSs = (float)($row['saldo_ss'] ?? 0);
                    $saldoSp = (float)($row['saldo_sp'] ?? 0);
                    $totSaham = (float)($row['total_saham'] ?? 0);
                    $jasa = (float)($row['jasa_saham'] ?? 0);
                    $dev = (float)($row['deviden'] ?? 0);

                    $totSetorSw += $setorSw; $totSetorSs += $setorSs; $totSetorSp += $setorSp;
                    $totTarikSw += $tarikSw; $totTarikSs += $tarikSs; $totTarikSp += $tarikSp;
                    $totJasa += $jasa; $totDev += $dev;
                    $lastSw = $saldoSw; $lastSs = $saldoSs; $lastSp = $saldoSp; $lastSaham = $totSaham;
                @endphp
                <tr>
                    <td align="center" class="text-center">{{ $row['transaction_date'] ?? $row['tgl'] ?? $row['month_name'] ?? '-' }}</td>
                    <td align="center" class="text-center">{{ $row['voucher_no'] ?? '-' }}</td>
                    <td align="right" class="text-right">{{ $setorSw > 0 ? number_format($setorSw, 0, ',', '.') : '' }}</td>
                    <td align="right" class="text-right">{{ $setorSs > 0 ? number_format($setorSs, 0, ',', '.') : '' }}</td>
                    <td align="right" class="text-right">{{ $setorSp > 0 ? number_format($setorSp, 0, ',', '.') : '' }}</td>
                    <td align="right" class="text-right">{{ $tarikSw > 0 ? number_format($tarikSw, 0, ',', '.') : '' }}</td>
                    <td align="right" class="text-right">{{ $tarikSs > 0 ? number_format($tarikSs, 0, ',', '.') : '' }}</td>
                    <td align="right" class="text-right">{{ $tarikSp > 0 ? number_format($tarikSp, 0, ',', '.') : '' }}</td>
                    <td align="right" class="text-right">{{ number_format($saldoSw, 0, ',', '.') }}</td>
                    <td align="right" class="text-right">{{ number_format($saldoSs, 0, ',', '.') }}</td>
                    <td align="right" class="text-right">{{ number_format($saldoSp, 0, ',', '.') }}</td>
                    <td align="right" class="text-right" style="font-weight: bold;">{{ number_format($totSaham, 0, ',', '.') }}</td>
                    <td align="right" class="text-right">{{ $jasa > 0 ? number_format($jasa, 0, ',', '.') : '0' }}</td>
                    <td align="right" class="text-right">{{ $dev > 0 ? number_format($dev, 0, ',', '.') : '0' }}</td>
                </tr>
            @endforeach

            <!-- BARIS JUMLAH / TOTAL -->
            <tr class="footer-total">
                <td colspan="2" align="center" class="text-center">JUMLAH</td>
                <td align="right" class="text-right">{{ number_format($totSetorSw, 0, ',', '.') }}</td>
                <td align="right" class="text-right">{{ number_format($totSetorSs, 0, ',', '.') }}</td>
                <td align="right" class="text-right">{{ number_format($totSetorSp, 0, ',', '.') }}</td>
                <td align="right" class="text-right">{{ number_format($totTarikSw, 0, ',', '.') }}</td>
                <td align="right" class="text-right">{{ number_format($totTarikSs, 0, ',', '.') }}</td>
                <td align="right" class="text-right">{{ number_format($totTarikSp, 0, ',', '.') }}</td>
                <td align="right" class="text-right">{{ number_format($lastSw, 0, ',', '.') }}</td>
                <td align="right" class="text-right">{{ number_format($lastSs, 0, ',', '.') }}</td>
                <td align="right" class="text-right">{{ number_format($lastSp, 0, ',', '.') }}</td>
                <td align="right" class="text-right" style="font-weight: bold;">{{ number_format($lastSaham, 0, ',', '.') }}</td>
                <td align="right" class="text-right">{{ number_format($totJasa, 0, ',', '.') }}</td>
                <td align="right" class="text-right">{{ number_format($totDev, 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    <!-- 3. BAGIAN BAWAH (TABEL PATOKAN KOPERASI & KOTAK REKAPITULASI) -->
    <div class="bottom-section">
        <table class="bottom-table-container">
            <tr>
                <!-- TABEL PATOKAN 12 BULAN KOPERASI -->
                <td style="width: 63%; vertical-align: top; padding-right: 4px;">
                    <table class="benchmark-table">
                        <thead>
                            <tr>
                                <th style="width: 9%;">Bulan</th>
                                <th style="width: 22%;">Total Saham</th>
                                <th style="width: 27%;">SHU setelah dikurangi biaya tiap thn</th>
                                <th style="width: 14%;">25% SHU</th>
                                <th style="width: 16%;">Harga Saham (Total Saham/1000)</th>
                                <th style="width: 12%;">Harga Saham E30xD30</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $lastSahamKop = 0; $totShuKop = 0; $totDanaDev = 0; $lastLembarKop = 0;
                            @endphp
                            @foreach($benchmarks as $bm)
                                @php
                                    $sahamKop = (float)($bm['total_saham'] ?? $bm['total_saham_koperasi'] ?? $bm['total_coop_shares'] ?? 0);
                                    $shuKop = (float)($bm['shu_setelah_biaya'] ?? $bm['shu_bersih_koperasi'] ?? $bm['net_income'] ?? 0);
                                    $danaDev = (float)($bm['shu_deviden_pool'] ?? $bm['dana_deviden_25'] ?? $bm['dana_deviden'] ?? 0);
                                    $lembarKop = (float)($bm['jumlah_lembar_koperasi'] ?? ($sahamKop > 0 ? round($sahamKop / 1000.0, 2) : 0));
                                    $hargaDev = (float)($bm['harga_saham_deviden'] ?? $bm['harga_deviden_per_lembar'] ?? 0);
                                    $rateDisplay = (int)($bm['harga_saham_display'] ?? round($hargaDev));
                                    $lastSahamKop = $sahamKop;
                                    $totShuKop += $shuKop;
                                    $totDanaDev += $danaDev;
                                    $lastLembarKop = $lembarKop;
                                @endphp
                                <tr>
                                    <td class="text-center" style="font-weight: bold;">{{ strtoupper($bm['month_name'] ?? '-') }}</td>
                                    <td>{{ number_format($sahamKop, 0, ',', '.') }}</td>
                                    <td>{{ number_format($shuKop, 0, ',', '.') }}</td>
                                    <td>{{ number_format($danaDev, 0, ',', '.') }}</td>
                                    <td>{{ number_format($lembarKop, 0, ',', '.') }}</td>
                                    <td class="text-center" style="font-weight: bold;">{{ $rateDisplay }}</td>
                                </tr>
                            @endforeach
                            <tr style="font-weight: bold; border-top: 1.5px solid #000000;">
                                <td class="text-center">JML</td>
                                <td>{{ number_format($lastSahamKop, 0, ',', '.') }}</td>
                                <td>{{ number_format($totShuKop, 0, ',', '.') }}</td>
                                <td>{{ number_format($totDanaDev, 0, ',', '.') }}</td>
                                <td>{{ number_format($lastLembarKop, 0, ',', '.') }}</td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </td>

                <!-- KOTAK REKAPITULASI DITERIMA ANGGOTA -->
                <td style="width: 37%; vertical-align: top; padding-left: 4px;">
                    <div class="recap-box">
                        <table class="recap-table">
                            <tr>
                                <td style="width: 48%;">Total Saham</td>
                                <td style="width: 4%;">:</td>
                                <td class="text-right" style="font-weight: bold;">Rp {{ number_format($summary_results['total_saham'] ?? $lastSaham, 0, ',', '.') }}</td>
                            </tr>
                            <tr>
                                <td>Jasa Saham (0.6%)</td>
                                <td>:</td>
                                <td class="text-right">Rp {{ number_format($summary_results['total_jasa_saham'] ?? $totJasa, 0, ',', '.') }}</td>
                            </tr>
                            <tr>
                                <td>Deviden Saham</td>
                                <td>:</td>
                                <td class="text-right">Rp {{ number_format($summary_results['total_deviden'] ?? $totDev, 0, ',', '.') }}</td>
                            </tr>
                            <tr class="recap-highlight">
                                <td>TOTAL SHU DITERIMA</td>
                                <td>:</td>
                                <td class="text-right">Rp {{ number_format($summary_results['total_shu_diterima'] ?? ($totJasa + $totDev), 0, ',', '.') }}</td>
                            </tr>
                        </table>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <!-- 4. TANDA TANGAN -->
    <table class="signature-table">
        <tr>
            <td class="sign-space"></td>
            <td class="sign-cell">
                <div class="sign-title">Diterima Oleh,<br>Anggota Bersangkutan</div>
                <div class="sign-name">{{ strtoupper($member['name'] ?? 'Anggota') }}</div>
            </td>
        </tr>
    </table>
</body>
</html>