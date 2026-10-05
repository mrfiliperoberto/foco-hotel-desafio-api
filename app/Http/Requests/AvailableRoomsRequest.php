<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AvailableRoomsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'hotel_id' => ['required', 'integer', 'exists:hotels,id'],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => [
                'required',
                'date_format:Y-m-d',
                'after:check_in',
            ],
        ];
    }
}
