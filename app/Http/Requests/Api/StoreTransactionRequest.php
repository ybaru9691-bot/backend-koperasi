<?php

namespace App\Http\Requests\Api;

use App\Services\PeriodLockService;
use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'member_id'        => 'required|exists:members,id',
            'payment_method'   => 'required|in:cash,tunai,bank,transfer,memorial',
            'transaction_date' => 'nullable|date',
            'amount'           => 'nullable|numeric',
            'type'             => 'nullable|string',
            'items'            => 'nullable|array',
            'items.*.amount'   => 'nullable|numeric',
            'items.*.account_code' => 'nullable|string',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $trxDate = $this->input('transaction_date') ?? now()->toDateString();
            if (\App\Services\PeriodClosingService::isDateLocked($trxDate)) {
                $validator->errors()->add('transaction_date', \App\Services\PeriodClosingService::LOCKED_MESSAGE);
            }
        });
    }

    /**
     * Custom messages for validation errors.
     */
    public function messages(): array
    {
        return [
            'member_id.required'      => 'Anggota harus dipilih.',
            'member_id.exists'        => 'Anggota yang dipilih tidak terdaftar di sistem.',
            'payment_method.required' => 'Metode pembayaran wajib diisi.',
            'payment_method.in'       => 'Metode pembayaran tidak valid.',
            'transaction_date.date'   => 'Format tanggal transaksi tidak valid.',
        ];
    }
}
