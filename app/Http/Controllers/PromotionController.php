<?php

namespace App\Http\Controllers;

use App\Models\Organisation;
use App\Models\Grade;
use App\Models\Level;
use App\Models\Student;
use App\Models\OrganisationGroup;
use App\Models\Assessment;
use App\Models\StudentPromotionHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Http\Request;

class PromotionController extends Controller
{
    /**
     * 
     * Validate Student Promotion
     * Validates if a target grade exists for promotion and returns eligibility status.
     *  
     * Payload: `{"organisation": id, "grade_level": level}`.
     * Python Source: `fable/organisation/views.py:validate_promotion`
     */
    public function validatePromotion(Request $request)
    {
        $organisationId = $request->input('organisation');
        $fromGradeLevel = $request->input('grade_level');

        if (!$organisationId) {
            return response()->json(['error' => 'organisation is required!'], 422);
        }
        if (!$fromGradeLevel) {
            return response()->json(['error' => 'grade_level is required!'], 422);
        }

        $organisation = Organisation::find($organisationId);
        if (!$organisation) {
            return response()->json(['error' => 'organisation not found'], 404);
        }

        $data = [];
        $nextLevel = (int)$fromGradeLevel + 1;
        $gradesInOrg = $organisation->grades();

        if ($gradesInOrg->where('level', $nextLevel)->exists()) {
            $grade = $gradesInOrg->where('level', $nextLevel)->first();
            $data['from_grade'] = "Grade {$fromGradeLevel}";
            $data['to_grade'] = "Grade {$grade->level}";
            $data['to_grade_exists'] = true;
            $data['can_be_promoted'] = true;
        } elseif ((int)$fromGradeLevel == 5) {
            $grade = Grade::where('level', 6)->first();
            $data['from_grade'] = "Grade {$fromGradeLevel}";
            $data['to_grade'] = $grade ? $grade->title : "Grade 6";
            $data['to_grade_exists'] = true;
            $data['can_be_promoted'] = true;
        } elseif ((int)$fromGradeLevel > 5) {
            $grade = Grade::where('level', 6)->first();
            $data['from_grade'] = $grade ? $grade->title : "Grade 6";
            $data['to_grade'] = $grade ? $grade->title : "Grade 6";
            $data['to_grade_exists'] = true;
            $data['can_be_promoted'] = false;
        } else {
            $grade = Grade::where('level', $nextLevel)->first();
            $data['from_grade'] = "Grade {$fromGradeLevel}";
            $data['to_grade'] = "Grade " . ($grade ? $grade->level : $nextLevel);
            $data['to_grade_exists'] = false;
            $data['can_be_promoted'] = true;
        }

        return response()->json($data, 200);
    }

