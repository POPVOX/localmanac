<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AskRequest extends FormRequest
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
            'question' => ['required', 'string', 'max:800'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'city_slug' => ['nullable', 'string', 'exists:cities,slug'],
        ];
    }

    public function messages(): array
    {
        return [
            'question.required' => __('Please provide a question.'),
            'question.max' => __('Questions must be 800 characters or less.'),
        ];
    }
}
