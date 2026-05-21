<?php

namespace App\Http\Requests\Plot;

use App\Enums\AnalysisType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $analysisTypes = $this->input('analysisTypes');

        if (is_string($analysisTypes) && trim($analysisTypes) !== '') {
            $analysisTypes = [trim($analysisTypes)];
        }

        if ($analysisTypes === null && $this->filled('analysisType')) {
            $analysisTypes = [$this->input('analysisType')];
        }

        if ($analysisTypes === null && $this->isMethod('GET')) {
            $analysisTypes = AnalysisType::values();
        }

        if ($analysisTypes !== null) {
            $this->merge([
                'analysisTypes' => array_values((array) $analysisTypes),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'analysisType' => ['sometimes', 'string', Rule::in(AnalysisType::values())],
            'analysisTypes' => ['required', 'array', 'min:1'],
            'analysisTypes.*' => ['required', 'string', Rule::in(AnalysisType::values())],
        ];
    }

    public function messages(): array
    {
        return [
            'analysisTypes.required' => 'Pasirinkite bent vieną analizės tipą.',
            'analysisTypes.array' => 'Analizės tipai turi būti pateikti kaip sąrašas.',
            'analysisTypes.min' => 'Pasirinkite bent vieną analizės tipą.',
            'analysisTypes.*.required' => 'Analizės tipo reikšmė negali būti tuščia.',
            'analysisTypes.*.in' => 'Pasirinktas analizės tipas nepalaikomas.',
            'analysisType.in' => 'Pasirinktas analizės tipas nepalaikomas.',
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                $analysisTypes = $this->input('analysisTypes', []);

                if (! is_array($analysisTypes) || $analysisTypes === []) {
                    return;
                }

                if (count($analysisTypes) !== count(array_unique($analysisTypes))) {
                    $validator->errors()->add('analysisTypes', 'Pasikartojantys analizės tipai neleidžiami.');
                }
            },
        ];
    }

    /**
     * @return array<int, string>
     */
    public function analysisTypes(): array
    {
        return array_values(
            array_unique($this->validated('analysisTypes', []))
        );
    }
}
