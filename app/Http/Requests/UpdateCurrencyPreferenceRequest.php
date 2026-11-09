<?php

namespace App\Http\Requests;

use App\Support\Currency;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCurrencyPreferenceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', Rule::in(Currency::supported())],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['currency' => $this->route('currency')]);
    }
}
