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
    $allCycles = $cycles ?? $monthly_records ?? $months ?? [];
    $sum = $summary ?? [];
    $mem = $member ?? [];
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Buku Tabungan Harian - {{ $mem['name'] ?? 'Anggota' }}</title>
    <style>
        @page {
            margin: 8mm 9mm 8mm 9mm;
            size: a4 portrait;
        }
        body {
            font-family: 'DejaVu Sans', 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 8px;
            color: #111827;
            background-color: #ffffff;
            margin: 0;
            padding: 0;
            line-height: 1.2;
        }
        thead { display: table-header-group; }
        tfoot { display: table-footer-group; }
        tr { page-break-inside: avoid; }

        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
            border-bottom: 2px solid #1e3a8a;
            padding-bottom: 4px;
        }
        .header-title h1 {
            margin: 0;
            font-size: 13px;
            color: #1e3a8a;
            font-weight: bold;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }
        .header-title h2 {
            margin: 2px 0 0 0;
            font-size: 10px;
            color: #b45309;
            font-weight: bold;
            text-transform: uppercase;
        }
        .header-title p {
            margin: 1px 0 0 0;
            font-size: 8px;
            color: #4b5563;
        }
        .info-container {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }
        .member-card {
            border: 1px solid #cbd5e1;
            background-color: #f8fafc;
            padding: 4px 6px;
            font-size: 8px;
        }
        .member-card table {
            width: 100%;
            border-collapse: collapse;
        }
        .member-card td {
            padding: 1.5px 2px;
            vertical-align: top;
        }
        .summary-card {
            border: 1px solid #fde68a;
            background-color: #fffbeb;
            padding: 4px 6px;
            font-size: 8px;
        }
        .summary-card table {
            width: 100%;
            border-collapse: collapse;
        }
        .summary-card td {
            padding: 1.5px 2px;
            vertical-align: middle;
        }
        .table-main {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-top: 4px;
            font-size: 7.5px;
        }
        .table-main th {
            background-color: #1e3a8a;
            color: #ffffff;
            font-weight: bold;
            text-align: center;
            border: 1px solid #0f172a;
            padding: 4px 2px;
            text-transform: uppercase;
        }
        .table-main td {
            border: 1px solid #cbd5e1;
            padding: 3px 3px;
            word-wrap: break-word;
        }
        .month-header td {
            background-color: #f1f5f9;
            font-weight: bold;
            color: #1e293b;
            border-top: 1.5px solid #64748b;
            border-bottom: 1px solid #94a3b8;
            padding: 3.5px 4px;
        }
        .row-bm td {
            background-color: #fefce8;
            color: #854d0e;
            font-weight: 500;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .footer-total td {
            font-weight: bold;
            background-color: #e2e8f0 !important;
            border-top: 2px solid #0f172a !important;
            border-bottom: 2px double #0f172a !important;
            padding: 4px 3px;
        }
        .signature-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 14px;
            page-break-inside: avoid;
        }
        .signature-table td {
            text-align: center;
            vertical-align: top;
            width: 33.33%;
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
            padding-top: 1px;
            min-width: 110px;
        }
    </style>
