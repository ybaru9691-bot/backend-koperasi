<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Identitas Anggota (Validasi ke tabel members)
            'member_number'       => 'nullable|string|max:30|unique:members,member_number',
            'member_no'           => 'nullable|string|max:30|unique:members,member_number',
            'no_register'         => 'nullable|string|max:30|unique:members,member_number',
            'nik'                 => 'required|string|size:16|unique:members,nik',
            'name'                => 'required|string|max:255',
            'email'               => 'nullable|email|unique:members,email',
            'password'            => 'nullable|string|min:6',
            'pin'                 => 'nullable|string|digits:6',
            'place_of_birth'      => 'nullable|string|max:255',
            'birth_place'         => 'nullable|string|max:255',
            'tempat_lahir'        => 'nullable|string|max:255',
            'date_of_birth'       => 'nullable|date',
            'birth_date'          => 'nullable|date',
            'tanggal_lahir'       => 'nullable|date',
            'gender'              => 'nullable|string|max:20',
            'jenis_kelamin'       => 'nullable|string|max:20',
            'phone'               => 'nullable|string|max:20',
            'no_hp'               => 'nullable|string|max:20',
            'occupation'          => 'nullable|string|max:255',
            'pekerjaan'           => 'nullable|string|max:255',
            'job'                 => 'nullable|string|max:255',
            'education'           => 'nullable|string|max:255',
            'pendidikan'          => 'nullable|string|max:255',
            'family_status'       => 'nullable|string|max:255',
            'status_keluarga'     => 'nullable|string|max:255',
            'church_sector'       => 'nullable|string|max:255',
            'sektor_gereja'       => 'nullable|string|max:255',
            'church_unit'         => 'nullable|string|max:255',
            'address'             => 'nullable|string',
            'alamat'              => 'nullable|string',

            // Data Ahli Waris
            'heir_name'           => 'nullable|string|max:255',
            'nama_ahli_waris'     => 'nullable|string|max:255',
            'heir_relationship'   => 'nullable|string|max:255',
            'hubungan_ahli_waris' => 'nullable|string|max:255',
            'heir_place_of_birth' => 'nullable|string|max:255',
            'heir_date_of_birth'  => 'nullable|date',
            'heir_address'        => 'nullable|string',
            'alamat_ahli_waris'   => 'nullable|string',

            // Rincian Setoran Awal / Keuangan
            'registration_fee'          => 'nullable|numeric|min:0',
            'uang_pangkal'              => 'nullable|numeric|min:0',
            'principal_savings'         => 'nullable|numeric|min:0',
            'simpanan_pokok'            => 'nullable|numeric|min:0',
            'initial_principal_savings' => 'nullable|numeric|min:0',
            'social_fund'               => 'nullable|numeric|min:0',
            'grief_fund'                => 'nullable|numeric|min:0',
            'dana_duka'                 => 'nullable|numeric|min:0',
            'mandatory_savings'         => 'nullable|numeric|min:0',
            'simpanan_wajib'            => 'nullable|numeric|min:0',
            'initial_mandatory_savings' => 'nullable|numeric|min:0',
            'voluntary_savings'         => 'nullable|numeric|min:0',
            'simpanan_sukarela'         => 'nullable|numeric|min:0',
            'initial_voluntary_savings' => 'nullable|numeric|min:0',
            'daily_savings'             => 'nullable|numeric|min:0',
            'simpanan_harian'           => 'nullable|numeric|min:0',
            'initial_daily_savings'     => 'nullable|numeric|min:0',
            'tabungan_harian'           => 'nullable|numeric|min:0',
            'buku_putih_no'             => 'nullable|string|max:50',
            'total_pembayaran'          => 'nullable|numeric|min:0',
            'total_deposit'             => 'nullable|numeric|min:0',
            'total_payment'             => 'nullable|numeric|min:0',
            'has_buku_biru'             => 'required|boolean',
            'has_buku_putih'            => 'required|boolean',
        ];
    }

    /**
     * Custom Validation Hook: Memastikan Total Pembayaran Awal conditional sesuai Produk Buku
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $hasBukuBiru  = filter_var($this->has_buku_biru, FILTER_VALIDATE_BOOLEAN);
            $hasBukuPutih = filter_var($this->has_buku_putih, FILTER_VALIDATE_BOOLEAN);

            $sp = (float) ($this->initial_principal_savings ?? $this->principal_savings ?? $this->simpanan_pokok ?? 200000);
            $sw = (float) ($this->initial_mandatory_savings ?? $this->mandatory_savings ?? $this->simpanan_wajib ?? 20000);
            $ss = (float) ($this->initial_voluntary_savings ?? $this->voluntary_savings ?? $this->simpanan_sukarela ?? 10000);
            $daily = (float) ($this->initial_daily_savings ?? $this->daily_savings ?? $this->simpanan_harian ?? $this->tabungan_harian ?? 50000);
            $regFee = (float) ($this->registration_fee ?? $this->uang_pangkal ?? 20000);
            $duka   = (float) ($this->grief_fund ?? $this->dana_duka ?? $this->social_fund ?? 20000);

            if ($hasBukuBiru && $hasBukuPutih) {
                if ($sp < 200000) {
                    $validator->errors()->add('principal_savings', 'Simpanan pokok minimal Rp 200.000 jika memiliki Buku Biru.');
                }
                if ($sw < 20000) {
                    $validator->errors()->add('mandatory_savings', 'Simpanan wajib minimal Rp 20.000 jika memiliki Buku Biru.');
                }
                if ($ss < 10000) {
                    $validator->errors()->add('voluntary_savings', 'Simpanan sukarela minimal Rp 10.000 jika memiliki Buku Biru.');
                }
                if ($daily < 50000) {
                    $validator->errors()->add('daily_savings', 'Setoran awal Buku Putih (Simpanan Harian) minimal Rp 50.000.');
                }
                $calculatedTotal = 40000 + $sp + 40000 + $sw + $ss + $daily;
                $inputTotal = (float) ($this->total_pembayaran ?? $this->total_deposit ?? $this->total_payment ?? $calculatedTotal);
                if ($inputTotal < $calculatedTotal) {
                    $validator->errors()->add('total_pembayaran', 'Total setoran awal untuk Kedua Buku (Biru + Putih) minimal Rp ' . number_format($calculatedTotal, 0, ',', '.') . '.');
                }
            } elseif ($hasBukuBiru) {
                if ($sp < 200000) {
                    $validator->errors()->add('principal_savings', 'Simpanan pokok minimal Rp 200.000 jika memiliki Buku Biru.');
                }
                if ($sw < 20000) {
                    $validator->errors()->add('mandatory_savings', 'Simpanan wajib minimal Rp 20.000 jika memiliki Buku Biru.');
                }
                if ($ss < 10000) {
                    $validator->errors()->add('voluntary_savings', 'Simpanan sukarela minimal Rp 10.000 jika memiliki Buku Biru.');
                }
                $calculatedTotal = $regFee + $sp + $duka + $sw + $ss;
                $inputTotal = (float) ($this->total_pembayaran ?? $this->total_deposit ?? $this->total_payment ?? $calculatedTotal);
                if ($inputTotal < $calculatedTotal) {
                    $validator->errors()->add('total_pembayaran', 'Total pembayaran awal minimal Rp ' . number_format($calculatedTotal, 0, ',', '.') . ' untuk Buku Biru.');
                }
            } elseif ($hasBukuPutih) {
                if ($daily < 50000) {
                    $validator->errors()->add('daily_savings', 'Setoran awal Buku Putih (Simpanan Harian) minimal Rp 50.000.');
                }
                $calculatedTotal = $regFee + $duka + $daily;
                $inputTotal = (float) ($this->total_pembayaran ?? $this->total_deposit ?? $this->total_payment ?? $calculatedTotal);
                if ($inputTotal < $calculatedTotal) {
                    $validator->errors()->add('total_pembayaran', 'Total pembayaran awal minimal Rp ' . number_format($calculatedTotal, 0, ',', '.') . ' untuk Buku Putih.');
                }
            }
        });
    }

    /**
     * Handle a failed validation attempt for API request.
     * Mengembalikan response JSON 422 Unprocessable Entity yang rapi.
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'status'  => 'error',
            'message' => 'Validasi input gagal',
            'errors'  => $validator->errors(),
        ], 422));
    }
}