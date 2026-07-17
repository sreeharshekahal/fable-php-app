<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ActivateBenchmarkTemplatePeriodRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true; // Update with proper authorization logic if needed
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'assessment_period' => 'nullable|string|in:BOY,MOY,EOY',
            'organisation_ids'  => 'nullable|array',
            'organisation_ids.*' => 'string|exists:organisation_organisation,id',
            'level_id'          => 'nullable|exists:common_level,id',
            'grade_id'          => 'nullable|exists:common_grade,id',
            'language_id'       => 'nullable|exists:common_language,id',
        ];
    }
}