</head>
<body>
    <!-- 1. KOP SURAT RESMI -->
    <table class="header-table">
        <tr>
            <td style="width: 10%; text-align: left; vertical-align: middle;">
                @if(!empty($logoBase64))
                    <img src="{{ $logoBase64 }}" style="width: 42px; height: auto;" alt="Logo">
                @endif
            </td>
            <td class="header-title" style="width: 60%; padding-left: 4px; vertical-align: middle;">
                <h1>CREDO UNION MODIFIKASI PELITA</h1>
                <h2>HKBP RESSORT DAME - BUKU TABUNGAN HARIAN (BUKU PUTIH)</h2>
                <p>Tahun Buku: {{ $period ?? '2026/2027' }} | Periode: {{ $period_info['period_label'] ?? 'Juni - Mei' }}</p>
            </td>
            <td style="width: 30%; text-align: right; vertical-align: middle;">
                <div style="font-size: 8px; color: #475569;">
                    <strong>Tanggal Cetak:</strong> {{ date('d/m/Y H:i') }}<br>
                    <strong>Bunga Jasa:</strong> 0,6% / Bulan
                </div>
            </td>
        </tr>
    </table>

    <!-- 2. IDENTITAS ANGGOTA & REKAPITULASI RINGKAS -->
    <table class="info-container">
        <tr>
            <td style="width: 50%; padding-right: 4px; vertical-align: top;">
                <div class="member-card">
                    <table>
                        <tr>
                            <td style="width: 32%; font-weight: bold;">Nama Anggota</td>
                            <td style="width: 3%;">:</td>
                            <td style="width: 65%; font-weight: bold; color: #1e3a8a;">{{ strtoupper($mem['name'] ?? '-') }}</td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold;">No. Buku Putih</td>
                            <td>:</td>
                            <td style="font-weight: bold; color: #b45309;">{{ $mem['buku_putih_no'] ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td>No. Anggota / NIK</td>
                            <td>:</td>
                            <td>{{ $mem['member_number'] ?? '-' }} / {{ $mem['nik'] ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td>Alamat</td>
                            <td>:</td>
                            <td>{{ $mem['address'] ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold;">Status Keaktifan</td>
                            <td>:</td>
                            <td style="vertical-align: middle;">
                                @php
                                    $st = strtoupper($status_keaktifan ?? $mem['status_keaktifan'] ?? $sum['status_keaktifan'] ?? 'AKTIF');
                                    $isAktif = str_contains($st, 'AKTIF') && !str_contains($st, 'TIDAK');
                                @endphp
                                @if($isAktif)
                                    <span style="display: inline-block; background-color: #dcfce7; color: #15803d; border: 1px solid #86efac; border-radius: 3px; padding: 1px 6px; font-weight: bold; font-size: 7.5px;">
                                        STATUS: AKTIF
                                    </span>
                                @else
                                    <span style="display: inline-block; background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; border-radius: 3px; padding: 1px 6px; font-weight: bold; font-size: 7.5px;">
                                        STATUS: TIDAK AKTIF
                                    </span>
                                @endif
                            </td>
                        </tr>
                    </table>
                </div>
            </td>
            <td style="width: 50%; padding-left: 4px; vertical-align: top;">
                <div class="summary-card">
                    <table>
                        <tr>
                            <td style="width: 55%;">Saldo Awal per 31 Mei</td>
                            <td style="width: 5%;">:</td>
                            <td style="width: 40%; text-align: right; font-weight: bold;">Rp {{ number_format($opening_balance ?? $sum['opening_balance'] ?? 0, 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td>Total Setoran (KM)</td>
                            <td>:</td>
                            <td style="text-align: right; color: #166534;">+ Rp {{ number_format($total_deposit ?? $sum['total_deposit'] ?? 0, 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td>Total Penarikan (KK)</td>
                            <td>:</td>
                            <td style="text-align: right; color: #991b1b;">- Rp {{ number_format($total_withdrawal ?? $sum['total_withdrawal'] ?? 0, 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td>Total Jasa Simpanan (BM 0,6%)</td>
                            <td>:</td>
                            <td style="text-align: right; color: #854d0e;">
                                {{ ($total_interest ?? $sum['total_interest'] ?? 0) > 0 ? '+ Rp ' . number_format($total_interest ?? $sum['total_interest'] ?? 0, 0, ',', '.') : 'Rp 0' }}
                            </td>
                        </tr>
                        <tr style="border-top: 1px solid #d97706;">
                            <td style="font-weight: bold; color: #1e3a8a; padding-top: 2px;">Saldo Akhir per 20 Mei</td>
                            <td style="padding-top: 2px;">:</td>
                            <td style="text-align: right; font-weight: bold; color: #1e3a8a; padding-top: 2px; font-size: 8.5px;">Rp {{ number_format($closing_balance ?? $sum['closing_balance'] ?? 0, 0, ',', '.') }}</td>
                        </tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <!-- 3. TABEL 12 SIKLUS MUTASI TRANSAKSI (MULTI-ROW: TIAP KM, KK, BM TERCETAK KE BAWAH SECARA KRONOLOGIS) -->
    <table class="table-main">
        <thead>
            <tr>
                <th style="width: 14%;">BULAN</th>
                <th style="width: 10%;">TANGGAL</th>
                <th style="width: 16%;">NO. BUKTI</th>
                <th style="width: 15%;">SETORAN (KM)</th>
                <th style="width: 15%;">PENARIKAN (KK)</th>
                <th style="width: 14%;">JASA 0.6% (BM)</th>
                <th style="width: 16%;">SALDO (RP)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($allCycles as $cycle)
                @php 
                    $rows = $cycle['rows'] ?? [];
                    $rowCount = count($rows);
                @endphp

                @if ($rowCount > 0)
                    @foreach ($rows as $index => $r)
                        <tr class="{{ ($r['type'] ?? '') === 'BM' ? 'row-bm' : '' }}">
                            {{-- Nama bulan hanya dicetak di baris pertama siklus dengan rowspan --}}
                            @if ($index === 0)
                                <td rowspan="{{ $rowCount }}" style="vertical-align: top; font-weight: bold; background-color: #f8fafc;">
                                    {{ $cycle['month_name'] ?? ('Bulan ' . ($cycle['month'] ?? '')) }}
                                </td>
                            @endif

                            <td style="text-align: center;">{{ !empty($r['date']) ? date('d/m', strtotime($r['date'])) : '-' }}</td>
                            <td style="text-align: center; font-weight: 500;">{{ $r['evidence_no'] ?? '-' }}</td>
                            <td style="text-align: right;">{{ ($r['deposit'] ?? 0) > 0 ? number_format($r['deposit'], 0, ',', '.') : '-' }}</td>
                            <td style="text-align: right;">{{ ($r['withdrawal'] ?? 0) > 0 ? number_format($r['withdrawal'], 0, ',', '.') : '-' }}</td>
                            <td style="text-align: right; color: #047857; font-weight: 500;">{{ ($r['interest'] ?? 0) > 0 ? number_format($r['interest'], 0, ',', '.') : '-' }}</td>
                            <td style="text-align: right; font-weight: bold;">{{ number_format($r['balance'] ?? 0, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                @else
                    {{-- Jika tidak ada transaksi sama sekali selain saldo berjalan --}}
                    <tr>
                        <td style="font-weight: bold; background-color: #f8fafc;">{{ $cycle['month_name'] ?? ('Bulan ' . ($cycle['month'] ?? '')) }}</td>
                        <td style="text-align: center;">-</td>
                        <td style="text-align: center;">-</td>
                        <td style="text-align: right;">-</td>
                        <td style="text-align: right;">-</td>
                        <td style="text-align: right;">-</td>
                        <td style="text-align: right; font-weight: bold;">{{ number_format($cycle['balance'] ?? $cycle['closing_balance'] ?? 0, 0, ',', '.') }}</td>
                    </tr>
                @endif
            @endforeach
        </tbody>
        <tfoot>
            <tr class="footer-total">
                <td colspan="3" class="text-center">TOTAL MUTASI 1 TAHUN BUKU (12 BULAN)</td>
                <td class="text-right">Rp {{ number_format($total_deposit ?? $sum['total_deposit'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right">Rp {{ number_format($total_withdrawal ?? $sum['total_withdrawal'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right" style="color: #047857;">Rp {{ number_format($total_interest ?? $sum['total_interest'] ?? 0, 0, ',', '.') }}</td>
                <td class="text-right" style="color: #1e3a8a; font-size: 8.5px;">Rp {{ number_format($closing_balance ?? $sum['closing_balance'] ?? 0, 0, ',', '.') }}</td>
            </tr>
        </tfoot>
    </table>

    <!-- 4. TANDA TANGAN VERIFIKASI -->
    <table class="signature-table">
        <tr>
            <td>
                <div class="sign-title">Mengetahui,<br>Pengurus / Manajer Koperasi</div>
                <div class="sign-name">Manajer Koperasi</div>
            </td>
            <td>
                <div class="sign-title">Diperiksa Oleh,<br>Bagian Pembukuan / Kasir</div>
                <div class="sign-name">Kasir / Teller</div>
            </td>
            <td>
                <div class="sign-title">Anggota / Penyimpan,<br>Yang Bersangkutan</div>
                <div class="sign-name">{{ strtoupper($mem['name'] ?? 'Anggota') }}</div>
            </td>
        </tr>
    </table>
</body>
</html>