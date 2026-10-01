<?php

use Illuminate\Http\Request;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\ChartOfAccountController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ManagerDashboardController;
use App\Http\Controllers\Api\TransactionApprovalController;
use App\Http\Controllers\Api\MemberResignationController;
use App\Http\Controllers\Api\MemberMigrationController;
use App\Http\Controllers\Api\KoperasiController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\AdminProfileController;
use Illuminate\Support\Facades\Route;

/*
| API Routes - Backend Koperasi Pelita HKBP Dame
*/

// Endpoint Otentikasi Publik (Login NIK / Email)
Route::post('/login', [AuthController::class, 'login']);

// Endpoint Terproteksi (Laravel Sanctum Middleware)
Route::middleware('auth:sanctum')->group(function () {

    // Modul Member (CRUD Full & Detail Khusus)
    Route::get('/members/active-list', [MemberController::class, 'activeList']);
    Route::get('/members/active', [MemberController::class, 'activeList']);
    Route::get('/admin/members/active-list', [MemberController::class, 'activeList']);
    Route::get('/members/search', [MemberController::class, 'search']);
    Route::get('/members', [MemberController::class, 'index']);
    Route::post('/members', [MemberController::class, 'store']);
    
    // Member Profile Endpoint (Dinamis Berdasarkan Token Auth Anggota)
    Route::get('/member/profile', [DashboardController::class, 'memberProfile']);
    
    Route::get('/members/{id}', [MemberController::class, 'show']);
    Route::get('/members/{id}/details', [MemberController::class, 'showDetails']);
    Route::get('/members/{id}/balances', [MemberController::class, 'getBalances']);
    Route::put('/members/{id}', [MemberController::class, 'update']);
    Route::patch('/members/{id}', [MemberController::class, 'update']);
    Route::delete('/members/{id}', [MemberController::class, 'destroy']);
    
    // Modul Pinjaman (Loans)
    Route::post('/user/loans/apply', [LoanController::class, 'applyLoan']);
    Route::post('/loans/apply', [LoanController::class, 'applyLoan']);
    Route::get('/admin/loans/pending', [LoanController::class, 'pendingLoans']);
    Route::post('/admin/loans/{id}/verify', [LoanController::class, 'verify']);
    Route::post('/admin/loans/{id}/approve-admin', [LoanController::class, 'verify']);
    Route::post('/loans/{id}/verify', [LoanController::class, 'verify']);
    Route::post('/loans/{id}/approve-admin', [LoanController::class, 'verify']);
    Route::post('/loans/{id}/disburse', [LoanController::class, 'disburse']);
    Route::post('/admin/loans/{id}/disburse', [LoanController::class, 'disburse']);
    Route::post('/admin/loans/{id}/reject', [LoanController::class, 'rejectLoan']);
    Route::post('/loans/{id}/reject', [LoanController::class, 'rejectLoan']);

    // Migrasi Saldo Pinjaman Berjalan (Cut-off Balance)
    Route::post('/loans/migrate-existing', [LoanController::class, 'storeMigratedLoan']);
    Route::post('/admin/loans/migrate-existing', [LoanController::class, 'storeMigratedLoan']);
    Route::post('/loans/migrate-initial', [LoanController::class, 'storeMigratedLoan']);
    Route::post('/admin/loans/migrate-initial', [LoanController::class, 'storeMigratedLoan']);
    Route::post('/loans/initial-balance', [LoanController::class, 'storeMigratedLoan']);
    Route::post('/admin/loans/initial-balance', [LoanController::class, 'storeMigratedLoan']);

    // Endpoint Khusus Manajer (Role Protected: Manager / Ketua / Pengurus)
    Route::middleware('role:manager')->group(function () {
        Route::post('/loans/{id}/approve-manager', [LoanController::class, 'approveByManager']);
        Route::post('/loans/{id}/reject-manager', [LoanController::class, 'rejectByManager']);
        Route::post('/admin/loans/{id}/approve-manager', [LoanController::class, 'approveByManager']);
        Route::post('/admin/loans/{id}/reject-manager', [LoanController::class, 'rejectByManager']);
        Route::post('/manager/loans/{id}/approve', [LoanController::class, 'approveByManager']);
        Route::post('/manager/loans/{id}/approve-manager', [LoanController::class, 'approveByManager']);
        Route::post('/manager/loans/{id}/reject', [LoanController::class, 'rejectByManager']);
        Route::post('/manager/loans/{id}/reject-manager', [LoanController::class, 'rejectByManager']);
        Route::get('/manager/approvals/loans', [LoanController::class, 'pendingManagerLoans']);
        Route::get('/manager/approvals', [LoanController::class, 'pendingManagerLoans']);
        Route::get('/manager/loans/pending', [LoanController::class, 'pendingManagerLoans']);
    });
    
    // Detail & Kartu Pinjaman Kuning CUM PELITA
    Route::get('/loans/dropdown-list', [LoanController::class, 'getLoanDropdownList']);
    Route::get('/admin/loans/dropdown-list', [LoanController::class, 'getLoanDropdownList']);

    // Riwayat Transaksi Operasional Pinjaman (Pencairan & Angsuran)
    Route::get('/loans/transactions-history', [LoanController::class, 'getLoanTransactionsHistory']);
    Route::get('/admin/loans/transactions-history', [LoanController::class, 'getLoanTransactionsHistory']);
    Route::get('/loans/history', [LoanController::class, 'getLoanTransactionsHistory']);
    Route::get('/admin/loans/history', [LoanController::class, 'getLoanTransactionsHistory']);

    Route::get('/user/loans', [LoanController::class, 'getMyLoans']);
    Route::get('/admin/loans', [LoanController::class, 'getApprovedLoans']);
    Route::get('/loans', [LoanController::class, 'getApprovedLoans']);

    Route::get('/loans/{id}/card/pdf', [LoanController::class, 'exportLoanCardPdf']);
    Route::get('/admin/loans/{id}/card/pdf', [LoanController::class, 'exportLoanCardPdf']);
    Route::get('/loans/{id}/print', [LoanController::class, 'exportLoanCardPdf']);
    Route::get('/admin/loans/{id}/print', [LoanController::class, 'exportLoanCardPdf']);
    Route::get('/loans/{id}/card', [LoanController::class, 'getLoanCard']);
    Route::get('/admin/loans/{id}/card', [LoanController::class, 'getLoanCard']);
    Route::get('/loans/{id}/installments', [LoanController::class, 'getInstallments']);
    Route::get('/admin/loans/{id}/installments', [LoanController::class, 'getInstallments']);
    Route::get('/loans/{id}', [LoanController::class, 'show']);
    Route::get('/admin/loans/{id}', [LoanController::class, 'show']);
    Route::put('/loans/{id}', [LoanController::class, 'update']);
    Route::patch('/loans/{id}', [LoanController::class, 'update']);
    Route::post('/loans/{id}/update', [LoanController::class, 'update']);
    Route::put('/admin/loans/{id}', [LoanController::class, 'update']);
    Route::patch('/admin/loans/{id}', [LoanController::class, 'update']);
    Route::delete('/loans/{id}', [LoanController::class, 'destroy']);
    Route::delete('/admin/loans/{id}', [LoanController::class, 'destroy']);
    Route::post('/loans/{id}/delete', [LoanController::class, 'destroy']);

    // Pembayaran Cicilan / Angsuran
    Route::post('/loans/installments/{installmentId}/pay', [LoanController::class, 'payInstallment']);
    Route::post('/admin/loans/installments/{installmentId}/pay', [LoanController::class, 'payInstallment']);
    Route::post('/loans/repayments', [LoanController::class, 'storeRepayment']);
    Route::post('/admin/loans/repayments', [LoanController::class, 'storeRepayment']);
    Route::post('/loans/{id}/repayment', [LoanController::class, 'storeRepayment']);
    Route::post('/admin/loans/{id}/repayment', [LoanController::class, 'storeRepayment']);
    
    // Support baik PUT maupun POST untuk Reset Password & Reset PIN
    Route::put('/members/{id}/reset-password', [MemberController::class, 'resetPassword']);
    Route::post('/members/{id}/reset-password', [MemberController::class, 'resetPassword']);
    
    Route::put('/members/{id}/reset-pin', [MemberController::class, 'resetPin']);
    Route::post('/members/{id}/reset-pin', [MemberController::class, 'resetPin']);
    
    // Layanan Penutupan Rekening Buku Putih Saja (Simpanan Harian)
    Route::get('/members/{id}/close-white-book/preview', [MemberResignationController::class, 'previewCloseWhiteBook']);
    Route::post('/members/{id}/close-white-book', [MemberResignationController::class, 'closeWhiteBook']);
    Route::post('/manager/members/{id}/close-white-book', [MemberResignationController::class, 'closeWhiteBook']);
    Route::post('/admin/members/{id}/close-white-book', [MemberResignationController::class, 'closeWhiteBook']);

    // Layanan Pengunduran Diri / Resign Total Anggota (Buku Biru & Buku Putih)
    Route::get('/members/{id}/resign-total/preview', [MemberResignationController::class, 'previewResignTotal']);
    Route::get('/members/{id}/resign/preview', [MemberResignationController::class, 'previewResignTotal']);
    Route::post('/members/{id}/resign-total', [MemberResignationController::class, 'resignTotal']);
    Route::post('/manager/members/{id}/resign-total', [MemberResignationController::class, 'resignTotal']);
    Route::post('/admin/members/{id}/resign-total', [MemberResignationController::class, 'resignTotal']);
    Route::post('/members/{id}/resign', [MemberResignationController::class, 'resignMember']);
    Route::post('/manager/members/{id}/resign', [MemberResignationController::class, 'resignMember']);
    Route::post('/admin/members/{id}/resign', [MemberResignationController::class, 'resignMember']);

    // Layanan Unduh Template & Migrasi Saldo Awal Anggota
    Route::get('/members/migration/template', [MemberMigrationController::class, 'downloadTemplate']);
    Route::get('/admin/members/migration/template', [MemberMigrationController::class, 'downloadTemplate']);
    Route::get('/members/template', [MemberMigrationController::class, 'downloadTemplate']);
    Route::post('/members/import-initial', [MemberController::class, 'importInitialMembers']);
    Route::post('/admin/members/import-initial', [MemberController::class, 'importInitialMembers']);
    
    // Modul Transaksi (POST /api/transactions & POST /api/daily-transactions)
    Route::get('/transactions/today', [TransactionController::class, 'today']);
    Route::get('/transactions/pending', [TransactionController::class, 'pending']);
    Route::get('/transactions/daily', [TransactionController::class, 'dailyTransactions']);
    Route::get('/daily-transactions', [TransactionController::class, 'dailyTransactions']);
    Route::get('/transactions/latest', [TransactionController::class, 'latest']);
    Route::post('/transactions', [TransactionController::class, 'store']);
    Route::post('/daily-transactions', [TransactionController::class, 'store']);
    Route::post('/daily-savings/transaction', [TransactionController::class, 'dailySavingsTransaction']);
    Route::post('/admin/daily-savings/transaction', [TransactionController::class, 'dailySavingsTransaction']);
    Route::post('/transactions/import', [TransactionController::class, 'importExcel']);
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::put('/transactions/{id}', [TransactionController::class, 'update'])->whereNumber('id');
    Route::patch('/transactions/{id}', [TransactionController::class, 'update'])->whereNumber('id');
    Route::post('/transactions/{id}', [TransactionController::class, 'update'])->whereNumber('id');
    Route::post('/transactions/{id}/update', [TransactionController::class, 'update'])->whereNumber('id');
    Route::put('/v1/transactions/{id}', [TransactionController::class, 'update'])->whereNumber('id');
    Route::patch('/v1/transactions/{id}', [TransactionController::class, 'update'])->whereNumber('id');
    Route::post('/v1/transactions/{id}', [TransactionController::class, 'update'])->whereNumber('id');
    Route::post('/v1/transactions/{id}/update', [TransactionController::class, 'update'])->whereNumber('id');
    Route::delete('/transactions/batch', [TransactionController::class, 'destroyBatch']);
    Route::delete('/transactions/{id}', [TransactionController::class, 'destroy'])->whereNumber('id');

    // Modul Pendapatan Lain-lain / Kas Masuk (Income)
    Route::get('/incomes', [\App\Http\Controllers\Api\IncomeController::class, 'index']);
    Route::post('/incomes', [\App\Http\Controllers\Api\IncomeController::class, 'store']);
    Route::get('/transactions/income', [\App\Http\Controllers\Api\IncomeController::class, 'index']);
    Route::post('/transactions/income', [\App\Http\Controllers\Api\IncomeController::class, 'store']);
    Route::post('/transactions/other-income', [\App\Http\Controllers\Api\IncomeController::class, 'store']);
    
    // Check Profile via Closure
    Route::get('/user', function (Request $request) {
        return response()->json([
            'status'  => 'success',
            'message' => 'Profile retrieved successfully',
            'data'    => $request->user()
        ]);
    });

    // 1. Profil Admin / User yang sedang login & Pengaturan Akun Admin
    Route::get('/me', [DashboardController::class, 'me']);
    Route::get('/admin/profile', [AdminProfileController::class, 'show']);
    Route::put('/admin/profile', [AdminProfileController::class, 'update']);
    Route::post('/admin/profile', [AdminProfileController::class, 'update']);
    Route::patch('/admin/profile', [AdminProfileController::class, 'update']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // 2. Ringkasan Dashboard Admin & Grafik Arus Kas
    Route::get('/dashboard', [DashboardController::class, 'summary']);
    Route::get('/admin/dashboard', [DashboardController::class, 'summary']);
    Route::get('/dashboard-summary', [DashboardController::class, 'summary']);
    Route::get('/dashboard/cashflow-chart', [DashboardController::class, 'cashflowChart']);
    Route::get('/dashboard/monthly-trend', [DashboardController::class, 'monthlyTrend']);
    Route::get('/admin/dashboard/monthly-trend', [DashboardController::class, 'monthlyTrend']);

    // 2a. Notifikasi Real-Time
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/mark-as-read', [NotificationController::class, 'markAsRead']);

    // 2b. Laporan, Penyesuaian & Data Anggota Manajer
    Route::get('/manager/dashboard-summary', [ManagerDashboardController::class, 'getDashboardSummary']);
    Route::get('/manager/financial-summary', [ManagerDashboardController::class, 'getFinancialSummary']);
    Route::get('/manager/reports/summary', [ManagerDashboardController::class, 'getFinancialSummary']);
    Route::get('/manager/reports/executive-summary', [ManagerDashboardController::class, 'getFinancialSummary']);
    Route::get('/manager/reports/executive-summary/pdf', [ManagerDashboardController::class, 'exportExecutiveSummaryPdf']);
    Route::post('/manager/trigger-monthly-interest', [ManagerDashboardController::class, 'triggerMonthlyInterest']);
    Route::post('/manager/transactions/adjust-balance', [ManagerDashboardController::class, 'adjustBalance']);
    Route::post('/manager/transactions/expense', [\App\Http\Controllers\Api\ManagerCashTransactionController::class, 'storeExpense']);
    Route::post('/manager/transactions/income', [\App\Http\Controllers\Api\ManagerCashTransactionController::class, 'storeIncome']);
    Route::post('/manager/cash-transactions/expense', [\App\Http\Controllers\Api\ManagerCashTransactionController::class, 'storeExpense']);
    Route::post('/manager/cash-transactions/income', [\App\Http\Controllers\Api\ManagerCashTransactionController::class, 'storeIncome']);
    Route::post('/manager/expense', [\App\Http\Controllers\Api\ManagerCashTransactionController::class, 'storeExpense']);
    Route::post('/manager/income', [\App\Http\Controllers\Api\ManagerCashTransactionController::class, 'storeIncome']);
    Route::post('/expenses', [\App\Http\Controllers\Api\ManagerCashTransactionController::class, 'storeExpense']);
    Route::get('/manager/members/stats', [ManagerDashboardController::class, 'getMemberStats']);
    Route::get('/manager/members', [ManagerDashboardController::class, 'getManagerMembers']);
    Route::get('/manager/members/{id}/detail', [ManagerDashboardController::class, 'getManagerMemberDetail']);
    Route::get('/manager/members/{id}', [ManagerDashboardController::class, 'getManagerMemberDetail']);

    // 2c. Pembagian Bunga Buku Putih (0.6%) & Bukti Memorial
    Route::get('/manager/interest/preview', [\App\Http\Controllers\Api\InterestController::class, 'preview']);
    Route::post('/manager/interest/distribute-buku-putih', [\App\Http\Controllers\Api\InterestController::class, 'distribute']);
    Route::post('/manager/interest/distribute', [\App\Http\Controllers\Api\InterestController::class, 'distribute']);

    // 2c. Pembagian Deviden / SHU Buku Biru, Bukti Memorial & Lembar Saham Anggota (Eksklusif Role Manajer)
    Route::middleware('role:manager')->group(function () {
        Route::get('/manager/dividends/preview', [\App\Http\Controllers\Api\DividendController::class, 'preview']);
        Route::get('/dividends/preview', [\App\Http\Controllers\Api\DividendController::class, 'preview']);
        Route::post('/manager/dividends/distribute', [\App\Http\Controllers\Api\DividendController::class, 'distribute']);
        Route::post('/dividends/distribute', [\App\Http\Controllers\Api\DividendController::class, 'distribute']);
        Route::get('/shu/monthly-status', [\App\Http\Controllers\Api\DividendController::class, 'monthlyStatus']);
        Route::get('/manager/shu/monthly-status', [\App\Http\Controllers\Api\DividendController::class, 'monthlyStatus']);
        Route::get('/dividends/monthly-status', [\App\Http\Controllers\Api\DividendController::class, 'monthlyStatus']);
        Route::get('/manager/dividends/monthly-status', [\App\Http\Controllers\Api\DividendController::class, 'monthlyStatus']);
        Route::post('/shu/distribute', [\App\Http\Controllers\Api\ShuController::class, 'distribute']);
        Route::post('/manager/shu/distribute', [\App\Http\Controllers\Api\ShuController::class, 'distribute']);

        // 2c. Modul SHU / Pembagian Deviden Bulanan Koperasi (Eksklusif Role Manajer)
        Route::post('/shu/monthly-record', [\App\Http\Controllers\Api\ShuController::class, 'monthlyRecord']);
        Route::post('/manager/shu/monthly-record', [\App\Http\Controllers\Api\ShuController::class, 'monthlyRecord']);
        Route::post('/shu/record', [\App\Http\Controllers\Api\ShuController::class, 'monthlyRecord']);
        Route::get('/shu/summary', [\App\Http\Controllers\Api\ShuController::class, 'summary']);
        Route::get('/shu/monthly-records', [\App\Http\Controllers\Api\ShuController::class, 'summary']);
        Route::get('/shu/preview', [\App\Http\Controllers\Api\ShuController::class, 'preview']);

        // 2c. Tabel Acuan / Master Parameter SHU Bulanan (Monthly Cooperative Benchmarks)
        Route::get('/manager/coop-benchmarks', [\App\Http\Controllers\Api\MonthlyCooperativeBenchmarkController::class, 'index']);
        Route::post('/manager/coop-benchmarks/update', [\App\Http\Controllers\Api\MonthlyCooperativeBenchmarkController::class, 'update']);
        Route::post('/manager/coop-benchmarks', [\App\Http\Controllers\Api\MonthlyCooperativeBenchmarkController::class, 'update']);
        Route::put('/manager/coop-benchmarks', [\App\Http\Controllers\Api\MonthlyCooperativeBenchmarkController::class, 'update']);

        // 2c. Pengaturan Manajer (Setting SHU Percentage)
        Route::prefix('manager')->group(function () {
            Route::prefix('settings')->group(function () {
                Route::get('/shu-percentage', [\App\Http\Controllers\Api\ManagerSettingController::class, 'getShuPercentage']);
                Route::put('/shu-percentage', [\App\Http\Controllers\Api\ManagerSettingController::class, 'updateShuPercentage']);
                Route::post('/shu-percentage', [\App\Http\Controllers\Api\ManagerSettingController::class, 'updateShuPercentage']);
            });
        });
    });

    // Statement & Ledger (Dapat dibaca oleh user/anggota untuk laporan individual)
    Route::get('/manager/members/{id}/dividend-statement', [\App\Http\Controllers\Api\DividendController::class, 'memberStatement']);
    Route::get('/members/{id}/dividend-statement', [\App\Http\Controllers\Api\DividendController::class, 'memberStatement']);
    Route::get('/manager/members/{id}/white-book-statement', [\App\Http\Controllers\Api\InterestController::class, 'memberWhiteBookStatement']);
    Route::get('/members/{id}/white-book-statement', [\App\Http\Controllers\Api\InterestController::class, 'memberWhiteBookStatement']);

    // 2c. Mutasi & Cetak Buku Putih (Simpanan Harian) 12 Bulan & Toggle Status Keaktifan
    Route::patch('/buku-putih/members/{id}/toggle-status', [\App\Http\Controllers\Api\BukuPutihLedgerController::class, 'toggleStatus']);
    Route::post('/buku-putih/members/{id}/toggle-status', [\App\Http\Controllers\Api\BukuPutihLedgerController::class, 'toggleStatus']);
    Route::patch('/members/{id}/toggle-status', [\App\Http\Controllers\Api\BukuPutihLedgerController::class, 'toggleStatus']);
    Route::post('/members/{id}/toggle-status', [\App\Http\Controllers\Api\BukuPutihLedgerController::class, 'toggleStatus']);
    Route::patch('/members/{id}/status', [\App\Http\Controllers\Api\BukuPutihLedgerController::class, 'toggleStatus']);
    Route::post('/members/{id}/status', [\App\Http\Controllers\Api\BukuPutihLedgerController::class, 'toggleStatus']);
    Route::patch('/manager/members/{id}/toggle-status', [\App\Http\Controllers\Api\BukuPutihLedgerController::class, 'toggleStatus']);
    Route::post('/manager/members/{id}/toggle-status', [\App\Http\Controllers\Api\BukuPutihLedgerController::class, 'toggleStatus']);
    Route::patch('/manager/members/{id}/status', [\App\Http\Controllers\Api\BukuPutihLedgerController::class, 'toggleStatus']);
    Route::post('/manager/members/{id}/status', [\App\Http\Controllers\Api\BukuPutihLedgerController::class, 'toggleStatus']);
    Route::get('/manager/members/{id}/buku-putih-ledger', [\App\Http\Controllers\Api\BukuPutihLedgerController::class, 'getLedger']);
    Route::get('/members/{id}/buku-putih-ledger', [\App\Http\Controllers\Api\BukuPutihLedgerController::class, 'getLedger']);

    // 2d. Manajemen Periode & Tutup Buku (Period Management & Closing)
    Route::prefix('manager')->group(function () {
        Route::prefix('periods')->group(function () {
            Route::get('/active', [\App\Http\Controllers\Api\PeriodController::class, 'getActivePeriod']);
            Route::post('/open-period', [\App\Http\Controllers\Api\PeriodController::class, 'openPeriod']);
            Route::post('/buka-periode', [\App\Http\Controllers\Api\PeriodController::class, 'openPeriod']);
            Route::post('/create', [\App\Http\Controllers\Api\PeriodController::class, 'createPeriod']);
            Route::post('/close-period', [\App\Http\Controllers\Api\PeriodController::class, 'closePeriod']);
            Route::post('/tutup-buku', [\App\Http\Controllers\Api\PeriodController::class, 'closePeriod']);
            Route::post('/{id}/unlock', [\App\Http\Controllers\Api\PeriodController::class, 'unlockPeriod']);
            Route::post('/unlock/{id}', [\App\Http\Controllers\Api\PeriodController::class, 'unlockPeriod']);
            Route::delete('/{id}', [\App\Http\Controllers\Api\PeriodController::class, 'destroy']);
            Route::delete('/delete/{id}', [\App\Http\Controllers\Api\PeriodController::class, 'destroy']);
            Route::get('/history', [\App\Http\Controllers\Api\PeriodController::class, 'history']);
            Route::get('/', [\App\Http\Controllers\Api\PeriodController::class, 'history']);
            Route::post('/', [\App\Http\Controllers\Api\PeriodController::class, 'createPeriod']);
        });
    });

    // Riwayat Distribusi SHU Anggota
    Route::get('/members/{id}/shu-history', [\App\Http\Controllers\Api\PeriodController::class, 'getMemberShuHistory']);
    Route::get('/member/shu-history', [\App\Http\Controllers\Api\PeriodController::class, 'getMemberShuHistory']);

    // 3. Persetujuan / Approval Status Transaksi oleh Ketua/Admin (Disetujui / Ditolak)
    Route::put('/transactions/{id}/status', [TransactionApprovalController::class, 'updateTransactionStatus']);
    Route::patch('/transactions/{id}/status', [TransactionApprovalController::class, 'updateTransactionStatus']);

    // 3b. Manajer Approvals (Approve / Reject)
    Route::post('/manager/approvals/{id}/approve', [TransactionApprovalController::class, 'approveTransaction']);
    Route::post('/manager/approvals/{id}/reject', [TransactionApprovalController::class, 'rejectTransaction']);

    // 4. Modul Pengumuman / Berita Koperasi (CRUD)
    Route::get('/announcements', [AnnouncementController::class, 'index']);
    Route::get('/announcements/{id}', [AnnouncementController::class, 'show']);
    Route::post('/announcements', [AnnouncementController::class, 'store']);
    Route::put('/announcements/{id}', [AnnouncementController::class, 'update']);
    Route::delete('/announcements/{id}', [AnnouncementController::class, 'destroy']);

    // 5. Modul Chart of Accounts / Kode Perkiraan (COA)
    Route::get('/accounts', [ChartOfAccountController::class, 'getAccounts']);
    Route::get('/chart-of-accounts/dropdown', [ChartOfAccountController::class, 'getAccounts']);
    Route::get('/chart-of-accounts', [ChartOfAccountController::class, 'index']);
    Route::get('/chart-of-accounts/{id}', [ChartOfAccountController::class, 'show']);
    Route::post('/chart-of-accounts', [ChartOfAccountController::class, 'store']);
    Route::put('/chart-of-accounts/{id}', [ChartOfAccountController::class, 'update']);
    Route::delete('/chart-of-accounts/{id}', [ChartOfAccountController::class, 'destroy']);

    // 6. Laporan Akuntansi (Neraca Lajur, Laba/Rugi, Buku Besar, Jurnal Tabelaris, Rekap Kas)
    Route::get('/reports/cash-recap', [ReportController::class, 'cashRecap']);
    Route::get('/admin/reports/cash-recap', [ReportController::class, 'cashRecap']);
    Route::get('/reports/trial-balance', [ReportController::class, 'trialBalance']);
    Route::get('/accounting/worksheet', [\App\Http\Controllers\Api\AccountingController::class, 'worksheet']);
    Route::get('/neraca-lajur', [\App\Http\Controllers\Api\NeracaLajurController::class, 'index']);
    Route::get('/reports/neraca-lajur', [\App\Http\Controllers\Api\NeracaLajurController::class, 'index']);
    Route::post('/accounting/lock-week', [\App\Http\Controllers\Api\AccountingController::class, 'lockWeek']);
    Route::post('/accounting/unlock-week', [\App\Http\Controllers\Api\AccountingController::class, 'unlockWeek']);
    Route::post('/periods/lock-week', [\App\Http\Controllers\Api\AccountingController::class, 'lockWeek']);
    Route::get('/reports/income-statement', [ReportController::class, 'incomeStatement']);
    Route::get('/reports/journal-ledger', [ReportController::class, 'journalLedger']);
    Route::get('/ledger', [ReportController::class, 'getLedger']);

    // 6c. Jurnal Tabelaris 29 Kolom (Format Presisi Manual Koperasi)
    Route::get('/v1/tabelaris', [\App\Http\Controllers\Api\TabelarisController::class, 'index']);
    Route::get('/tabelaris', [\App\Http\Controllers\Api\TabelarisController::class, 'index']);
    Route::get('/reports/tabelaris', [\App\Http\Controllers\Api\TabelarisController::class, 'index']);

    // 6d. Saldo Awal Pembukuan / Cut-Off Initial Balances
    Route::get('/initial-balances', [\App\Http\Controllers\Api\InitialBalanceController::class, 'index']);
    Route::post('/initial-balances', [\App\Http\Controllers\Api\InitialBalanceController::class, 'store']);

    // 7. System Reset (Super Admin & Admin)
    Route::post('/admin/reset-test-data', [ManagerDashboardController::class, 'resetTestData']);
    Route::post('/system/reset-test-data', [ManagerDashboardController::class, 'resetTestData']);
});

// Export endpoints (Public fallback with Sanctum Bearer or query ?token= validation)
Route::match(['GET', 'POST'], '/v1/tabelaris/export-excel', [\App\Http\Controllers\Api\TabelarisController::class, 'exportExcel']);
Route::match(['GET', 'POST'], '/tabelaris/export-excel', [\App\Http\Controllers\Api\TabelarisController::class, 'exportExcel']);
Route::match(['GET', 'POST'], '/reports/tabelaris/export-excel', [\App\Http\Controllers\Api\TabelarisController::class, 'exportExcel']);
Route::match(['GET', 'POST'], '/v1/tabelaris/export-pdf', [\App\Http\Controllers\Api\TabelarisController::class, 'exportPdf']);
Route::match(['GET', 'POST'], '/tabelaris/export-pdf', [\App\Http\Controllers\Api\TabelarisController::class, 'exportPdf']);
Route::match(['GET', 'POST'], '/reports/tabelaris/export-pdf', [\App\Http\Controllers\Api\TabelarisController::class, 'exportPdf']);
Route::match(['GET', 'POST'], '/reports/trial-balance/export/{type}', [ReportController::class, 'exportTrialBalance']);

// Ledger & Buku Besar Export Route Aliases (Supports both GET & POST via Bearer token or ?token=)
Route::match(['GET', 'POST'], '/ledger/export-excel', [ReportController::class, 'exportExcelLedger']);
Route::match(['GET', 'POST'], '/ledger/export-pdf', [ReportController::class, 'exportPdfLedger']);
Route::match(['GET', 'POST'], '/ledger/export/{type}', [ReportController::class, 'exportJournalLedger']);
Route::match(['GET', 'POST'], '/reports/ledger/export-excel', [ReportController::class, 'exportExcelLedger']);
Route::match(['GET', 'POST'], '/reports/ledger/export-pdf', [ReportController::class, 'exportPdfLedger']);
Route::match(['GET', 'POST'], '/reports/ledger/export/{type}', [ReportController::class, 'exportJournalLedger']);
Route::match(['GET', 'POST'], '/reports/journal-ledger/export-excel', [ReportController::class, 'exportExcelLedger']);
Route::match(['GET', 'POST'], '/reports/journal-ledger/export-pdf', [ReportController::class, 'exportPdfLedger']);
Route::match(['GET', 'POST'], '/reports/journal-ledger/export/{type}', [ReportController::class, 'exportJournalLedger']);

// Bukti Memorial Bunga Buku Putih Export Routes
Route::match(['GET', 'POST'], '/manager/interest/export-memorial-pdf', [\App\Http\Controllers\Api\InterestController::class, 'exportMemorialPdf']);
Route::match(['GET', 'POST'], '/manager/interest/export-memorial-excel', [\App\Http\Controllers\Api\InterestController::class, 'exportMemorialExcel']);
Route::match(['GET', 'POST'], '/interest/export-memorial-pdf', [\App\Http\Controllers\Api\InterestController::class, 'exportMemorialPdf']);
Route::match(['GET', 'POST'], '/interest/export-memorial-excel', [\App\Http\Controllers\Api\InterestController::class, 'exportMemorialExcel']);

// Laporan Pembagian Deviden Buku Biru Export Routes
Route::match(['GET', 'POST'], '/manager/dividends/export-pdf', [\App\Http\Controllers\Api\DividendController::class, 'exportPdf']);
Route::match(['GET', 'POST'], '/dividends/export-pdf', [\App\Http\Controllers\Api\DividendController::class, 'exportPdf']);

// Lembar Buku Saham & Deviden Anggota Export Routes
Route::match(['GET', 'POST'], '/manager/members/{id}/dividend-statement/export-pdf', [\App\Http\Controllers\Api\DividendController::class, 'exportMemberStatementPdf']);
Route::match(['GET', 'POST'], '/members/{id}/dividend-statement/export-pdf', [\App\Http\Controllers\Api\DividendController::class, 'exportMemberStatementPdf']);

// Lembar Buku Putih Anggota Export Routes
Route::match(['GET', 'POST'], '/manager/members/{id}/white-book-statement/export-pdf', [\App\Http\Controllers\Api\InterestController::class, 'exportMemberWhiteBookStatementPdf']);
Route::match(['GET', 'POST'], '/members/{id}/white-book-statement/export-pdf', [\App\Http\Controllers\Api\InterestController::class, 'exportMemberWhiteBookStatementPdf']);
Route::match(['GET', 'POST'], '/manager/members/{id}/buku-putih-ledger/export-pdf', [\App\Http\Controllers\Api\BukuPutihLedgerController::class, 'exportPdf']);
Route::match(['GET', 'POST'], '/members/{id}/buku-putih-ledger/export-pdf', [\App\Http\Controllers\Api\BukuPutihLedgerController::class, 'exportPdf']);

// Template Migrasi Saldo Awal Anggota Export Routes
Route::match(['GET', 'POST'], '/members/migration/template/download', [MemberMigrationController::class, 'downloadTemplate']);
Route::match(['GET', 'POST'], '/admin/members/migration/template/download', [MemberMigrationController::class, 'downloadTemplate']);

// Cetak & Export PDF Kartu Pinjaman Kuning (Mendukung Bearer Token dan ?token= pada Tab Baru Browser)
Route::match(['GET', 'POST'], '/loans/{id}/print', [LoanController::class, 'exportLoanCardPdf']);
Route::match(['GET', 'POST'], '/admin/loans/{id}/print', [LoanController::class, 'exportLoanCardPdf']);
Route::match(['GET', 'POST'], '/loans/{id}/card/pdf', [LoanController::class, 'exportLoanCardPdf']);
Route::match(['GET', 'POST'], '/admin/loans/{id}/card/pdf', [LoanController::class, 'exportLoanCardPdf']);