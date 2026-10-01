<?php

namespace App\Services;

class DailySavingsPdfService
{
    protected BukuPutihLedgerService $ledgerService;

    public function __construct(BukuPutihLedgerService $ledgerService)
    {
        $this->ledgerService = $ledgerService;
    }

    /**
     * Generate or export Buku Putih PDF
     */
    public function exportPdf(int $memberId, ?int $periodId = null, ?int $fiscalYear = null, ?int $year = null)
    {
        return $this->ledgerService->exportPdf($memberId, $periodId, $fiscalYear, $year);
    }

    /**
     * Get member Buku Putih ledger statement data
     */
    public function getStatement(int $memberId, ?int $periodId = null, ?int $fiscalYear = null, ?int $year = null): array
    {
        return $this->ledgerService->getMemberBukuPutihLedger($memberId, $periodId, $fiscalYear, $year);
    }
}
