<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRequest extends FormRequest
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
        $memberId = $this->route('id') ?? $this->route('member') ?? $this->id;

        return [
            'nik' => [
                'sometimes',
                'required',
                'string',
                'max:20',
                Rule::unique('members', 'nik')->ignore($memberId),
            ],
            'no_ktp' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('members', 'nik')->ignore($memberId),
            ],
            'ktp' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('members', 'nik')->ignore($memberId),
            ],
            'member_number' => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('members', 'member_number')->ignore($memberId),
            ],
            'name' => 'nullable|string|max:255',
            'email' => [
                'nullable',
                'email',
                Rule::unique('members', 'email')->ignore($memberId),
            ],
            'phone' => 'nullable|string|max:20',
            'no_hp' => 'nullable|string|max:20',
            'buku_putih_no' => 'nullable|string|max:50',
            'no_buku_putih' => 'nullable|string|max:50',
            'rekening_buku_putih' => 'nullable|string|max:50',
            'nomor_buku_putih' => 'nullable|string|max:50',
            'has_buku_biru' => 'nullable|boolean',
            'has_buku_putih' => 'nullable|boolean',
            'handphone' => 'nullable|string|max:20',
            'status' => 'nullable|string|in:active,inactive,suspended',
        ];
    }
}
