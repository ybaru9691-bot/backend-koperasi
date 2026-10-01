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
    $memberData = $member ?? $loan['member'] ?? [];
    $installmentsData = $installments ?? $loan['installments'] ?? [];
    $totalRows = 28;

    $plafonAmt = (float)($loan['original_amount'] ?? $loan['plafon_awal'] ?? $loan['amount'] ?? $loan['plafon'] ?? 0);

    $disbRawDate = $loan['disbursement_date'] ?? $loan['tanggal_cair'] ?? $loan['created_at'] ?? null;
    $disbDate = !empty($disbRawDate) ? \Carbon\Carbon::parse($disbRawDate)->format('d-M-y') : '-';

    $dueDateRaw = $loan['due_date'] ?? $loan['tanggal_jatuh_tempo_akhir'] ?? null;
    if (!$dueDateRaw && !empty($disbRawDate)) {
        $tenor = (int)($loan['duration_months'] ?? $loan['tenor_months'] ?? 12);
        $dueDateRaw = \Carbon\Carbon::parse($disbRawDate)->addMonths($tenor);
    }
    $dueDateFormatted = !empty($dueDateRaw) 
        ? strtoupper(\Carbon\Carbon::parse($dueDateRaw)->translatedFormat('d F Y'))
        : '-';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kartu Pinjaman - {{ $memberData['name'] ?? $memberData['nama'] ?? 'Anggota' }}</title>
    <style>
        @page {
            margin: 5mm 6mm 5mm 6mm;
            size: a4 portrait;
        }
        body {
            font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif;
            font-size: 7.5px;
            color: #000000;
            background-color: #fffdf2;
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
            margin-bottom: 3px;
        }
        .header-title h1 {
            margin: 0;
            font-size: 11px;
            color: #000000;
            font-weight: bold;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }
        .header-title h2 {
            margin: 1px 0 0 0;
            font-size: 9.5px;
            color: #000000;
            font-weight: bold;
            text-transform: uppercase;
        }
        .header-title h3 {
            margin: 1px 0 0 0;
            font-size: 8.5px;
            color: #000000;
            font-weight: bold;
            text-transform: uppercase;
        }
        .header-title h4 {
            margin: 1px 0 0 0;
            font-size: 7.5px;
            color: #000000;
            font-weight: normal;
            text-transform: uppercase;
        }

        .meta-container {
            width: 100%;
            border-collapse: collapse;
            margin-top: 2px;
            margin-bottom: 4px;
            font-size: 7.8px;
        }
        .meta-container td {
            vertical-align: top;
            padding: 1px 2px;
        }

        .table-main {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-top: 2px;
            font-size: 7px;
        }
        .table-main th {
            background-color: #fffdf2;
            color: #000000;
            font-weight: bold;
            text-align: center;
            border: 1px solid #000000;
            padding: 3px 1px;
            text-transform: uppercase;
        }
        .table-main td {
            border: 1px solid #000000;
            padding: 2.2px 2px;
            word-wrap: break-word;
            height: 14.5px;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .font-bold { font-weight: bold; }

        .footer-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }
        .footer-due-date {
            text-align: right;
            font-weight: bold;
            font-size: 8.5px;
            padding-right: 4px;
            color: #000000;
            letter-spacing: 0.3px;
        }
    </style>
