<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

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
