<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class NotificationController extends Controller
{
    /**
     * GET /api/notifications
     * Mengembalikan 10 notifikasi terbaru dan jumlah unread_count
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $userId = $user ? $user->id : null;

            // Auto-sinkronisasi aktivitas pending yang membutuhkan perhatian
            $this->syncPendingActivities();

            $query = Notification::query()
                ->where(function ($q) use ($userId) {
                    $q->whereNull('user_id');
                    if ($userId) {
                        $q->orWhere('user_id', $userId);
                    }
                });

            $unreadCount = (clone $query)->where('is_read', false)->count();

            $notifications = $query->orderBy('created_at', 'desc')
                ->take($request->input('limit', 10))
                ->get();

            return response()->json([
                'success'      => true,
                'unread_count' => $unreadCount,
                'data'         => $notifications,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil notifikasi: ' . $e->getMessage(),
                'unread_count' => 0,
                'data'    => [],
            ], 500);
        }
    }

    /**
     * POST /api/notifications/mark-as-read
     * Mengubah status notifikasi menjadi sudah dibaca
     */
    public function markAsRead(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $userId = $user ? $user->id : null;
            $notificationId = $request->input('id') ?? $request->input('notification_id');

            $query = Notification::query();

            if ($notificationId) {
                $query->where('id', $notificationId);
            } else {
                $query->where(function ($q) use ($userId) {
                    $q->whereNull('user_id');
                    if ($userId) {
                        $q->orWhere('user_id', $userId);
                    }
                })->where('is_read', false);
            }

            $query->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

            // Hitung ulang unread count
            $remainingUnread = Notification::where(function ($q) use ($userId) {
                $q->whereNull('user_id');
                if ($userId) {
                    $q->orWhere('user_id', $userId);
                }
            })->where('is_read', false)->count();

            return response()->json([
                'success'      => true,
                'message'      => 'Notifikasi berhasil ditandai sudah dibaca.',
                'unread_count' => $remainingUnread,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui status notifikasi: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sinkronisasi otomatis notifikasi berdasarkan antrean aktivitas di database
     */
    private function syncPendingActivities(): void
    {
        try {
            // 1. Pengajuan Pinjaman Baru (Pending)
            if (Schema::hasTable('loans')) {
                $pendingLoans = Loan::with('member')->where('status', 'pending')->get();
                foreach ($pendingLoans as $loan) {
                    $memberName = $loan->member ? $loan->member->name : 'Anggota';
                    $formattedAmt = 'Rp ' . number_format((float) $loan->amount, 0, ',', '.');
                    
                    Notification::firstOrCreate(
                        [
                            'type' => 'loan_request',
                            'data->loan_id' => $loan->id,
                        ],
                        [
                            'title'   => 'Pengajuan Pinjaman Baru',
                            'message' => "{$memberName} mengajukan pinjaman sebesar {$formattedAmt}.",
                            'data'    => ['loan_id' => $loan->id, 'member_id' => $loan->member_id, 'amount' => $loan->amount],
                            'is_read' => false,
                            'created_at' => $loan->created_at ?? now(),
                        ]
                    );
                }
            }

            // 2. Pendaftaran Anggota Baru Butuh Verifikasi (Pending)
            if (Schema::hasTable('members')) {
                $pendingMembers = Member::where('status', 'pending')->get();
                foreach ($pendingMembers as $member) {
                    Notification::firstOrCreate(
                        [
                            'type' => 'new_member',
                            'data->member_id' => $member->id,
                        ],
                        [
                            'title'   => 'Pendaftaran Anggota Baru',
                            'message' => "Pendaftaran anggota {$member->name} ({$member->member_number}) membutuhkan verifikasi data.",
                            'data'    => ['member_id' => $member->id, 'name' => $member->name],
                            'is_read' => false,
                            'created_at' => $member->created_at ?? now(),
                        ]
                    );
                }
            }

            // 3. Transaksi Butuh Persetujuan ACC Manajer (Pending)
            if (Schema::hasTable('transactions')) {
                $pendingTrxs = Transaction::with('member')->where('status', 'pending')->get();
                foreach ($pendingTrxs as $trx) {
                    $memberName = $trx->member ? $trx->member->name : 'Anggota Umum';
                    $formattedAmt = 'Rp ' . number_format((float) $trx->amount, 0, ',', '.');
                    $prefix = in_array($trx->type, ['deposit', 'in', 'kas_masuk']) ? 'KM' : 'KK';

                    Notification::firstOrCreate(
                        [
                            'type' => 'transaction_approval',
                            'data->transaction_id' => $trx->id,
                        ],
                        [
                            'title'   => "Persetujuan Transaksi {$prefix}",
                            'message' => "Transaksi {$trx->description} atas nama {$memberName} senilai {$formattedAmt} membutuhkan persetujuan ACC Manajer.",
                            'data'    => ['transaction_id' => $trx->id, 'member_id' => $trx->member_id, 'amount' => $trx->amount],
                            'is_read' => false,
                            'created_at' => $trx->created_at ?? now(),
                        ]
                    );
                }
            }
        } catch (\Exception $e) {

        }
    }
}
