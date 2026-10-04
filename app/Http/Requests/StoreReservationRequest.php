<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $moneyRules = [
            'required',
            'string',
            'regex:/^\d{1,10}(?:\.\d{1,2})?$/',
        ];

        return [
            'external_id' => ['prohibited'],
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => [
                'required',
                'date_format:Y-m-d',
                'after:check_in',
            ],
            'total' => $moneyRules,

            'guests' => ['required', 'array', 'min:1', 'max:50'],
            'guests.*' => ['required', 'array:first_name,last_name,phone'],
            'guests.*.first_name' => ['required', 'string', 'max:255'],
            'guests.*.last_name' => ['required', 'string', 'max:255'],
            'guests.*.phone' => ['required', 'string', 'max:255'],

            'dailies' => ['required', 'array', 'min:1', 'max:365'],
            'dailies.*' => ['required', 'array:date,value'],
            'dailies.*.date' => [
                'required',
                'date_format:Y-m-d',
                'distinct',
                'after_or_equal:check_in',
                'before:check_out',
            ],
            'dailies.*.value' => $moneyRules,

            'payments' => ['sometimes', 'array', 'max:100'],
            'payments.*' => ['required', 'array:method,value'],
            'payments.*.method' => ['required', 'string', 'max:255'],
            'payments.*.value' => $moneyRules,
        ];
    }
}
