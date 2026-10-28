<?php

namespace App\Http\Requests\Passport;

use Illuminate\Foundation\Http\FormRequest;

class CommSendEmailVerify extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:strict'],
            'isforget' => ['sometimes', 'nullable', 'boolean'],
            'recaptcha_data' => ['sometimes', 'nullable', 'string', 'max:8192'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => __('Email can not be empty'),
            'email.string' => __('Email format is incorrect'),
            'email.email' => __('Email format is incorrect'),
        ];
    }
}
