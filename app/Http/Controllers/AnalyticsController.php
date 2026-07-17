<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use App\Models\Student;
use App\Models\Grade;
use App\Models\Language;
use App\Models\GroupingParameter;
use App\Models\Assessment;
use App\Models\BenchmarkTemplate;
use App\Models\Organisation;
use App\Models\Level;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    /**
     * Word Trend Analysis
     * Analyzes student assessment trends with optional date filtering
     * Matches Python Django implementation exactly
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function tryDecrypt(?string $value): ?string
    {
        if ($value === null || $value === '')
            return $value;

        if (!str_contains($value, 'eyJpdiI6')) {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            try {
                // Try non-string decryption in case it was encrypted with encrypt()
                return Crypt::decrypt($value);
            } catch (\Exception $e2) {
                return $value;
            }
        }
    }

    public function wordTrendAnalysis(Request $request)
    {
        // ============================================
        // 1. EXTRACT QUERY PARAMETERS
        // ============================================
        $student_id = $this->sanitizeUuid($request->input('student', null));
        $language_name = $request->input('language__name', 'English');
        $from_date = $request->input('from', null);
        $to_date = $request->input('to', null);
        $type = $request->input('type', 0);
        $grade_id = $request->input('grade', null);

        // ============================================
        // 2. VALIDATE & PARSE DATE RANGE
        // ============================================
        $flag = false;
        if ($from_date !== null && $to_date !== null) {
            $flag = true;
            try {
                // Date format: dd-mm-yyyy
                $from_date = Carbon::createFromFormat('d-m-Y H:i:s', $from_date . ' 00:00:00');
                $to_date = Carbon::createFromFormat('d-m-Y H:i:s', $to_date . ' 23:59:59');
            } catch (\Exception $e) {
                throw ValidationException::withMessages([
                    'date' => ['error in the sent query parameters']
                ]);
            }
        } elseif (($from_date !== null && $to_date === null) || ($from_date === null && $to_date !== null)) {
            throw ValidationException::withMessages([
                'date' => ['error in the sent query parameters']
            ]);
        }

        // ============================================
        // 3. VALIDATE STUDENT & GRADE
        // ============================================
        if ($student_id === null || $student_id === '') {
            throw ValidationException::withMessages([
                'student' => ['Improper Student ID']
            ]);
        }

        // Load student with relationships
        $student = Student::with(['user', 'group.level', 'group_hindi.level', 'group_marathi.level'])
            ->find($student_id);

        if (!$student) {
            return response()->json(['error' => 'Student not found'], 404);
        }

        // Load grade (Support both UUID and numeric level)
        $grade_id_sanitized = $this->sanitizeUuid($grade_id);
        $gradeQuery = Grade::query();
        if ($grade_id_sanitized) {
            $gradeQuery->where('id', $grade_id_sanitized);
        }
        if (is_numeric($grade_id)) {
            $gradeQuery->orWhere('level', (int)$grade_id);
        }
        $grade = $gradeQuery->first();

        if (!$grade) {
            return response()->json(['error' => 'Grade not found'], 404);
        }
        $grade_id = $grade->id; // Use the UUID for subsequent queries

        // ============================================
        // 4. INITIALIZE RESPONSE DATA
        // ============================================
        $no_of_assessments = 0;
        $accuracy = null;
        $stats_exists = false;
        // Decrypt names
        $firstName = $this->tryDecrypt($student->user->first_name);
        $lastName  = $this->tryDecrypt($student->user->last_name);
        $data = [
            'student_id' => $student->id,
            'student_name' => trim($firstName . ' ' . $lastName),
            'assessment_type' => (int) $type,
            'grade' => (int) $grade->level,
            'accuracy' => $accuracy,
            'no_of_assessments' => $no_of_assessments,
            'stats_exists' => false,
            'stats' => [],
        ];

        // Get language
        $language = Language::where('name', $language_name)->first();
        if (!$language) {
            return response()->json(['error' => 'Language not found'], 404);
        }

        // ============================================
        // 5. BUILD BASE QUERY (Filtered by grade/language/student/type)
        // ============================================
        $baseQuery = Assessment::with(['passage', 'checklists', 'errorWords', 'group'])
            ->where('student_id', $student_id)
            ->where('type', $type)
            ->where('grade_id', $grade_id)
            ->whereHas('passage', function ($query) use ($language) {
                $query->where('language_id', $language->id);
            });

        // ============================================
        // 6. APPLY DATE FILTER IF PROVIDED
        // ============================================
        if ($flag === true) {
            $baseQuery->where('created', '>=', $from_date)
                ->where('created', '<', $to_date);
        }

        // ============================================
        // 7. GET ASSESSMENT COUNT (Total count matching filters)
        // ============================================
        $no_of_assessments = (clone $baseQuery)->count();

        // ============================================
        // 8. GET TOP 5 RECENT ASSESSMENTS
        // ============================================
        $topAssessments = (clone $baseQuery)
            ->orderBy('created', 'desc')
            ->limit(5)
            ->get();

        // Format stats - Python only returns 4 fields
        $stats = $topAssessments->map(function ($assessment) use ($student, $language, $grade_id) {
            $score = (float)(($assessment->last_word_index + 1) - $assessment->no_error_words);

            // Resolve the level_id for this specific assessment.
            // Fall back to student's current group level_id if the assessment has no group or group level.
            $assessmentLevelId = null;
            if ($assessment->group && $assessment->group->level_id) {
                $assessmentLevelId = $assessment->group->level_id;
            } else {
                $langName = strtolower($language->name);
                $studentGroup = null;
                if ($langName === 'hindi') {
                    $studentGroup = $student->group_hindi;
                } elseif ($langName === 'marathi') {
                    $studentGroup = $student->group_marathi;
                } else {
                    $studentGroup = $student->group;
                }
                $assessmentLevelId = $studentGroup ? $studentGroup->level_id : null;
            }

            $dynamicGoal = 0;
            if ($assessment->benchmark_goal > 0) {
                $dynamicGoal = $assessment->benchmark_goal;
            } elseif ($assessment->benchmark_goal == NULL) {
                $dynamicGoal = $this->resolveBenchmarkGoal(
                    $student->organisation_id,
                    $language->id ?? $assessment->passage->language_id,
                    $assessment->grade_id ?? $grade_id,
                    $assessmentLevelId,
                    $assessment->assessment_period
                );
            }

            return [
                'created' => $this->formatAssessmentTime($assessment->created),
                'passage__passage_name' => $assessment->passage->passage_name,
                'goal' => $dynamicGoal,
                'score' => $score,
            ];
        });

        // ============================================
        // 9. CALCULATE ACCURACY (On top 5 only)
        // ============================================
        if ($topAssessments->count() > 0) {
            // Python calculates average of (correct / total) for the top 5
            $total_accuracy = $topAssessments->sum(function ($assessment) {
                $total_words = $assessment->last_word_index + 1;
                $correct_words = $total_words - $assessment->no_error_words;
                return $total_words > 0 ? ($correct_words / $total_words) : 0;
            });
            $accuracy = ($total_accuracy / $topAssessments->count()) * 100;

            // Replicate Python logic: stats_exists only set true if NO date filters used
            if ($flag === false) {
                $stats_exists = true;
            }
        }

        // ============================================
        // 10. PREPARE & RETURN RESPONSE
        // ============================================
        if ($stats_exists || $flag === true) {
            $data['stats'] = $stats->toArray();
        }
        $data['stats_exists'] = $stats_exists;
        $data['no_of_assessments'] = $no_of_assessments;
        $data['accuracy'] = $accuracy;

        return response()->json($data, 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Performance per Grade
     * Replicates legacy Django performance_per_grade endpoint exactly with template-aware resolution.
     */
    public function performancePerGrade(Request $request)
    {
        $organisationId = $this->sanitizeUuid($request->query('organisation', $request->query('organisation_id', null)));
        if (!$organisationId) {
            return response()->json(['error' => 'organisation id is not sent'], 400);
        }

        $organisation = Organisation::find($organisationId);
        if (!$organisation) {
            return response()->json(['error' => 'Organisation not found'], 404);
        }

        $languageName = $request->query('language', $request->query('languages__name', 'English'));
        $language = Language::where('name', 'ilike', $languageName)->first();
        if (!$language) {
            $language = Language::where('name', 'ilike', 'English')->first();
        }
        $languageId = $language ? $language->id : null;

        // Fetch students in this organisation who have at least one assessment
        $studentIds = DB::table('assessment_assessment as a')
            ->join('access_student as s', 'a.student_id', '=', 's.id')
            ->where('s.organisation_id', $organisationId)
            ->pluck('s.id')
            ->unique()
            ->toArray();

        $students = Student::whereIn('id', $studentIds)
            ->whereHas('grade', function ($q) {
                $q->where('level', '!=', 6);
            })
            ->with('grade')
            ->get();

        $grades = $organisation->grades()->where('level', '!=', 6)->orderBy('title', 'asc')->get();

        $level1 = Level::where('rank', 1)->first();
        $levelId = $level1 ? $level1->id : null;

        $result = [];
        $counts = [];

        foreach ($grades as $g) {
            $goal = $this->resolveBenchmarkGoal(
                $organisationId,
                $languageId,
                $g->id,
                $levelId,
                $request->input('assessment_period')
            );

            $result[(string)$g->level] = [
                'grade' => $g->id,
                'grade_title' => $g->title,
                'assessment_1' => 0.0,
                'assessment_2' => 0.0,
                'assessment_3' => 0.0,
                'goal' => (float)$goal,
            ];
            $counts[(string)$g->level] = [0, 0, 0];
        }

        foreach ($students as $student) {
            $gradeLevel = (string)$student->grade->level;

            // Fetch top 3 latest benchmark assessments (type = 1) for the resolved language
            $assessments = DB::table('assessment_assessment as a')
                ->join('passage_passage as p', 'a.passage_id', '=', 'p.id')
                ->where('a.type', 1)
                ->where('a.student_id', $student->id)
                ->where('p.language_id', $languageId)
                ->orderBy('a.created', 'desc')
                ->limit(3)
                ->select(DB::raw('(a.last_word_index + 1 - a.no_error_words) as score'))
                ->pluck('score')
                ->toArray();

            $cnt = count($assessments);
            if ($cnt >= 3) {
                // assessments[0] is latest, [1] is 2nd latest, [2] is 3rd latest
                $a1 = (float)$assessments[2]; // 3rd latest
                $a2 = (float)$assessments[1]; // 2nd latest
                $a3 = (float)$assessments[0]; // LATEST

                $result[$gradeLevel]['assessment_1'] += $a1;
                $result[$gradeLevel]['assessment_2'] += $a2;
                $result[$gradeLevel]['assessment_3'] += $a3;
                $counts[$gradeLevel][0]++;
                $counts[$gradeLevel][1]++;
                $counts[$gradeLevel][2]++;
            } elseif ($cnt == 2) {
                $a1 = (float)$assessments[1]; // 2nd latest
                $a2 = (float)$assessments[0]; // LATEST

                $result[$gradeLevel]['assessment_1'] += $a1;
                $result[$gradeLevel]['assessment_2'] += $a2;
                $counts[$gradeLevel][0]++;
                $counts[$gradeLevel][1]++;
            } elseif ($cnt == 1) {
                $a1 = (float)$assessments[0]; // LATEST

                $result[$gradeLevel]['assessment_1'] += $a1;
                $counts[$gradeLevel][0]++;
            }
        }

        $response = [];
        foreach ($result as $key => $data) {
            for ($i = 0; $i < 3; $i++) {
                $div = $counts[$key][$i];
                if ($div > 0) {
                    $data['assessment_' . ($i + 1)] = (float)($data['assessment_' . ($i + 1)] / $div);
                } else {
                    $data['assessment_' . ($i + 1)] = 0.0;
                }
            }
            $response[] = $data;
        }

        usort($response, function ($a, $b) {
            return strnatcasecmp($a['grade_title'], $b['grade_title']);
        });

        return response()->json($response, 200);
    }
}
