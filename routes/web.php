<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Rute Unduh APK Android (Aman dengan MIME-Type Android Package & Proteksi Path Traversal)
Route::get('/downloads/{filename}', function ($filename) {
    $cleanFilename = basename($filename);
    $filePath = public_path('downloads/' . $cleanFilename);

    if (!file_exists($filePath)) {
        return response()->json([
            'success' => false,
            'message' => "File APK '{$cleanFilename}' belum diunggah ke server.",
        ], 404);
    }

    return response()->download($filePath, $cleanFilename, [
        'Content-Type'        => 'application/vnd.android.package-archive',
        'Content-Disposition' => 'attachment; filename="' . $cleanFilename . '"',
    ]);
})->where('filename', '.*\.apk$');

/*
|--------------------------------------------------------------------------
| Admin Web Routes Group
|--------------------------------------------------------------------------
| Rute web operasional Admin Koperasi.
| Catatan: Rute Manajemen & Distribusi SHU / Deviden secara eksplisit tidak
| tersedia / tidak dapat diakses oleh Role Admin dan hanya khusus Role Manajer.
*/
Route::middleware(['auth', 'role:admin'])->prefix('admin')->group(function () {
    // Rute Operasional Admin Koperasi (Kas, Transaksi, Anggota, Pinjaman)
});

/*
|--------------------------------------------------------------------------
| Manager Web Routes Group (SHU & Deviden)
|--------------------------------------------------------------------------
| Akses eksklusif Role Manajer untuk Manajemen & Distribusi SHU Bulanan / Deviden.
*/
Route::middleware(['auth', 'role:manager'])->prefix('manager')->group(function () {
    // Rute Distribusi SHU & Deviden khusus Manajer
});
