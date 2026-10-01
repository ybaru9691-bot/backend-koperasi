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
    <title>Buku Besar - {{ $coa->account_code }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            color: #333;
            margin: 0;
            padding: 10px;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #2563eb;
            padding-bottom: 10px;
        }
        .header h2 {
            margin: 0 0 5px 0;
            font-size: 16px;
            color: #1e3a8a;
            text-transform: uppercase;
        }
        .header h3 {
            margin: 0 0 5px 0;
            font-size: 13px;
            color: #374151;
        }
        .meta-info {
            width: 100%;
            margin-bottom: 15px;
        }
        .meta-info table {
            width: 100%;
            border-collapse: collapse;
        }
        .meta-info td {
            padding: 3px 0;
            font-size: 11px;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .data-table th {
            background-color: #f3f4f6;
            color: #1f2937;
            font-weight: bold;
            text-align: center;
            border: 1px solid #d1d5db;
            padding: 6px 4px;
        }
        .data-table td {
            border: 1px solid #e5e7eb;
            padding: 5px 4px;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .footer-total {
            background-color: #f9fafb;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <table style="width: 100%; border: none; margin-bottom: 10px;">
        <tr>
            <td style="width: 15%; text-align: left; vertical-align: middle; border: none;">
                @if(!empty($logoBase64))
                    <img src="{{ $logoBase64 }}" style="width: 70px; height: auto;" alt="Logo Koperasi">
                @endif
            </td>
            <td style="width: 85%; text-align: center; vertical-align: middle; border: none; padding-right: 15%;">
                <h2 style="margin: 0; color: #1e3a8a; font-size: 16pt; font-weight: bold;">
                    KOPERASI KREDIT PELITA HKBP DAME DURI
                </h2>
                <h3 style="margin: 4px 0 0 0; font-size: 12pt; color: #1f2937;">
                    LAPORAN BUKU BESAR (GENERAL LEDGER)
                </h3>
            </td>
        </tr>
    </table>
    <hr style="border: none; border-top: 2px solid #2563eb; margin-top: 5px; margin-bottom: 15px;">

    <div class="meta-info">
        <table>
            <tr>
                <td style="width: 15%;"><strong>Kode Akun:</strong></td>
                <td style="width: 35%;">{{ $coa->account_code }} - {{ $coa->account_name }}</td>
                <td style="width: 15%;"><strong>Periode:</strong></td>
                <td style="width: 35%;">{{ $startDate ?: 'Awal' }} s/d {{ $endDate ?: 'Sekarang' }}</td>
            </tr>
            <tr>
                <td><strong>Tipe Akun:</strong></td>
                <td>{{ strtoupper($coa->account_type) }} (Saldo Normal: {{ strtoupper($coa->normal_balance) }})</td>
                <td><strong>Saldo Awal:</strong></td>
                <td><strong>Rp {{ number_format($beginningBalance, 2, ',', '.') }}</strong></td>
            </tr>
        </table>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 12%;">Tanggal</th>
                <th style="width: 18%;">No. Bukti</th>
                <th>Keterangan</th>
                <th style="width: 15%;">Debit (Rp)</th>
                <th style="width: 15%;">Kredit (Rp)</th>
                <th style="width: 16%;">Saldo (Rp)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="text-center">{{ $startDate ?: '-' }}</td>
                <td class="text-center">-</td>
                <td><em>Saldo Awal Periode</em></td>
                <td class="text-right">-</td>
                <td class="text-right">-</td>
                <td class="text-right"><strong>{{ number_format($beginningBalance, 2, ',', '.') }}</strong></td>
            </tr>
            @forelse($rows as $row)
            <tr>
                <td class="text-center">{{ $row['entry_date'] }}</td>
                <td class="text-center">{{ $row['voucher_number'] }}</td>
                <td>{{ $row['description'] }}</td>
                <td class="text-right">{{ $row['debit'] > 0 ? number_format($row['debit'], 2, ',', '.') : '-' }}</td>
                <td class="text-right">{{ $row['credit'] > 0 ? number_format($row['credit'], 2, ',', '.') : '-' }}</td>
                <td class="text-right">{{ number_format($row['balance'], 2, ',', '.') }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="6" class="text-center" style="padding: 15px;"><em>Tidak ada mutasi transaksi pada periode ini.</em></td>
            </tr>
            @endforelse
            <tr class="footer-total">
                <td colspan="3" class="text-center"><strong>TOTAL MUTASI & SALDO AKHIR</strong></td>
                <td class="text-right"><strong>{{ number_format($totalDebit, 2, ',', '.') }}</strong></td>
                <td class="text-right"><strong>{{ number_format($totalCredit, 2, ',', '.') }}</strong></td>
                <td class="text-right"><strong>{{ number_format($endingBalance, 2, ',', '.') }}</strong></td>
            </tr>
        </tbody>
    </table>
</body>
</html>
