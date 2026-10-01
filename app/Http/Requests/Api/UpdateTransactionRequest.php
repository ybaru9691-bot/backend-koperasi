<?php

namespace App\Http\Requests\Api;

use App\Models\Transaction;
use App\Services\PeriodLockService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTransactionRequest extends FormRequest
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
            'amount'           => 'nullable|numeric|min:0.01',
            'transaction_date' => 'nullable|date',
            'description'      => 'nullable|string',
            'payment_method'   => 'nullable|string',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $id = $this->route('id') ?? $this->route('transaction');
            if ($id) {
                $trx = Transaction::find($id);
                if ($trx && \App\Services\PeriodClosingService::isDateLocked($trx->transaction_date)) {
                    $validator->errors()->add('transaction_date', \App\Services\PeriodClosingService::LOCKED_MESSAGE);
                }
            }
            $newDate = $this->input('transaction_date');
            if ($newDate && \App\Services\PeriodClosingService::isDateLocked($newDate)) {
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
            'amount.min'            => 'Nominal transaksi harus lebih dari 0.',
            'transaction_date.date' => 'Format tanggal transaksi tidak valid.',
        ];
    }
}