    /**
     * 
     * Bulk Promote Students
     * Moves students to the next grade level and resets their group to the baseline.
     * 
     * Payload: `{"organisation": id, "grade_level": level, "students": [ids]}`.
     * Python Source: `fable/organisation/views.py:bulk_promote_students`
     */
    public function bulkPromoteStudents(Request $request)
    {
        $organisationId = $request->input('organisation');
        $fromGradeLevel = $request->input('grade_level');
        $studentIds = $request->input('students');
        $students = is_string($studentIds) ? json_decode($studentIds, true) : $studentIds;

        if (!$organisationId) {
            return response()->json(['error' => 'key organisation is required!'], 422);
        }
        if ($fromGradeLevel === null) {
            return response()->json(['error' => 'key grade_level is required!'], 422);
        }

        if (!$students || !is_array($students)) {
            return response()->json(['details' => 'invalid data format'], 400);
        }

        $organisation = Organisation::find($organisationId);
        if (!$organisation) {
            return response()->json(['error' => 'organisation not found'], 404);
        }

        // --- Grade Resolution (matching Python logic) ---
        $gradesInOrg = $organisation->grades()->get();
        $nextLevel = (int)$fromGradeLevel + 1;

        if ($gradesInOrg->where('level', $nextLevel)->isNotEmpty()) {
            // Next grade already exists in org
            $newGrade = $gradesInOrg->where('level', $nextLevel)->first();
        } elseif ((int)$fromGradeLevel > 5) {
            // Grade > 5: cap at Grade 6 (Completed/Alumni)
            $newGrade = Grade::where('level', 6)->first();
            if (!$newGrade) {
                return response()->json(['error' => 'Grade level 6 not found in system'], 404);
            }
        } else {
            // Grade doesn't exist in org yet — fetch from global and auto-add to org
            $newGrade = Grade::where('level', $nextLevel)->first();
            if (!$newGrade) {
                return response()->json(['error' => "Grade level {$nextLevel} not found in system"], 404);
            }
            // Python: organisation.grades.add(grade)
            $organisation->grades()->syncWithoutDetaching([$newGrade->id]);
            // create groups for new grade
            $organisation->createGroupsForGrade($newGrade);
        }

        // Find the baseline group (rank=1/Benchmarking) for the target grade in this org
        $level1 = Level::where('rank', 1)->first();
        if (!$level1) {
            return response()->json(['error' => 'Benchmarking level not found'], 404);
        }

        $newGroup = OrganisationGroup::where('organisation_id', $organisationId)
            ->where('grade_id', $newGrade->id)
            ->where('level_id', $level1->id)
            ->first();

        if (!$newGroup) {
            return response()->json(['error' => 'Target group not found in organisation'], 404);
        }

        // --- Student Filtering (matching Python: filter by org + grade__level + id__in) ---
        $studentsToPromote = Student::where('organisation_id', $organisationId)
            ->whereHas('grade', function ($q) use ($fromGradeLevel) {
                $q->where('level', $fromGradeLevel);
            })
            ->whereIn('id', $students)
            ->get();

        // --- Promote each student within a transaction (matching Python: transaction.atomic()) ---
        foreach ($studentsToPromote as $student) {
            DB::transaction(function () use ($student, $newGrade, $newGroup) {
                // Python: StudentPromotionHistory.objects.get_or_create(student=student, grade=grade)
                StudentPromotionHistory::firstOrCreate(
                    ['student_id' => $student->id, 'grade_id' => $newGrade->id]
                );

                // Update student grade and groups
                $updateData = ['grade_id' => $newGrade->id];

                // Python sets student.group = group unconditionally via student.save()
                // PHP mirrors the conditional logic for multi-language groups
                if ($student->group_id) {
                    $updateData['group_id'] = $newGroup->id;
                }
                if ($student->group_hindi_id) {
                    $updateData['group_hindi_id'] = $newGroup->id;
                }
                if ($student->group_marathi_id) {
                    $updateData['group_marathi_id'] = $newGroup->id;
                }

                // --- Group History Update (Matching side effects of Python signal) ---
                foreach (['group_id', 'group_hindi_id', 'group_marathi_id'] as $groupField) {
                    if ($student->$groupField) {
                        // Deactivate old active group history for this student and specific group
                        DB::table('organisation_studentgrouphistory')
                            ->where('student_id', $student->id)
                            ->where('group_id', $student->$groupField)
                            ->where('is_active', true)
                            ->update([
                                'is_active' => false,
                                'removed_date' => now(),
                                'updated' => now()
                            ]);

                        // Create new active group history
                        DB::table('organisation_studentgrouphistory')->insert([
                            'student_id' => $student->id,
                            'group_id' => $newGroup->id,
                            'is_active' => true,
                            'joined_date' => now(),
                            'created' => now(),
                            'updated' => now()
                        ]);
                    }
                }

                // Python signal behavior: The promotion status is now handled dynamically 
                // via the Student model's is_promoted accessor and StudentPromotionHistory.

                DB::table('access_student')
                    ->where('id', $student->id)
                    ->update($updateData);
            });
        }

        // --- Response: Return serialized student data (matching Python: StudentSerializer(students, many=True).data) ---
        // Re-fetch to get updated state
        $promotedStudents = Student::with(['grade', 'group', 'group_hindi', 'group_marathi', 'languages'])
            ->whereIn('id', $studentsToPromote->pluck('id'))
            ->get();

        $responseData = $promotedStudents->map(function ($student) {
            $user = DB::table('auth_user')->where('id', $student->user_id)->first();
            $org = DB::table('organisation_organisation')->where('id', $student->organisation_id)->first();

            $firstName = $user->first_name ?? '';
            $lastName = $user->last_name ?? '';
            try {
                if (!empty($firstName) && str_contains($firstName, 'eyJpdiI6')) {
                    $firstName = Crypt::decryptString($firstName);
                }
                if (!empty($lastName) && str_contains($lastName, 'eyJpdiI6')) {
                    $lastName = Crypt::decryptString($lastName);
                }
            } catch (\Exception $e) {
                // Keep as-is
            }

            $groupDetail = null;
            if ($student->group) {
                $groupDetail = [
                    'id' => $student->group->id,
                    'title' => $student->group->title,
                    'description' => $student->group->description ?? null,
                    'organisation' => $student->group->organisation_id,
                    'grade' => $student->group->grade_id,
                    'level' => $student->group->level_id,
                ];
            }

            return [
                'id' => $student->id,
                'group_detail' => $groupDetail,
                'user_detail' => [
                    'username' => $user->username ?? '',
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'role_id' => $student->id,
                    'full_name' => trim($firstName . ' ' . $lastName),
                    'email' => $user->email ?? '',
                    'role' => 'Student',
                    'organisation' => [
                        'id' => $student->organisation_id,
                        'title' => $org->title ?? '',
                    ],
                    'groups' => $student->group->title ?? null,
                ],
                'organisation_title' => $org->title ?? '',
                'grade_title' => $student->grade->title ?? '',
                'role' => 'Student',
                'languages' => $student->languages->pluck('name')->values(),
                'division' => $student->division ?? null,
                'date_of_birth' => $student->date_of_birth,
                'gender' => $student->gender,
                'is_english_second_language' => $student->is_english_second_language,
                'disability' => $student->disability,
                'picture' => $student->picture ?: null,
                'user' => $student->user_id,
                'organisation' => $student->organisation_id,
                'grade' => $student->grade_id,
                'group' => $student->group_id,
                'group_hindi' => $student->group_hindi_id,
                'group_marathi' => $student->group_marathi_id,
            ];
        });

        return response()->json($responseData, 200);
    }

