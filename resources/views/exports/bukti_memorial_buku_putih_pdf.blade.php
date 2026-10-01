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

    $summaryData = $summary ?? [];
    $membersList = $members ?? $details ?? $summaryData['members'] ?? $summaryData['details'] ?? [];
    
    // Filter anggota yang berhak (bunga > 0)
    $eligibleMembers = array_values(array_filter($membersList, function($m) {
        $amt = (float)($m['interest_amount'] ?? $m['interest'] ?? $m['amount'] ?? 0);
        return $amt > 0;
    }));

    $totalExpense = (float)($totalExpense ?? $summaryData['total_interest_amount'] ?? $summaryData['total_interest'] ?? $summaryData['total_expense'] ?? 0);
    if ($totalExpense <= 0 && count($eligibleMembers) > 0) {
        $totalExpense = array_sum(array_map(fn($m) => (float)($m['interest_amount'] ?? $m['interest'] ?? $m['amount'] ?? 0), $eligibleMembers));
    }

    $bmNumber = $summaryData['voucher_number'] ?? $journal_no ?? $voucher_number ?? '3962';

    $periodLabel = $summaryData['period_label'] ?? $period_label ?? '';
    if (empty($periodLabel) && !empty($summaryData['month']) && !empty($summaryData['year'])) {
        $monthName = \Carbon\Carbon::createFromDate($summaryData['year'], $summaryData['month'], 1)->locale('id')->translatedFormat('F');
        $periodLabel = "{$monthName} {$summaryData['year']}";
    }
    if (empty($periodLabel)) {
        $periodLabel = now()->locale('id')->translatedFormat('F Y');
    }

    $tanggalLabel = "Tanggal : 20 {$periodLabel}";
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Bukti Memorial (BM) - {{ $bmNumber }}</title>
    <style>
        @page {
            size: a4 landscape;
            margin: 10mm 15mm 10mm 15mm;
        }
        body {
            font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif;
            font-size: 8.5px;
            color: #000000;
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
        }
        .header-table td {
            vertical-align: middle;
            padding: 0;
        }

        .kop-line-1 {
            font-size: 13px;
            font-weight: bold;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin: 0;
            color: #000000;
        }
        .kop-line-2 {
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            margin: 2px 0 0 0;
            color: #000000;
        }
        .kop-line-3 {
            font-size: 12px;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin: 3px 0 0 0;
            color: #000000;
        }
        .kop-line-4 {
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 0.5px;
            margin: 2px 0 0 0;
            color: #000000;
        }

        .table-bm {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-top: 2px;
            font-size: 8.5px;
        }
        .table-bm th, .table-bm td {
            border: 1px solid #000000;
            padding: 3px 4px;
            word-wrap: break-word;
        }
        .table-bm th {
            background-color: #f7f7f7;
            color: #000000;
            font-weight: bold;
            text-align: center;
            text-transform: uppercase;
            font-size: 8.5px;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .font-bold { font-weight: bold; }

        .total-row td {
            background-color: #f7f7f7;
            font-weight: bold;
            border-top: 1.5px solid #000000 !important;
            border-bottom: 2px double #000000 !important;
        }

        .sign-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 18px;
            page-break-inside: avoid;
            font-size: 8.5px;
        }
        .sign-table td {
            text-align: center;
            vertical-align: top;
        }
        .sign-title {
            font-weight: bold;
            margin-bottom: 35px;
        }
        .sign-line {
            border-top: 1px dotted #000000;
            display: inline-block;
            padding-top: 2px;
            min-width: 140px;
            font-weight: bold;
        }
    </style>
</head>
<body>

    <!-- 1. HEADER KOP SURAT BERDAMPINGAN DENGAN LOGO & INFORMASI BM -->
    <table class="header-table">
        <tr>
            <td style="width: 25%; text-align: left; vertical-align: top;">
                @if(!empty($logoBase64))
                    <img src="{{ $logoBase64 }}" style="width: 48px; height: auto; margin-bottom: 4px;" alt="Logo"><br>
                @endif
                <div style="font-size: 9px; font-weight: bold; margin-top: 2px;">{{ $tanggalLabel }}</div>
            </td>
            <td style="width: 50%; text-align: center; vertical-align: middle;">
                <div class="kop-line-1">CREDO UNION MODIFIKASI PELITA</div>
                <div class="kop-line-2">HKBP RESSORT DAME</div>
                <div class="kop-line-3">BUKTI MEMORIAL</div>
                <div class="kop-line-4">( BM )</div>
            </td>
            <td style="width: 25%; text-align: right; vertical-align: top;">
                <div style="font-size: 9.5px; font-weight: bold; margin-top: 15px;">BM No : {{ $bmNumber }}</div>
            </td>
        </tr>
    </table>

    <!-- 2. TABEL 2 TINGKAT BERDAMPINGAN (DEBET & KREDIT) -->
    <table class="table-bm">
        <thead>
            <tr>
                <th rowspan="2" style="width: 4%;">No</th>
                <th rowspan="2" style="width: 28%;">Keterangan</th>
                <th colspan="3" style="width: 34%;">DEBET</th>
                <th colspan="3" style="width: 34%;">KREDIT</th>
            </tr>
            <tr>
                <!-- DEBET -->
                <th style="width: 7%;">No Perk</th>
                <th style="width: 13%;">Nama Perk</th>
                <th style="width: 14%;">Jumlah Rp</th>
                <!-- KREDIT -->
                <th style="width: 7%;">No Perk</th>
                <th style="width: 13%;">Nama Perk</th>
                <th style="width: 14%;">Jumlah Rp</th>
            </tr>
        </thead>
        <tbody>
            <!-- BARIS ANGGOTA (SEIMBANG SISI DEBET DAN KREDIT) -->
            @foreach($eligibleMembers as $index => $m)
                @php
                    $amt = (float)($m['interest_amount'] ?? $m['interest'] ?? $m['amount'] ?? 0);
                    $noBuku = $m['buku_putih_no'] ?? (!empty($m['member_number']) ? '2021-'.str_pad((string)$m['member_number'], 4, '0', STR_PAD_LEFT) : '');
                    $memberName = $m['name'] ?? $m['nama'] ?? 'Anggota';
                    $keterangan = !empty($noBuku) ? "{$memberName} ({$noBuku})" : $memberName;
                @endphp
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $keterangan }}</td>
                    <td class="text-center font-bold">7145</td>
                    <td>Jasa Simpanan</td>
                    <td class="text-right font-bold">{{ number_format($amt, 0, ',', '.') }}</td>
                    <td class="text-center font-bold">2021</td>
                    <td>Simpanan Harian</td>
                    <td class="text-right font-bold">{{ number_format($amt, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <!-- BARIS TOTAL -->
            <tr class="total-row">
                <td colspan="2" class="text-center font-bold">TOTAL</td>
                <td class="text-center">-</td>
                <td class="text-center">-</td>
                <td class="text-right font-bold">{{ number_format($totalExpense, 0, ',', '.') }}</td>
                <td class="text-center">-</td>
                <td class="text-center">-</td>
                <td class="text-right font-bold">{{ number_format($totalExpense, 0, ',', '.') }}</td>
            </tr>
        </tfoot>
    </table>

    <!-- 3. LEMBAR TANDA TANGAN (HANYA MANAJER) -->
    <table class="sign-table">
        <tr>
            <td style="width: 70%;"></td>
            <td style="width: 30%;">
                <div class="sign-title">Disetujui Oleh,<br>Manajer Koperasi</div>
                <div class="sign-line">Manajer Koperasi</div>
            </td>
        </tr>
    </table>

</body>
</html>
