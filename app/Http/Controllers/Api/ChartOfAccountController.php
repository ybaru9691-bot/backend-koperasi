<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ChartOfAccountController extends Controller
{
    /**
     * GET /api/chart-of-accounts
     * Daftar semua COA, bisa difilter berdasarkan tipe akun.
     * Query params: ?type=ASSET|LIABILITY|EQUITY|REVENUE|EXPENSE
     *               ?active_only=true (default: true)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = ChartOfAccount::query();

            // Filter aktif (default true)
            if ($request->boolean('active_only', true)) {
                $query->active();
            }

            // Filter berdasarkan tipe akun
            if ($request->filled('type')) {
                $query->ofType($request->input('type'));
            }

            $accounts = $query->orderBy('account_code', 'asc')->get();

            $data = $accounts->map(function ($acc) {
                return [
                    'id'                 => $acc->id,
                    'account_code'       => $acc->account_code,
                    'account_name'       => $acc->account_name,
                    'account_type'       => $acc->account_type,
                    'account_type_label' => $acc->account_type_label,
                    'normal_balance'     => $acc->normal_balance,
                    'display_label'      => $acc->display_label,
                    'parent_code'        => $acc->parent_code,
                    'is_active'          => $acc->is_active,
                ];
            });

            // Grouping per tipe untuk tampilan terstruktur
            $grouped = $data->groupBy('account_type')->map(function ($items, $type) {
                return [
                    'type'    => $type,
                    'label'   => $items->first()['account_type_label'] ?? $type,
                    'count'   => $items->count(),
                    'accounts' => $items->values(),
                ];
            })->values();

            return response()->json([
                'success' => true,
                'message' => 'Daftar Chart of Accounts berhasil diambil',
                'total'   => $data->count(),
                'data'    => $data->values(),
                'grouped' => $grouped,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil daftar COA: ' . $e->getMessage(),
                'data'    => [],
            ], 200);
        }
    }

    /**
     * GET /api/chart-of-accounts/{id}
     * Detail satu akun COA.
     */
    public function show($id): JsonResponse
    {
        try {
            $acc = ChartOfAccount::find($id);

            if (!$acc) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akun COA tidak ditemukan.',
                    'data'    => null,
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Detail akun COA berhasil diambil',
                'data'    => [
                    'id'                 => $acc->id,
                    'account_code'       => $acc->account_code,
                    'account_name'       => $acc->account_name,
                    'account_type'       => $acc->account_type,
                    'account_type_label' => $acc->account_type_label,
                    'normal_balance'     => $acc->normal_balance,
                    'display_label'      => $acc->display_label,
                    'parent_code'        => $acc->parent_code,
                    'is_active'          => $acc->is_active,
                    'children'           => $acc->children->map(fn($c) => [
                        'id'           => $c->id,
                        'account_code' => $c->account_code,
                        'account_name' => $c->account_name,
                    ]),
                    'created_at' => $acc->created_at ? $acc->created_at->toISOString() : null,
                    'updated_at' => $acc->updated_at ? $acc->updated_at->toISOString() : null,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil detail COA: ' . $e->getMessage(),
                'data'    => null,
            ], 200);
        }
    }

    /**
     * POST /api/chart-of-accounts
     * Tambah akun COA baru.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'account_code'   => 'required|string|max:10|unique:chart_of_accounts,account_code',
                'account_name'   => 'required|string|max:255',
                'account_type'   => 'required|string|in:ASSET,LIABILITY,EQUITY,REVENUE,EXPENSE',
                'normal_balance' => 'required|string|in:DEBIT,CREDIT',
                'parent_code'    => 'nullable|string|max:10|exists:chart_of_accounts,account_code',
                'is_active'      => 'nullable|boolean',
            ], [
                'account_code.required' => 'Kode akun wajib diisi!',
                'account_code.unique'   => 'Kode akun ini sudah terdaftar!',
                'account_name.required' => 'Nama akun wajib diisi!',
                'account_type.required' => 'Tipe akun wajib diisi!',
                'account_type.in'       => 'Tipe akun harus: ASSET, LIABILITY, EQUITY, REVENUE, atau EXPENSE.',
                'normal_balance.in'     => 'Saldo normal harus: DEBIT atau CREDIT.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            $acc = ChartOfAccount::create([
                'account_code'   => $request->input('account_code'),
                'account_name'   => $request->input('account_name'),
                'account_type'   => strtoupper($request->input('account_type')),
                'normal_balance' => strtoupper($request->input('normal_balance')),
                'parent_code'    => $request->input('parent_code'),
                'is_active'      => $request->boolean('is_active', true),
            ]);

            return response()->json([
                'success' => true,
                'message' => "Akun COA [{$acc->account_code}] {$acc->account_name} berhasil ditambahkan!",
                'data'    => [
                    'id'                 => $acc->id,
                    'account_code'       => $acc->account_code,
                    'account_name'       => $acc->account_name,
                    'account_type'       => $acc->account_type,
                    'account_type_label' => $acc->account_type_label,
                    'normal_balance'     => $acc->normal_balance,
                    'display_label'      => $acc->display_label,
                    'is_active'          => $acc->is_active,
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan akun COA: ' . $e->getMessage(),
                'data'    => null,
            ], 200);
        }
    }

    /**
     * PUT /api/chart-of-accounts/{id}
     * Edit akun COA.
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $acc = ChartOfAccount::find($id);

            if (!$acc) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akun COA tidak ditemukan.',
                    'data'    => null,
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'account_code'   => 'sometimes|required|string|max:10|unique:chart_of_accounts,account_code,' . $id,
                'account_name'   => 'sometimes|required|string|max:255',
                'account_type'   => 'nullable|string|in:ASSET,LIABILITY,EQUITY,REVENUE,EXPENSE',
                'normal_balance' => 'nullable|string|in:DEBIT,CREDIT',
                'parent_code'    => 'nullable|string|max:10',
                'is_active'      => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            $dataToUpdate = [];
            if ($request->has('account_code'))   $dataToUpdate['account_code']   = $request->input('account_code');
            if ($request->has('account_name'))   $dataToUpdate['account_name']   = $request->input('account_name');
            if ($request->has('account_type'))   $dataToUpdate['account_type']   = strtoupper($request->input('account_type'));
            if ($request->has('normal_balance')) $dataToUpdate['normal_balance'] = strtoupper($request->input('normal_balance'));
            if ($request->has('parent_code'))    $dataToUpdate['parent_code']    = $request->input('parent_code');
            if ($request->has('is_active'))      $dataToUpdate['is_active']      = $request->boolean('is_active');

            $acc->update($dataToUpdate);
            $acc->refresh();

            return response()->json([
                'success' => true,
                'message' => "Akun COA [{$acc->account_code}] berhasil diperbarui!",
                'data'    => [
                    'id'                 => $acc->id,
                    'account_code'       => $acc->account_code,
                    'account_name'       => $acc->account_name,
                    'account_type'       => $acc->account_type,
                    'account_type_label' => $acc->account_type_label,
                    'normal_balance'     => $acc->normal_balance,
                    'display_label'      => $acc->display_label,
                    'is_active'          => $acc->is_active,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui akun COA: ' . $e->getMessage(),
                'data'    => null,
            ], 200);
        }
    }

    /**
     * DELETE /api/chart-of-accounts/{id}
     * Hapus akun COA (soft: set is_active=false, atau hard delete).
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        try {
            $acc = ChartOfAccount::find($id);

            if (!$acc) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akun COA tidak ditemukan.',
                ], 404);
            }

            $label = $acc->display_label;

            // Default: soft delete (nonaktifkan). Gunakan ?force=true untuk hard delete
            if ($request->boolean('force')) {
                $acc->delete();
                return response()->json([
                    'success' => true,
                    'message' => "Akun COA {$label} berhasil dihapus permanen!",
                ], 200);
            }

            $acc->update(['is_active' => false]);
            return response()->json([
                'success' => true,
                'message' => "Akun COA {$label} berhasil dinonaktifkan!",
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus akun COA: ' . $e->getMessage(),
            ], 200);
        }
    }


    
    public function getAccounts(Request $request): JsonResponse
    {
        try {
            $query = ChartOfAccount::query();

            // Filter aktif (default true)
            if ($request->boolean('active_only', true)) {
                $query->active();
            }

            // Filter berdasarkan category (income, expense, asset, liability, equity, dll)
            if ($request->filled('category')) {
                $cat = strtolower($request->category);
                if (in_array($cat, ['income', 'pendapatan', 'revenue'])) {
                    $query->where(function ($q) {
                        $q->where('account_type', 'REVENUE')
                          ->orWhere('account_code', 'like', '4%');
                    });
                } elseif (in_array($cat, ['expense', 'beban', 'biaya', 'pengeluaran'])) {
                    $query->where(function ($q) {
                        $q->where('account_type', 'EXPENSE')
                          ->orWhere('account_code', 'like', '7%');
                    });
                } elseif (in_array($cat, ['asset', 'aktiva', 'harta'])) {
                    $query->where(function ($q) {
                        $q->where('account_type', 'ASSET')
                          ->orWhere('account_code', 'like', '1%');
                    });
                } elseif (in_array($cat, ['liability', 'kewajiban', 'hutang'])) {
                    $query->where(function ($q) {
                        $q->where('account_type', 'LIABILITY')
                          ->orWhere('account_code', 'like', '2%');
                    });
                } elseif (in_array($cat, ['equity', 'modal'])) {
                    $query->where(function ($q) {
                        $q->where('account_type', 'EQUITY')
                          ->orWhere('account_code', 'like', '3%');
                    });
                } else {
                    $query->where('account_type', strtoupper($cat));
                }
            }

            // Filter berdasarkan head (1, 2, 3, 4, 7, dll)
            if ($request->filled('head')) {
                $head = $request->head;
                $query->where('account_code', 'like', "{$head}%");
            }

            // Filter berdasarkan type
            if ($request->filled('type')) {
                $query->where('account_type', strtoupper($request->type));
            }

            // Filter search
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('account_code', 'like', "%{$search}%")
                      ->orWhere('account_name', 'like', "%{$search}%");
                });
            }

            $accounts = $query->orderBy('account_code', 'asc')->get();

            $data = $accounts->map(function ($acc) {
                return [
                    'id'           => $acc->id,
                    'code'         => $acc->account_code,
                    'name'         => $acc->account_name,
                    'account_code' => $acc->account_code,
                    'account_name' => $acc->account_name,
                    'account_type' => $acc->account_type,
                    'display_label'=> "[{$acc->account_code}] {$acc->account_name}",
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $data->values(),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data akun: ' . $e->getMessage(),
            ], 500);
        }
    }
}
