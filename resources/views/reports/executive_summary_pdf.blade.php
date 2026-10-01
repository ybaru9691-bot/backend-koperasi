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
<html>
<head>
    <meta charset="utf-8">
    <title>Laporan Eksekutif Keuangan - KSP CUM Pelita</title>
    <style>
        @page {
            margin: 8mm 10mm;
            size: a4 portrait;
        }
        body {
            font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif;
            font-size: 10px;
            color: #333333;
            line-height: 1.35;
            margin: 0;
            padding: 0;
        }
        thead { display: table-header-group; }
        tfoot { display: table-footer-group; }
        tr { page-break-inside: avoid; }
        .header-kop {
            margin-bottom: 10px;
        }
        .title-report {
            text-align: center;
            text-transform: uppercase;
            font-weight: bold;
            font-size: 11.5px;
            margin-bottom: 12px;
        }
        .summary-box {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: 14px;
        }
        .summary-box th, .summary-box td {
            padding: 4px 6px;
            border: 1px solid #d1d5db;
        }
        .summary-box th {
            background-color: #f3f4f6;
            text-align: left;
            font-weight: bold;
        }
        .table-data {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: 14px;
        }
        .table-data th, .table-data td {
            border: 1px solid #d1d5db;
            padding: 3.5px 5px;
            text-align: left;
            word-wrap: break-word;
        }
        .table-data th {
            background-color: #34495e;
            color: #ffffff;
            font-weight: bold;
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .font-bold {
            font-weight: bold;
        }
        .section-title {
            font-size: 11px;
            font-weight: bold;
            margin-top: 10px;
            margin-bottom: 4px;
            color: #2c3e50;
            border-left: 3px solid #34495e;
            padding-left: 5px;
        }
        .signature-container {
            margin-top: 30px;
            width: 100%;
            page-break-inside: avoid;
        }
        .signature-table {
            width: 100%;
            border: none;
            table-layout: fixed;
        }
        .signature-table td {
            border: none;
            text-align: center;
            width: 50%;
        }
        .signature-space {
            height: 45px;
        }
    </style>
</head>
<body>

    <table style="width: 100%; border: none; margin-bottom: 5px;">
        <tr>
            <td style="width: 15%; text-align: left; vertical-align: middle; border: none;">
                @if(!empty($logoBase64))
                    <img src="{{ $logoBase64 }}" style="width: 70px; height: auto;" alt="Logo Koperasi">
                @endif
            </td>
            <td style="width: 85%; text-align: center; vertical-align: middle; border: none; padding-right: 15%;">
                <h2 style="margin: 0; color: #1e3a8a; font-size: 16pt; font-weight: bold;">
                    KOPERASI SIMPAN PINJAM CUM PELITA
                </h2>
                <h3 style="margin: 4px 0 0 0; font-size: 12pt; color: #1f2937;">
                    HKBP DAME DURI
                </h3>
            </td>
        </tr>
    </table>
    <hr style="border: none; border-top: 2px solid #2563eb; margin-top: 5px; margin-bottom: 15px;">

    <div class="title-report">
        Ringkasan Laporan Eksekutif Keuangan<br>
        <span style="font-size: 10px; font-weight: normal; text-transform: none;">Periode: {{ $period }} | Tanggal Cetak: {{ $report_date }}</span>
    </div>

    <div class="section-title">Ringkasan Arus Kas (Cash Flow Summary)</div>
    <table class="summary-box">
        <tr>
            <th width="40%">Total Kas Masuk (KM)</th>
            <td class="text-right font-bold" style="color: #27ae60;">Rp {{ number_format($total_km, 2, ',', '.') }}</td>
        </tr>
        <tr>
            <th>Total Kas Keluar (KK)</th>
            <td class="text-right font-bold" style="color: #c0392b;">Rp {{ number_format($total_kk, 2, ',', '.') }}</td>
        </tr>
        <tr style="background-color: #eaeded;">
            <th>Arus Kas Bersih (Net Cashflow)</th>
            <td class="text-right font-bold">Rp {{ number_format($net_cashflow, 2, ',', '.') }}</td>
        </tr>
        <tr>
            <th>Alokasi SHU Pengurus & Anggota (70%)</th>
            <td class="text-right font-bold" style="color: #2980b9;">Rp {{ number_format($alokasi_shu_70, 2, ',', '.') }}</td>
        </tr>
    </table>

    <div class="section-title">Rincian Pos Penerimaan Kas Masuk (KM)</div>
    <table class="table-data">
        <thead>
            <tr>
                <th width="15%">Kode Pos</th>
                <th width="50%">Kategori Keterangan Penerimaan</th>
                <th width="20%">Nominal (Rp)</th>
                <th width="15%">Porsi (%)</th>
            </tr>
        </thead>
        <tbody>
            @forelse($km_categories as $cat)
                <tr>
                    <td class="text-center">{{ $cat['code'] }}</td>
                    <td>{{ $cat['category_name'] }}</td>
                    <td class="text-right">Rp {{ number_format($cat['amount'], 2, ',', '.') }}</td>
                    <td class="text-center font-bold">{{ $cat['percentage'] }}%</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-center" style="color: #7f8c8d;">Tidak ada data penerimaan kas masuk pada periode ini.</td>
                </tr>
            @endforelse
            <tr style="background-color: #f9f9f9;" class="font-bold">
                <td colspan="2" class="text-center">TOTAL KAS MASUK</td>
                <td class="text-right">Rp {{ number_format($total_km, 2, ',', '.') }}</td>
                <td class="text-center">100%</td>
            </tr>
        </tbody>
    </table>

    <div class="section-title">Rincian Pos Pengeluaran Kas Keluar (KK)</div>
    <table class="table-data">
        <thead>
            <tr>
                <th width="15%">Kode Pos</th>
                <th width="50%">Kategori Keterangan Pengeluaran</th>
                <th width="20%">Nominal (Rp)</th>
                <th width="15%">Porsi (%)</th>
            </tr>
        </thead>
        <tbody>
            @forelse($kk_categories as $cat)
                <tr>
                    <td class="text-center">{{ $cat['code'] }}</td>
                    <td>{{ $cat['category_name'] }}</td>
                    <td class="text-right">Rp {{ number_format($cat['amount'], 2, ',', '.') }}</td>
                    <td class="text-center font-bold">{{ $cat['percentage'] }}%</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-center" style="color: #7f8c8d;">Tidak ada data pengeluaran kas keluar pada periode ini.</td>
                </tr>
            @endforelse
            <tr style="background-color: #f9f9f9;" class="font-bold">
                <td colspan="2" class="text-center">TOTAL KAS KELUAR</td>
                <td class="text-right">Rp {{ number_format($total_kk, 2, ',', '.') }}</td>
                <td class="text-center">100%</td>
            </tr>
        </tbody>
    </table>

    <div class="signature-container">
        <table class="signature-table">
            <tr>
                <td>
                    Mengetahui,<br>
                    <strong>Pengurus Koperasi CUM Pelita</strong>
                    <div class="signature-space"></div>
                    ( .................................................... )
                </td>
                <td>
                    Disiapkan oleh,<br>
                    <strong>Manajer Keuangan</strong>
                    <div class="signature-space"></div>
                    ( .................................................... )
                </td>
            </tr>
        </table>
    </div>

</body>
</html>
