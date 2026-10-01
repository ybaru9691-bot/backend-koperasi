<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MemberMigrationController extends Controller
{
    /**
     * Download Excel / CSV Template for Initial Member Balance Migration.
     *
     * Endpoint: GET /api/members/migration/template
     */
    public function downloadTemplate(Request $request): StreamedResponse
    {
        $format = strtolower($request->query('format', 'xlsx'));

        $headers = [
            'member_number',
            'nik',
            'name',
            'buku_putih_no',
            'phone_number',
            'status',
            'principal_savings',
            'mandatory_savings',
            'voluntary_savings',
            'daily_savings',
        ];

        $sampleData = [
            [
                '0001',
                '1403094112820007',
                'Basalina Hutauruk',
                '2021-0017',
                '081234567890',
                'Active',
                200000,
                4080000,
                12020000,
                744486,
            ],
            [
                '0002',
                '1403094112820008',
                'Binsar Pandiangan',
                '2021-0018',
                '081234567891',
                'Active',
                200000,
                3500000,
                8500000,
                500000,
            ],
        ];

        if ($format === 'csv') {
            $fileName = 'Template_Migrasi_Saldo_Anggota.csv';
            return response()->streamDownload(function () use ($headers, $sampleData) {
                $handle = fopen('php://output', 'w');
                // Include UTF-8 BOM for Excel compatibility
                fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
                
                // Write Header Row
                fwrite($handle, implode(',', $headers) . "\n");

                // Write Sample Rows
                foreach ($sampleData as $row) {
                    fwrite($handle, implode(',', $row) . "\n");
                }

                fclose($handle);
            }, $fileName, [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
                'Pragma'              => 'no-cache',
                'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
                'Expires'             => '0',
            ]);
        }

        // Default: XLSX
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Migrasi Anggota');

        // Header Row
        $sheet->fromArray($headers, null, 'A1');

        // Header Styling
        $headerRange = 'A1:J1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size'  => 11,
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1E3A8A'], // Navy Blue
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => 'D1D5DB'],
                ],
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);

        // Data Rows
        $rowNum = 2;
        foreach ($sampleData as $rowData) {
            // Write text columns as explicit string format to preserve leading zeros
            $sheet->setCellValueExplicit('A' . $rowNum, (string) $rowData[0], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B' . $rowNum, (string) $rowData[1], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('C' . $rowNum, $rowData[2]);
            $sheet->setCellValueExplicit('D' . $rowNum, (string) $rowData[3], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('E' . $rowNum, (string) $rowData[4], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('F' . $rowNum, $rowData[5]);
            
            // Numeric currency amounts
            $sheet->setCellValue('G' . $rowNum, $rowData[6]);
            $sheet->setCellValue('H' . $rowNum, $rowData[7]);
            $sheet->setCellValue('I' . $rowNum, $rowData[8]);
            $sheet->setCellValue('J' . $rowNum, $rowData[9]);

            // Number formatting for savings columns
            $sheet->getStyle('G' . $rowNum . ':J' . $rowNum)->getNumberFormat()->setFormatCode('#,##0');

            // Alignment & Borders
            $sheet->getStyle('A' . $rowNum . ':B' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('D' . $rowNum . ':F' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('G' . $rowNum . ':J' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('A' . $rowNum . ':J' . $rowNum)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E5E7EB');

            $sheet->getRowDimension($rowNum)->setRowHeight(22);
            $rowNum++;
        }

        // Auto size columns
        foreach (range('A', 'J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $fileName = 'Template_Migrasi_Saldo_Anggota.xlsx';
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ]);
    }
}