</head>
<body>

    <!-- 1. KOP SURAT KARTU PINJAMAN KUNING -->
    <table class="header-table">
        <tr>
            <td style="width: 8%; text-align: left; vertical-align: middle;">
                @if(!empty($logoBase64))
                    <img src="{{ $logoBase64 }}" style="width: 38px; height: auto;" alt="Logo">
                @endif
            </td>
            <td class="header-title" style="width: 92%; padding-left: 5px; vertical-align: middle;">
                <h1>KARTU PINJAMAN PRODUKTIF / KONSUMTIF</h1>
                <h2>CREDO UNION MODIFIKASI PELITA</h2>
                <h3>HKBP RESSORT DAME</h3>
                <h4>JL. PERDAMAIAN NO 37</h4>
            </td>
        </tr>
    </table>

    <!-- 2. INFORMASI PINJAMAN & IDENTITAS ANGGOTA -->
    <table class="meta-container">
        <tr>
            <!-- Kolom Kiri -->
            <td style="width: 44%; vertical-align: top;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="width: 32%; font-weight: bold;">Jasa</td>
                        <td style="width: 4%;">:</td>
                        <td style="width: 64%; font-weight: bold;">{{ number_format($loan['interest_rate'] ?? 2.5, 2, ',', '.') }}% <span style="font-weight: normal;">bulan/minggu/hari</span></td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">Jangka Waktu</td>
                        <td>:</td>
                        <td style="font-weight: bold;">{{ $loan['duration_months'] ?? $loan['tenor_months'] ?? 12 }} <span style="font-weight: normal;">bulan/minggu/hari</span></td>
                    </tr>
                </table>
            </td>
            <!-- Kolom Kanan -->
            <td style="width: 56%; vertical-align: top;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="width: 26%; font-weight: bold;">NAMA</td>
                        <td style="width: 3%;">:</td>
                        <td style="width: 71%; font-weight: bold;">{{ strtoupper($memberData['name'] ?? $memberData['nama'] ?? '-') }}</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">No SH</td>
                        <td>:</td>
                        <td style="font-weight: bold;">{{ $loan['loan_code'] ?? $loan['loan_number'] ?? $memberData['member_number'] ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">PINJAMAN</td>
                        <td>:</td>
                        <td style="font-weight: bold;">Rp {{ number_format($plafonAmt, 0, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td>Alamat & HP</td>
                        <td>:</td>
                        <td>{{ $memberData['address'] ?? $memberData['alamat'] ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td></td>
                        <td>:</td>
                        <td>HP {{ $memberData['phone'] ?? $memberData['telepon'] ?? $memberData['no_hp'] ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td>Jaminan</td>
                        <td>:</td>
                        <td>{{ $loan['collateral'] ?? $loan['collateral_type'] ?? $loan['agunan'] ?? 'Saham' }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- 3. TABEL MUTASI KARTU PINJAMAN 9 KOLOM -->
    <table class="table-main">
        <thead>
            <tr>
                <th style="width: 11%;">Tgl</th>
                <th style="width: 12%;">No. Bukti</th>
                <th style="width: 6%;">Ang Ke</th>
                <th style="width: 15%;">Jumlah Pinjaman<br>Rp</th>
                <th style="width: 13%;">Angsuran<br>Rp</th>
                <th style="width: 15%;">Saldo<br>Rp</th>
                <th style="width: 11%;">Jasa<br>Rp</th>
                <th style="width: 9%;">Denda<br>Rp</th>
                <th style="width: 8%;">Paraf</th>
            </tr>
        </thead>
        <tbody>
            <!-- BARIS 1: PENCAIRAN DANA (Plafon Awal) -->
            <tr>
                <td class="text-center font-bold">{{ $disbDate }}</td>
                <td class="text-center"></td>
                <td class="text-center"></td>
                <td class="text-right font-bold">{{ number_format($plafonAmt, 0, ',', '.') }}</td>
                <td class="text-right"></td>
                <td class="text-right font-bold">{{ number_format($plafonAmt, 0, ',', '.') }}</td>
                <td class="text-right"></td>
                <td class="text-right"></td>
                <td class="text-center font-bold">{{ $loan['disbursed_by_name'] ?? 'Kasir' }}</td>
            </tr>

            <!-- BARIS ANGSURAN (MUTASI ANGSURAN & JADWAL) -->
            @php
                $renderedRowsCount = 1;
            @endphp
            @foreach($installmentsData as $inst)
                @php
                    $instDate = '';
                    if (!empty($inst['paid_at'])) {
                        $instDate = \Carbon\Carbon::parse($inst['paid_at'])->format('d-M-y');
                    } elseif (!empty($inst['date'])) {
                        $instDate = \Carbon\Carbon::parse($inst['date'])->format('d-M-y');
                    } elseif (!empty($inst['due_date'])) {
                        $instDate = \Carbon\Carbon::parse($inst['due_date'])->format('d-M-y');
                    }

                    $rawReceipt = $inst['receipt_number'] ?? $inst['no_bukti'] ?? '-';
                    $orderNo = $inst['installment_order'] ?? $inst['installment_number'] ?? $inst['angsuran_ke'] ?? '';
                    $noBukti = ($rawReceipt !== '-' && !empty($rawReceipt)) ? $rawReceipt : ($orderNo ? 'KM-' . $orderNo : '-');
                    $pokok   = (float)($inst['principal_amount'] ?? $inst['angsuran_pokok'] ?? 0);
                    $saldo   = (float)($inst['ending_balance'] ?? $inst['sisa_pokok_akhir'] ?? $inst['remaining_balance'] ?? 0);
                    $jasa    = (float)($inst['interest_amount'] ?? $inst['jasa_pinjaman'] ?? 0);
                    $denda   = (float)($inst['late_fee'] ?? $inst['penalty_amount'] ?? $inst['denda'] ?? 0);
                    $teller  = $inst['teller_name'] ?? $inst['paraf'] ?? 'Kasir';

                    $renderedRowsCount++;
                @endphp
                <tr>
                    <td class="text-center">{{ $instDate }}</td>
                    <td class="text-center">{{ $noBukti }}</td>
                    <td class="text-center font-bold">{{ $orderNo }}</td>
                    <td class="text-right"></td>
                    <td class="text-right">{{ $pokok > 0 ? number_format($pokok, 0, ',', '.') : '-' }}</td>
                    <td class="text-right font-bold">{{ number_format($saldo, 0, ',', '.') }}</td>
                    <td class="text-right">{{ $jasa > 0 ? number_format($jasa, 0, ',', '.') : '-' }}</td>
                    <td class="text-right">{{ $denda > 0 ? number_format($denda, 0, ',', '.') : '-' }}</td>
                    <td class="text-center">{{ $teller }}</td>
                </tr>
            @endforeach

            <!-- BARIS KOSONG BERGARIS AGAR MEMENUHI HALAMAN PERSIS KARTU FISIK (MIN 28 BARIS) -->
            @for($i = $renderedRowsCount; $i < $totalRows; $i++)
                <tr>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
            @endfor
        </tbody>
    </table>

    <!-- 4. FOOTER: JATUH TEMPO -->
    <table class="footer-table">
        <tr>
            <td class="footer-due-date">
                JATUH TEMPO : {{ $dueDateFormatted }}
            </td>
        </tr>
    </table>

</body>
</html>
