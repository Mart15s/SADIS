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
            'analysisTypes.required' => 'Select at least one analysis type.',
            'analysisTypes.array' => 'Analysis types must be provided as an array.',
            'analysisTypes.min' => 'Select at least one analysis type.',
            'analysisTypes.*.required' => 'Analysis type values cannot be empty.',
            'analysisTypes.*.in' => 'The selected analysis type is not supported.',
            'analysisType.in' => 'The selected analysis type is not supported.',
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
                    $validator->errors()->add('analysisTypes', 'Duplicate analysis types are not allowed.');
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
