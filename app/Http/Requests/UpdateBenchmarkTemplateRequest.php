<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBenchmarkTemplateRequest extends FormRequest
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
            'name'                               => 'sometimes|string|max:100',
            'organisation_ids'                   => 'sometimes|array',
            'organisation_ids.*'                 => 'string|exists:organisation_organisation,id',
            'language_ids'                       => 'sometimes|array',
            'language_ids.*'                     => 'string|exists:common_language,id',
            'assessment_period'                  => 'sometimes|string|in:BOY,MOY,EOY',
            'grouping_parameters'                => 'nullable|array',
            'grouping_parameters.*.name'         => 'required|string',
            'grouping_parameters.*.grade_id'     => 'required|exists:common_grade,id',
            'grouping_parameters.*.level_id'     => 'required|exists:common_level,id',
            'grouping_parameters.*.language_id'  => 'required|exists:common_language,id',
            'grouping_parameters.*.lower_bound'  => 'required|integer|min:0',
            'grouping_parameters.*.upper_bound'  => 'required|integer|min:0',
            'grouping_parameters.*.goal'         => 'required|integer|min:0',
            'grouping_parameters.*.assessment_period' => 'nullable|string|in:BOY,MOY,EOY',
            'accuracies'                         => 'nullable|array',
            'accuracies.*.grade_id'              => 'required|exists:common_grade,id',
            'accuracies.*.level_id'              => 'nullable|exists:common_level,id',
            'accuracies.*.language_id'           => 'nullable|exists:common_language,id',
            'accuracies.*.percentage'            => 'required|numeric|min:0|max:100',
            'accuracies.*.assessment_period'     => 'nullable|string|in:BOY,MOY,EOY',
            'active_organisations_by_period'                          => 'nullable|array',
            'active_organisations_by_period.BOY'                      => 'nullable|array',
            'active_organisations_by_period.BOY.*.organisation_id'    => 'nullable|string|exists:organisation_organisation,id',
            'active_organisations_by_period.BOY.*.language_id'        => 'nullable|string|exists:common_language,id',
            'active_organisations_by_period.MOY'                      => 'nullable|array',
            'active_organisations_by_period.MOY.*.organisation_id'    => 'nullable|string|exists:organisation_organisation,id',
            'active_organisations_by_period.MOY.*.language_id'        => 'nullable|string|exists:common_language,id',
            'active_organisations_by_period.EOY'                      => 'nullable|array',
            'active_organisations_by_period.EOY.*.organisation_id'    => 'nullable|string|exists:organisation_organisation,id',
            'active_organisations_by_period.EOY.*.language_id'        => 'nullable|string|exists:common_language,id',
        ];
    }
}
