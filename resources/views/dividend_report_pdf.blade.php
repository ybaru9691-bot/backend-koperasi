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
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Deviden Buku Biru - {{ $summary['period_label'] ?? '' }}</title>
    <style>
        @page {
            margin: 8mm 9mm 8mm 9mm;
            size: a4 portrait;
        }
        body {
            font-family: 'DejaVu Sans', 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 7.5px;
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
            font-size: 12px;
            color: #1e3a8a;
            font-weight: bold;
            text-transform: uppercase;
        }
        .header-title h2 {
            margin: 2px 0 0 0;
            font-size: 10px;
            color: #b45309;
            font-weight: bold;
            text-transform: uppercase;
        }
        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
            background-color: #f8fafc;
            border: 1px solid #cbd5e1;
            font-size: 7.5px;
        }
        .meta-table td {
            padding: 2.5px 5px;
        }
        .table-main {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-top: 4px;
            font-size: 7.2px;
        }
        .table-main th {
            background-color: #1e3a8a;
            color: #ffffff;
            font-weight: bold;
            text-align: center;
            border: 1px solid #0f172a;
            padding: 3.5px 2px;
            text-transform: uppercase;
        }
        .table-main td {
            border: 1px solid #cbd5e1;
            padding: 2.5px 3px;
            word-wrap: break-word;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .footer-total td {
            font-weight: bold;
            background-color: #e2e8f0 !important;
            border-top: 2px solid #0f172a !important;
            border-bottom: 2px double #0f172a !important;
            padding: 3.5px 3px;
        }
        .signature-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
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
            margin-bottom: 28px;
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
    <!-- KOP RESMI -->
    <table class="header-table">
        <tr>
            <td style="width: 10%; text-align: left; vertical-align: middle;">
                @if(!empty($logoBase64))
                    <img src="{{ $logoBase64 }}" style="width: 40px; height: auto;" alt="Logo">
                @endif
            </td>
            <td class="header-title" style="width: 60%; padding-left: 4px; vertical-align: middle;">
                <h1>CREDO UNION MODIFIKASI PELITA</h1>
                <h2>LAPORAN PEMBAGIAN DEVIDEN BUKU BIRU (SHU BULANAN)</h2>
                <div style="font-size: 7.5px; color: #475569; margin-top: 1px;">
                    HKBP Ressort Dame • Periode: <strong>{{ $summary['period_label'] ?? '-' }}</strong>
                </div>
            </td>
            <td style="width: 30%; text-align: right; vertical-align: middle;">
                <div style="font-size: 7.5px; color: #475569;">
                    <strong>Tanggal:</strong> {{ \Carbon\Carbon::parse($summary['execution_date'])->translatedFormat('d F Y') }}<br>
                    <strong>No. Bukti:</strong> {{ $summary['default_voucher_no'] ?? '-' }}
                </div>
            </td>
        </tr>
    </table>

    <!-- RINGKASAN REKAP -->
    <table class="meta-table">
        <tr>
            <td style="width: 20%; font-weight: bold;">Laba Bersih (SHU)</td>
            <td style="width: 2%;">:</td>
            <td style="width: 28%; font-weight: bold; color: #1e3a8a;">Rp {{ number_format($summary['shu_bersih'] ?? 0, 0, ',', '.') }}</td>
            <td style="width: 20%; font-weight: bold;">Total Saham Koperasi</td>
            <td style="width: 2%;">:</td>
            <td style="width: 28%; font-weight: bold;">Rp {{ number_format($summary['total_all_shares'] ?? 0, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td style="font-weight: bold;">Alokasi Deviden (%)</td>
            <td>:</td>
            <td>{{ number_format($summary['percentage'] ?? 25, 2) }}% (Rp {{ number_format($summary['dividend_pool'] ?? 0, 0, ',', '.') }})</td>
            <td style="font-weight: bold;">Total Lembar Saham</td>
            <td>:</td>
            <td>{{ number_format($summary['total_lembar_koperasi'] ?? 0, 0, ',', '.') }} Lembar</td>
        </tr>
        <tr>
            <td style="font-weight: bold;">Harga Deviden / Lembar</td>
            <td>:</td>
            <td style="font-weight: bold; color: #b45309;">Rp {{ number_format($summary['harga_deviden_per_lembar'] ?? 0, 4, ',', '.') }}</td>
            <td style="font-weight: bold;">Anggota Berhak SHU</td>
            <td>:</td>
            <td style="font-weight: bold; color: #166534;">{{ $summary['eligible_members_count'] ?? 0 }} Orang</td>
        </tr>
    </table>

    <!-- TABEL DAFTAR ANGGOTA -->
    <table class="table-main">
        <thead>
            <tr>
                <th style="width: 4%;">No</th>
                <th style="width: 10%;">No. Anggota</th>
                <th style="width: 22%;">Nama Anggota</th>
                <th style="width: 14%;">Simp. Pokok (SP)</th>
                <th style="width: 14%;">Simp. Wajib (SW)</th>
                <th style="width: 14%;">Simp. Sukarela (SS)</th>
                <th style="width: 14%;">Total Saham</th>
                <th style="width: 8%;">Status SW</th>
                <th style="width: 10%;">Deviden (Rp)</th>
            </tr>
        </thead>
        <tbody>
            @php $no = 1; @endphp
            @foreach($members as $m)
                <tr>
                    <td class="text-center">{{ $no++ }}</td>
                    <td class="text-center">{{ $m['member_number'] ?? '-' }}</td>
                    <td>{{ $m['name'] ?? '-' }}</td>
                    <td class="text-right">{{ number_format($m['principal_savings'] ?? 0, 0, ',', '.') }}</td>
                    <td class="text-right">{{ number_format($m['mandatory_savings'] ?? 0, 0, ',', '.') }}</td>
                    <td class="text-right">{{ number_format($m['voluntary_savings'] ?? 0, 0, ',', '.') }}</td>
                    <td class="text-right" style="font-weight: bold;">{{ number_format($m['total_saham'] ?? 0, 0, ',', '.') }}</td>
                    @php
                        $isLunas = (bool) ($m['is_eligible'] ?? (!($m['is_sw_arrears'] ?? false) && ($m['total_saham'] ?? 0) > 0));
                    @endphp
                    <td class="text-center" style="font-size: 6.5px; font-weight: bold; color: {{ $isLunas ? '#166534' : '#991b1b' }};">
                        {{ $isLunas ? 'Lunas' : 'Menunggak' }}
                    </td>
                    <td class="text-right" style="font-weight: bold; color: #1e3a8a;">
                        {{ number_format($m['deviden_amount'] ?? 0, 0, ',', '.') }}
                    </td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="footer-total">
                <td colspan="6" class="text-center">TOTAL DISTRIBUSI DEVIDEN</td>
                <td class="text-right">Rp {{ number_format($summary['total_all_shares'] ?? 0, 0, ',', '.') }}</td>
                <td></td>
                <td class="text-right" style="color: #1e3a8a;">Rp {{ number_format($summary['total_distributed_dividend'] ?? 0, 0, ',', '.') }}</td>
            </tr>
        </tfoot>
    </table>

    <!-- TANDA TANGAN -->
    <table class="signature-table">
        <tr>
            <td>
                <div class="sign-title">Dibuat Oleh,<br>Bendahara Koperasi</div>
                <div class="sign-name">Bendahara</div>
            </td>
            <td>
                <div class="sign-title">Diperiksa Oleh,<br>Ketua Pengurus</div>
                <div class="sign-name">Ketua Pengurus</div>
            </td>
            <td>
                <div class="sign-title">Disetujui Oleh,<br>Manajer Koperasi</div>
                <div class="sign-name">Manajer Koperasi</div>
            </td>
        </tr>
    </table>
</body>
</html>