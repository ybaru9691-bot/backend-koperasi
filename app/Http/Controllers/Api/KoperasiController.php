<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * KoperasiController (Legacy Adapter)
 * Memperluas ManagerDashboardController dan meneruskan approval ke TransactionApprovalController
 * untuk menjamin kompatibilitas mundur 100%.
 */
class KoperasiController extends ManagerDashboardController
{
    public function updateTransactionStatus(Request $request, $id): JsonResponse
    {
        return app(TransactionApprovalController::class)->updateTransactionStatus($request, $id);
    }

    public function approveTransaction($id): JsonResponse
    {
        return app(TransactionApprovalController::class)->approveTransaction($id);
    }

    public function rejectTransaction($id): JsonResponse
    {
        return app(TransactionApprovalController::class)->rejectTransaction($id);
    }
}
