<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AnnouncementController extends Controller
{
    /**
     * GET /api/announcements
     * Ambil semua pengumuman aktif, terbaru di atas.
     * Query param: ?all=true untuk admin (termasuk non-aktif)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Announcement::query();

            // Jika bukan request admin (?all=true), hanya tampilkan yang aktif
            if (!$request->boolean('all')) {
                $query->active();
            }

            // Filter by category jika ada
            if ($request->filled('category')) {
                $query->where('category', strtoupper($request->input('category')));
            }

            $announcements = $query->orderBy('created_at', 'desc')->get();

            $data = $announcements->map(function ($item) {
                return [
                    'id'             => $item->id,
                    'title'          => $item->title,
                    'content'        => $item->content,
                    'category'       => $item->category,
                    'author_name'    => $item->author_name ?? '-',
                    'author_role'    => $item->author_role ?? '-',
                    'is_active'      => $item->is_active,
                    'formatted_date' => $item->formatted_date,
                    'time_ago'       => $item->time_ago,
                    'created_at'     => $item->created_at ? $item->created_at->toISOString() : null,
                    'updated_at'     => $item->updated_at ? $item->updated_at->toISOString() : null,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Daftar pengumuman berhasil diambil',
                'total'   => $data->count(),
                'data'    => $data->values(),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil daftar pengumuman: ' . $e->getMessage(),
                'data'    => [],
            ], 200);
        }
    }

    /**
     * GET /api/announcements/{id}
     * Detail satu pengumuman.
     */
    public function show($id): JsonResponse
    {
        try {
            $item = Announcement::find($id);

            if (!$item) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pengumuman tidak ditemukan.',
                    'data'    => null,
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Detail pengumuman berhasil diambil',
                'data'    => [
                    'id'             => $item->id,
                    'title'          => $item->title,
                    'content'        => $item->content,
                    'category'       => $item->category,
                    'author_name'    => $item->author_name ?? '-',
                    'author_role'    => $item->author_role ?? '-',
                    'is_active'      => $item->is_active,
                    'formatted_date' => $item->formatted_date,
                    'time_ago'       => $item->time_ago,
                    'created_at'     => $item->created_at ? $item->created_at->toISOString() : null,
                    'updated_at'     => $item->updated_at ? $item->updated_at->toISOString() : null,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil detail pengumuman: ' . $e->getMessage(),
                'data'    => null,
            ], 200);
        }
    }

    /**
     * POST /api/announcements
     * Tambah pengumuman baru (Manajer)
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'title'       => 'required|string|max:255',
                'content'     => 'required|string',
                'category'    => 'nullable|string|in:PENTING,INFORMASI,PROMO',
                'author_name' => 'nullable|string|max:100',
                'author_role' => 'nullable|string|max:100',
                'is_active'   => 'nullable|boolean',
            ], [
                'title.required'   => 'Judul pengumuman wajib diisi!',
                'title.max'        => 'Judul pengumuman maksimal 255 karakter!',
                'content.required' => 'Isi pengumuman wajib diisi!',
                'category.in'      => 'Kategori harus salah satu dari: PENTING, INFORMASI, atau PROMO.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            $user = $request->user();

            $announcement = Announcement::create([
                'title'       => $request->input('title'),
                'content'     => $request->input('content'),
                'category'    => strtoupper($request->input('category', 'INFORMASI')),
                'author_name' => $request->input('author_name', $user->name ?? 'Admin'),
                'author_role' => $request->input('author_role', $user->role ?? 'Admin'),
                'created_by'  => $user->id ?? null,
                'is_active'   => $request->boolean('is_active', true),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Pengumuman berhasil ditambahkan!',
                'data'    => [
                    'id'             => $announcement->id,
                    'title'          => $announcement->title,
                    'content'        => $announcement->content,
                    'category'       => $announcement->category,
                    'author_name'    => $announcement->author_name,
                    'author_role'    => $announcement->author_role,
                    'is_active'      => $announcement->is_active,
                    'formatted_date' => $announcement->formatted_date,
                    'time_ago'       => $announcement->time_ago,
                    'created_at'     => $announcement->created_at->toISOString(),
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan pengumuman: ' . $e->getMessage(),
                'data'    => null,
            ], 200);
        }
    }

    /**
     * PUT /api/announcements/{id}
     * Edit pengumuman
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $announcement = Announcement::find($id);

            if (!$announcement) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pengumuman tidak ditemukan.',
                    'data'    => null,
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'title'       => 'sometimes|required|string|max:255',
                'content'     => 'sometimes|required|string',
                'category'    => 'nullable|string|in:PENTING,INFORMASI,PROMO',
                'author_name' => 'nullable|string|max:100',
                'author_role' => 'nullable|string|max:100',
                'is_active'   => 'nullable|boolean',
            ], [
                'title.required'   => 'Judul pengumuman wajib diisi!',
                'title.max'        => 'Judul pengumuman maksimal 255 karakter!',
                'content.required' => 'Isi pengumuman wajib diisi!',
                'category.in'      => 'Kategori harus salah satu dari: PENTING, INFORMASI, atau PROMO.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            $dataToUpdate = [];
            if ($request->has('title'))       $dataToUpdate['title']       = $request->input('title');
            if ($request->has('content'))     $dataToUpdate['content']     = $request->input('content');
            if ($request->has('category'))    $dataToUpdate['category']    = strtoupper($request->input('category'));
            if ($request->has('author_name')) $dataToUpdate['author_name'] = $request->input('author_name');
            if ($request->has('author_role')) $dataToUpdate['author_role'] = $request->input('author_role');
            if ($request->has('is_active'))   $dataToUpdate['is_active']   = $request->boolean('is_active');

            $announcement->update($dataToUpdate);
            $announcement->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Pengumuman berhasil diperbarui!',
                'data'    => [
                    'id'             => $announcement->id,
                    'title'          => $announcement->title,
                    'content'        => $announcement->content,
                    'category'       => $announcement->category,
                    'author_name'    => $announcement->author_name ?? '-',
                    'author_role'    => $announcement->author_role ?? '-',
                    'is_active'      => $announcement->is_active,
                    'formatted_date' => $announcement->formatted_date,
                    'time_ago'       => $announcement->time_ago,
                    'created_at'     => $announcement->created_at ? $announcement->created_at->toISOString() : null,
                    'updated_at'     => $announcement->updated_at ? $announcement->updated_at->toISOString() : null,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui pengumuman: ' . $e->getMessage(),
                'data'    => null,
            ], 200);
        }
    }

    /**
     * DELETE /api/announcements/{id}
     * Hapus pengumuman
     */
    public function destroy($id): JsonResponse
    {
        try {
            $announcement = Announcement::find($id);

            if (!$announcement) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pengumuman tidak ditemukan.',
                ], 404);
            }

            $title = $announcement->title;
            $announcement->delete();

            return response()->json([
                'success' => true,
                'message' => "Pengumuman '{$title}' berhasil dihapus!",
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus pengumuman: ' . $e->getMessage(),
            ], 200);
        }
    }
}
