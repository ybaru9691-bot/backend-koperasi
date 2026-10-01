<?php

namespace App\Http\Middleware;

use App\Models\Transaction;
use App\Services\PeriodClosingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPeriodLockMiddleware
{
    /**
     * Routes yang dikecualikan dari pengecekan lock periode (misal auth, unlock, tutup buku itu sendiri).
     */
    protected array $exceptRoutes = [
        'api/login',
        'api/logout',
        'api/manager/periods/*',
        'api/periods/*',
        'api/accounting/lock-week',
        'api/accounting/unlock-week',
        'api/admin/reset-test-data',
        'api/system/reset-test-data',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Hanya cek request yang melakukan mutasi data (POST, PUT, PATCH, DELETE)
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            return $next($request);
        }

        // Cek apakah route masuk daftar pengecualian
        foreach ($this->exceptRoutes as $except) {
            if ($request->is($except)) {
                return $next($request);
            }
        }

        // 1. Cek tanggal dari payload request
        $payloadDate = $request->input('transaction_date')
            ?? $request->input('date')
            ?? $request->input('payment_date')
            ?? $request->input('entry_date');

        if ($payloadDate && PeriodClosingService::isDateLocked($payloadDate)) {
            return response()->json([
                'status'  => 'error',
                'success' => false,
                'message' => PeriodClosingService::LOCKED_MESSAGE,
                'error'   => 'LOCKED_PERIOD',
            ], 403);
        }

        // 2. Cek jika ada item array (bulk daily transactions)
        if ($request->has('items') && is_array($request->items)) {
            $baseDate = $payloadDate ?? now()->toDateString();
            if (PeriodClosingService::isDateLocked($baseDate)) {
                return response()->json([
                    'status'  => 'error',
                    'success' => false,
                    'message' => PeriodClosingService::LOCKED_MESSAGE,
                    'error'   => 'LOCKED_PERIOD',
                ], 403);
            }
        }

        // 3. Cek parameter ID transaksi jika route memodifikasi / menghapus transaksi yang sudah ada
        $routeParamId = $request->route('id') ?? $request->route('transaction');
        if ($routeParamId && is_numeric($routeParamId)) {
            $isTransactionRoute = $request->is('api/transactions/*')
                || $request->is('api/v1/transactions/*')
                || $request->is('api/daily-transactions/*')
                || $request->is('api/incomes/*')
                || $request->is('api/expenses/*')
                || $request->is('api/manager/transactions/*')
                || $request->is('api/manager/approvals/*');

            if ($isTransactionRoute) {
                $existingTrx = Transaction::find($routeParamId);
                if ($existingTrx && PeriodClosingService::isDateLocked($existingTrx->transaction_date)) {
                    return response()->json([
                        'status'  => 'error',
                        'success' => false,
                        'message' => PeriodClosingService::LOCKED_MESSAGE,
                        'error'   => 'LOCKED_PERIOD',
                    ], 403);
                }
            }
        }

        return $next($request);
    }
}
