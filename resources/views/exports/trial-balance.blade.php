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
    <title>Neraca Lajur</title>
    <style>
        body { font-family: sans-serif; font-size: 10px; }
        h2, h3 { text-align: center; margin: 5px 0; }
        table.data-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.data-table th, table.data-table td { border: 1px solid #000; padding: 4px; text-align: right; }
        table.data-table th { background-color: #f2f2f2; text-align: center; font-size: 9px; }
        table.data-table td:nth-child(1), table.data-table td:nth-child(2) { text-align: left; }
        .total-row { font-weight: bold; background-color: #e6e6e6; }
    </style>
</head>
<body>
    <table style="width: 100%; border: none; margin-bottom: 5px;">
        <tr>
            <td style="width: 15%; text-align: left; vertical-align: middle; border: none;">
                @if(!empty($logoBase64))
                    <img src="{{ $logoBase64 }}" style="width: 65px; height: auto;" alt="Logo Koperasi">
                @endif
            </td>
            <td style="width: 85%; text-align: center; vertical-align: middle; border: none; padding-right: 15%;">
                <h2 style="margin: 0; color: #1e3a8a; font-size: 14pt; font-weight: bold;">
                    KOPERASI KREDIT PELITA HKBP DAME DURI
                </h2>
                <h3 style="margin: 3px 0 0 0; font-size: 11pt; color: #1f2937;">
                    NERACA LAJUR (TRIAL BALANCE) 10 KOLOM
                </h3>
                <p style="text-align:center; margin: 3px 0 0 0; font-size: 9pt; color: #4b5563;">Periode: {{ $startDate ?? '-' }} s/d {{ $endDate ?? '-' }}</p>
            </td>
        </tr>
    </table>
    <hr style="border: none; border-top: 2px solid #2563eb; margin-top: 5px; margin-bottom: 10px;">

    <table class="data-table">
        <thead>
            <tr>
                <th rowspan="2">Kode Akun</th>
                <th rowspan="2">Nama Akun</th>
                <th colspan="2">Neraca Saldo Awal</th>
                <th colspan="2">Mutasi Periode</th>
                <th colspan="2">Neraca Percobaan</th>
                <th colspan="2">Rugi Laba</th>
                <th colspan="2">Neraca Akhir</th>
            </tr>
            <tr>
                <th>Debit</th>
                <th>Kredit</th>
                <th>Debit</th>
                <th>Kredit</th>
                <th>Debit</th>
                <th>Kredit</th>
                <th>Debit</th>
                <th>Kredit</th>
                <th>Debit</th>
                <th>Kredit</th>
            </tr>
        </thead>
        <tbody>
            @foreach($result['accounts'] as $acc)
            <tr>
                <td>{{ $acc['account_code'] }}</td>
                <td>{{ $acc['account_name'] }}</td>
                <td>{{ number_format($acc['awal_debit'] ?? $acc['initial_debit'] ?? 0, 2) }}</td>
                <td>{{ number_format($acc['awal_credit'] ?? $acc['initial_credit'] ?? 0, 2) }}</td>
                <td>{{ number_format($acc['mutasi_debit'] ?? $acc['adjustment_debit'] ?? 0, 2) }}</td>
                <td>{{ number_format($acc['mutasi_credit'] ?? $acc['adjustment_credit'] ?? 0, 2) }}</td>
                <td>{{ number_format($acc['percobaan_debit'] ?? $acc['trial_debit'] ?? 0, 2) }}</td>
                <td>{{ number_format($acc['percobaan_credit'] ?? $acc['trial_credit'] ?? 0, 2) }}</td>
                <td>{{ number_format($acc['rugi_laba_debit'] ?? 0, 2) }}</td>
                <td>{{ number_format($acc['rugi_laba_credit'] ?? 0, 2) }}</td>
                <td>{{ number_format($acc['neraca_debit'] ?? 0, 2) }}</td>
                <td>{{ number_format($acc['neraca_credit'] ?? 0, 2) }}</td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="2" style="text-align: center;">TOTAL</td>
                <td>{{ number_format($result['summary']['total_awal_debit'] ?? $result['summary']['initial_debit'] ?? 0, 2) }}</td>
                <td>{{ number_format($result['summary']['total_awal_credit'] ?? $result['summary']['initial_credit'] ?? 0, 2) }}</td>
                <td>{{ number_format($result['summary']['total_mutasi_debit'] ?? $result['summary']['adjustment_debit'] ?? 0, 2) }}</td>
                <td>{{ number_format($result['summary']['total_mutasi_credit'] ?? $result['summary']['adjustment_credit'] ?? 0, 2) }}</td>
                <td>{{ number_format($result['summary']['total_percobaan_debit'] ?? $result['summary']['trial_debit'] ?? 0, 2) }}</td>
                <td>{{ number_format($result['summary']['total_percobaan_credit'] ?? $result['summary']['trial_credit'] ?? 0, 2) }}</td>
                <td>{{ number_format($result['summary']['total_rugi_laba_debit'] ?? $result['summary']['rugi_laba_debit'] ?? 0, 2) }}</td>
                <td>{{ number_format($result['summary']['total_rugi_laba_credit'] ?? $result['summary']['rugi_laba_credit'] ?? 0, 2) }}</td>
                <td>{{ number_format($result['summary']['total_neraca_debit'] ?? $result['summary']['neraca_debit'] ?? 0, 2) }}</td>
                <td>{{ number_format($result['summary']['total_neraca_credit'] ?? $result['summary']['neraca_credit'] ?? 0, 2) }}</td>
            </tr>
        </tbody>
    </table>
    <p style="margin-top: 10px;">
        <strong>Laba / Rugi Bersih: </strong> {{ number_format($result['summary']['laba_rugi_bersih'] ?? $result['summary']['net_income'] ?? 0, 2) }}
    </p>
</body>
</html>
