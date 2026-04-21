<?php

namespace App\Http\Requests\Community;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCommunityPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'text' => ['sometimes', 'required', 'string'],
            'share' => ['sometimes', 'required', 'boolean'],
            'fk_plot_id' => ['sometimes', 'nullable', 'exists:plots,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        if ($this->exists('name')) {
            $name = $this->input('name');
            $data['name'] = is_string($name) ? trim($name) : $name;
        }

        if ($this->exists('text')) {
            $text = $this->input('text');
            $data['text'] = is_string($text) ? trim($text) : $text;
        }

        if ($data !== []) {
            $this->merge($data);
        }
    }
}