    /**
     * 
     * Implement Undo Student Bulk Promotion
     * Reverts promotion for selected students to their previous grade based on level calculation.
     * 
     * Payload: `{"students": [id1, id2, ...]}`.
     * Python Source: `fable/organisation/views.py:bulk_undo_promote_students` (Modified to exclude history flow)
     */
    public function bulkUndoPromoteStudents(Request $request)
    {
        $studentIds = $request->input('students');

        if (!is_array($studentIds)) {
            return response()->json(['details' => 'invalid data format'], 400);
        }

        // Fetch students (matching Python: Student.objects.filter(id__in=student_ids))
        // and filter for those with history (matching Python: .filter(student_promotion_history__isnull=False))
        // and exclude those with assessments in current grade (matching Python: .exclude(assessments__grade=F('grade')))
        $students = Student::whereIn('id', $studentIds)
            ->whereHas('promotionHistory')
            ->whereDoesntHave('assessments', function ($q) {
                $q->whereColumn('grade_id', 'access_student.grade_id');
            })
            ->distinct()
            ->get();

        if ($students->count() != count($studentIds)) {
            return response()->json([
                "error" => "few students cannot be undo promoted from students list!"
            ], 422);
        }

        foreach ($students as $student) {
            DB::transaction(function () use ($student) {
                $organisation = Organisation::find($student->organisation_id);
                $gradesInOrg = $organisation->grades()->orderBy('level', 'asc')->get();

                // Python logic: find the highest grade in org with level < student.grade.level
                $previousGrade = $gradesInOrg->first();
                $currentLevel = (int)$student->grade->level;

                foreach ($gradesInOrg as $g) {
                    if ($g->level < $currentLevel) {
                        $previousGrade = $g;
                    } else {
                        break;
                    }
                }

                // Delete the last promotion history record (matching Python: promotion_history.delete())
                $promotionHistory = StudentPromotionHistory::where('student_id', $student->id)
                    ->orderBy('created', 'desc')
                    ->first();

                if ($promotionHistory) {
                    $promotionHistory->delete();
                } else {
                    // This shouldn't happen due to whereHas('promotionHistory') above, but for safety:
                    throw new \Exception("promotion history does not exists for student - {$student->id}");
                }

                if ($previousGrade) {
                    $updateData = [
                        'grade_id' => $previousGrade->id,
                    ];
                    
                    // --- Group History Update for Undo (Matching side effects of Python signal) ---
                    $baselineGroup = OrganisationGroup::where('organisation_id', $student->organisation_id)
                        ->where('grade_id', $previousGrade->id)
                        ->where('title', 'Baseline')
                        ->first();
                        
                    if ($baselineGroup) {
                        foreach (['group_id', 'group_hindi_id', 'group_marathi_id'] as $groupField) {
                            if ($student->$groupField) {
                                DB::table('organisation_studentgrouphistory')
                                    ->where('student_id', $student->id)
                                    ->where('group_id', $student->$groupField)
                                    ->where('is_active', true)
                                    ->update([
                                        'is_active' => false,
                                        'removed_date' => now(),
                                        'updated' => now()
                                    ]);

                                DB::table('organisation_studentgrouphistory')->insert([
                                    'student_id' => $student->id,
                                    'group_id' => $baselineGroup->id,
                                    'is_active' => true,
                                    'joined_date' => now(),
                                    'created' => now(),
                                    'updated' => now()
                                ]);
                            }
                        }
                    }

                    // Revert to baseline group of previous grade
                    $level1 = Level::where('rank', 1)->first();
                    $prevGroup = OrganisationGroup::where('organisation_id', $student->organisation_id)
                        ->where('grade_id', $previousGrade->id)
                        ->where('level_id', $level1->id)
                        ->first();

                    if ($prevGroup) {
                        if ($student->group_id) $updateData['group_id'] = $prevGroup->id;
                        if ($student->group_hindi_id) $updateData['group_hindi_id'] = $prevGroup->id;
                        if ($student->group_marathi_id) $updateData['group_marathi_id'] = $prevGroup->id;
                    }

                    DB::table('access_student')
                        ->where('id', $student->id)
                        ->update($updateData);
                }
            });
        }

        // Return serialized student data (matching Python: StudentSerializer(students, many=True).data)
        // Re-fetch to get updated state
        $updatedStudents = Student::with(['grade', 'group', 'group_hindi', 'group_marathi', 'languages'])
            ->whereIn('id', $students->pluck('id'))
            ->get();

        // Use the same serialization logic as bulkPromoteStudents (in a real app, this would be a shared helper or resource)
        $responseData = $updatedStudents->map(function ($student) {
            $user = DB::table('auth_user')->where('id', $student->user_id)->first();
            $org = DB::table('organisation_organisation')->where('id', $student->organisation_id)->first();

            $firstName = $user->first_name ?? '';
            $lastName = $user->last_name ?? '';
            try {
                if (!empty($firstName) && str_contains($firstName, 'eyJpdiI6')) {
                    $firstName = Crypt::decryptString($firstName);
                }
                if (!empty($lastName) && str_contains($lastName, 'eyJpdiI6')) {
                    $lastName = Crypt::decryptString($lastName);
                }
            } catch (\Exception $e) {}

            return [
                'id' => $student->id,
                'user_detail' => [
                    'username' => $user->username ?? '',
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'full_name' => trim($firstName . ' ' . $lastName),
                    'email' => $user->email ?? '',
                    'role' => 'Student',
                    'organisation' => ['id' => $student->organisation_id, 'title' => $org->title ?? ''],
                ],
                'organisation_title' => $org->title ?? '',
                'grade_title' => $student->grade->title ?? '',
                'role' => 'Student',
                'languages' => $student->languages->pluck('name')->values(),
                'division' => $student->division ?? null,
                'date_of_birth' => $student->date_of_birth,
                'gender' => $student->gender,
                'is_english_second_language' => $student->is_english_second_language,
                'disability' => $student->disability,
                'user' => $student->user_id,
                'organisation' => $student->organisation_id,
                'grade' => $student->grade_id,
                'group' => $student->group_id,
                'group_hindi' => $student->group_hindi_id,
                'group_marathi' => $student->group_marathi_id,
            ];
        });

        return response()->json($responseData, 200);
    }
}
