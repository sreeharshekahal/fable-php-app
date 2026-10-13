<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate flat bulk update payload for /students/bulk-update.
 */
class StudentBulkUpdateRequest extends FormRequest
{
    /**
     * Updatable columns allowed on access_student.
     */
    public const ALLOWED_FIELDS = [
        'division',
        'grade_id',
        'group_id',
        'group_hindi_id',
        'group_marathi_id',
        'gender',
        'is_english_second_language',
        'disability',
        'date_of_birth',
    ];

    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'student_ids' => 'required|array|min:1|max:1000',
            'student_ids.*' => 'required|uuid|distinct:ignore_case',

            // Flat payload fields
            'division' => 'sometimes|nullable|string|max:255',
            'grade_id' => 'sometimes|nullable|uuid',
            'group_id' => 'sometimes|nullable|uuid',
            'group_hindi_id' => 'sometimes|nullable|uuid',
            'group_marathi_id' => 'sometimes|nullable|uuid',
            'gender' => 'sometimes|nullable|integer',
            'is_english_second_language' => 'sometimes|nullable|boolean',
            'disability' => 'sometimes|nullable|boolean',
            'date_of_birth' => 'sometimes|nullable|date',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $updates = $this->getUpdateAttributes();
            if (empty($updates)) {
                $validator->errors()->add(
                    'update_fields',
                    'At least one valid field to update must be provided (e.g. division).'
                );
            }
        });
    }

    /**
     * Extract sanitized attributes to update from flat payload.
     */
    public function getUpdateAttributes(): array
    {
        $updates = [];

        foreach (self::ALLOWED_FIELDS as $field) {
            if ($this->has($field) || $this->exists($field)) {
                $updates[$field] = $this->input($field);
            }
        }

        // Sanitize string fields like division: trim, convert empty string to null
        if (array_key_exists('division', $updates)) {
            $val = $updates['division'];
            $updates['division'] = ($val === null || trim((string) $val) === '') ? null : trim((string) $val);
        }

        return $updates;
    }

    public function messages(): array
    {
        return [
            'student_ids.required' => 'Provide a list of student_ids to update.',
            'student_ids.max' => 'You may not update more than 1000 students at once.',
        ];
    }
}
