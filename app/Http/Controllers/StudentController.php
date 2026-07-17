<?php

namespace App\Http\Controllers;

use App\Constants\TranslationConstants;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use App\Models\Student;
use App\Models\User;
use App\Models\BenchmarkTemplate;

class StudentController extends Controller
{
    /**
     * Get Students
     */
    public function getStudents(Request $request)
    {
        $limit = $request->get('limit', 50);
        $offset = $request->get('offset', 0);

        $search = $request->get('search', '');
        $division = $request->get('division', $request->get('division__name', ''));
        $organisation = $this->sanitizeUuid($request->get('organisation', ''));
        $grade = $this->sanitizeUuid($request->get('grade', ''));
        $grade_level = $request->get('grade__level', '');
        $promoted = $request->get('promoted', '');
        $lang = $request->get('lang', $request->get('language', $request->get('languages__name', '')));
        $group_id = $this->sanitizeUuid($request->get('group_id', $request->get('group__id', '')));
        $group_hindi = $this->sanitizeUuid($request->get('group_hindi', ''));
        $group_marathi = $this->sanitizeUuid($request->get('group_marathi', ''));
        $teacher_id = $this->sanitizeUuid($request->get('teachers_id', $request->get('teacher_id', $request->get('teachers__id', ''))));

        // Auto-detect language if specific group filters are used but lang is not provided
        if (!$lang) {
            if ($group_hindi) {
                $lang = 'Hindi';
            } elseif ($group_marathi) {
                $lang = 'Marathi';
            }
        }

        $teacherDivisions = [];
        if ($teacher_id) {
            $teacher = DB::table('access_teacher')->where('id', $teacher_id)->first();
            if ($teacher && $teacher->division) {
                // Normalize teacher divisions to uppercase for robust matching
                $teacherDivisions = array_map('trim', explode(',', $teacher->division));
                $teacherDivisions = array_filter(array_map('strtoupper', $teacherDivisions));
            }
        }


        // Dynamic ordering logic
        $ordering = $request->query('ordering', 'first_name');
        $orderColumn = 'u.first_name';
        $orderDirection = 'asc';

        if ($ordering) {
            if (str_starts_with($ordering, '-')) {
                $orderDirection = 'desc';
                $ordering = substr($ordering, 1);
            } else {
                $orderDirection = 'asc';
            }

            if ($ordering === 'created_at' || $ordering === 'date_joined') {
                $orderColumn = 'u.date_joined';
            } elseif ($ordering === 'id') {
                $orderColumn = 'u.id';
            } elseif ($ordering === 'username') {
                $orderColumn = 'u.username';
            } elseif ($ordering === 'first_name') {
                $orderColumn = 'u.first_name';
            } elseif ($ordering === 'group_title' || $ordering === 'title') {
                $orderColumn = 'g.title';
            }
        }



        $groupColumn = 'group_id';
        if ($lang) {
            $langLower = strtolower($lang);
            if ($langLower == 'hindi') {
                $groupColumn = 'group_hindi_id';
            } elseif ($langLower == 'marathi') {
                $groupColumn = 'group_marathi_id';
            }
        }
        $selectedGroupColumn = 's.' . $groupColumn;

        // Pre-fetch language ID for safe interpolation
        $languageLookup = DB::table('common_language')->where('name', 'ilike', $lang ?: 'English')->first();
        if (!$languageLookup) {
            $languageLookup = DB::table('common_language')->where('name', 'ilike', 'English')->first();
        }
        $langId = $languageLookup ? $languageLookup->id : null;

        $query = DB::table('access_student as s')
            ->select([
                's.id',
                'g.id as group_id',
                'g.title as group_title',
                'g.description as group_description',
                'g.organisation_id as group_organisation',
                'g.grade_id as group_grade',
                'g.level_id as group_level',
                'g.created as group_created',
                'g.updated as group_updated',
                DB::raw("json_build_object(
                    'username', u.username,
                    'first_name', u.first_name,
                    'last_name', u.last_name,
                    'role_id', s.id,
                    'full_name', concat(u.first_name, ' ', u.last_name),
                    'email', u.email,
                    'role', 'Student',
                    'organisation', json_build_object(
                        'id', o.id,
                        'title', o.title
                    ),
                    'groups', g.title
                ) as user_detail"),
                'o.title as organisation_title',
                'gr.title as grade_title',
                DB::raw("'Student' as role"),
                DB::raw("(
                    SELECT json_agg(cl.name)
                    FROM access_student_languages asl
                    JOIN common_language cl ON cl.id = asl.language_id
                    WHERE asl.student_id = s.id
                ) AS languages"),
                DB::raw("NULLIF(s.division, '') as division"),
                'g.title as title',
                's.date_of_birth as date_of_birth',
                's.gender as gender',
                's.is_english_second_language as is_english_second_language',
                's.disability as disability',
                DB::raw("NULLIF(s.picture, '') as picture"),
                's.user_id as user',
                's.organisation_id as organisation',
                's.grade_id as grade',
                's.group_id as group',
                's.group_hindi_id as group_hindi',
                's.group_marathi_id as group_marathi',
                'last_ass.last_assessment_time',
                DB::raw("CASE WHEN last_ass.last_assessment_time IS NOT NULL THEN true ELSE false END as is_assessed"),
            ])
            ->leftJoin(DB::raw("(SELECT student_id, MAX(created) as last_assessment_time FROM assessment_assessment GROUP BY student_id) as last_ass"), 'last_ass.student_id', '=', 's.id')
            ->leftJoin('organisation_group as g', 'g.id', '=', $selectedGroupColumn)
            ->join('organisation_organisation as o', 'o.id', '=', 's.organisation_id')
            ->join('common_grade as gr', 'gr.id', '=', 's.grade_id')
            ->join('auth_user as u', 'u.id', '=', 's.user_id');

        if ($division) {
            $query->where(function ($q) use ($division) {
                $divs = is_array($division) ? $division : explode(',', $division);
                foreach ($divs as $div) {
                    $q->orWhere('s.division', 'ILIKE', "%" . trim($div) . "%");
                }
            });
        }

        if ($organisation) {
            $query->where('s.organisation_id', $organisation);
        }

        if ($grade) {
            $query->where('s.grade_id', $grade);
        }

        if ($grade_level) {
            $query->where('gr.level', $grade_level);
        }

        if ($promoted !== '') {
            $isPromoted = filter_var($promoted, FILTER_VALIDATE_BOOLEAN);
            if ($isPromoted) {
                $query->whereExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('organisation_studentpromotionhistory as sph')
                        ->whereColumn('sph.student_id', 's.id')
                        ->whereColumn('sph.grade_id', 's.grade_id');
                });
            } else {
                $query->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('organisation_studentpromotionhistory as sph')
                        ->whereColumn('sph.student_id', 's.id')
                        ->whereColumn('sph.grade_id', 's.grade_id');
                });
            }
        }

        if ($group_id) {
            $query->where($selectedGroupColumn, $group_id);
        }

        if ($group_hindi) {
            $query->where('s.group_hindi_id', $group_hindi);
        }

        if ($group_marathi) {
            $query->where('s.group_marathi_id', $group_marathi);
        }

        if ($lang) {
            $query->whereNotNull($selectedGroupColumn);
            if ($langId) {
                $query->whereExists(function ($q) use ($langId) {
                    $q->select(DB::raw(1))
                        ->from('access_student_languages')
                        ->whereColumn('student_id', 's.id')
                        ->where('language_id', $langId);
                });
            }
        }

        if ($teacher_id) {
            // Filter by teacher's groups (Strict scoping to teacher-assigned groups)
            $query->whereExists(function ($q) use ($teacher_id, $selectedGroupColumn) {
                $q->select(DB::raw(1))
                    ->from('access_teacher_groups as tg')
                    ->whereColumn('tg.group_id', $selectedGroupColumn)
                    ->where('tg.teacher_id', $teacher_id);
            });
        }

        // Teacher Division Filtering for parity with /users API
        if ($teacher_id && !empty($teacherDivisions)) {
            $query->where(function ($q) use ($teacherDivisions) {
                $q->whereIn(DB::raw('UPPER(s.division)'), $teacherDivisions)
                  ->orWhereNull('s.division')
                  ->orWhere('s.division', '');
            });
        }

        // Define common mapping function for decryption and cleanup
        $mapper = function ($item) use ($langId) {
            if (is_string($item->user_detail)) {
                $item->user_detail = json_decode($item->user_detail, true);
            }
            if (is_string($item->languages)) {
                $item->languages = json_decode($item->languages, true) ?: [];
            } elseif (is_null($item->languages)) {
                $item->languages = [];
            }

            $item->is_assessed = (bool) $item->is_assessed;
            $item->last_assessment_time = $item->last_assessment_time ? $this->formatAssessmentTime($item->last_assessment_time) : null;

            try {
                if (!empty($item->user_detail['first_name']) && str_contains($item->user_detail['first_name'], 'eyJpdiI6')) {
                    $item->user_detail['first_name'] = Crypt::decryptString($item->user_detail['first_name']);
                }
                if (!empty($item->user_detail['last_name']) && str_contains($item->user_detail['last_name'], 'eyJpdiI6')) {
                    $item->user_detail['last_name'] = Crypt::decryptString($item->user_detail['last_name']);
                }
                $item->user_detail['full_name'] = trim(($item->user_detail['first_name'] ?? '') . ' ' . ($item->user_detail['last_name'] ?? ''));
            } catch (\Exception $e) {
                Log::error('Error decrypting user details: ' . $e->getMessage());
            }

            $item->benchmark_template_id = BenchmarkTemplate::findTemplateId($item->organisation, $langId);

            return $item;
        };

        // If search is present, fetch all results and filter them
        if ($search) {
            $allResults = $query->when($orderColumn === 'g.title', function ($q) use ($orderDirection) {
                $q->orderBy(DB::raw('LENGTH(g.title)'), $orderDirection);
            })
                ->orderBy($orderColumn, $orderDirection)
                ->get()
                ->map($mapper);

            $searchLower = strtolower($search);
            $filteredResults = $allResults->filter(function ($item) use ($searchLower) {
                return str_contains(strtolower($item->user_detail['first_name'] ?? ''), $searchLower) ||
                    str_contains(strtolower($item->user_detail['last_name'] ?? ''), $searchLower) ||
                    str_contains(strtolower($item->user_detail['full_name'] ?? ''), $searchLower) ||
                    str_contains(strtolower($item->user_detail['username'] ?? ''), $searchLower);
            });

            $total = $filteredResults->count();
            $results = $filteredResults->slice($offset, $limit)->values();
        } else {
            $totalCountQuery = DB::table('access_student as s')
                ->join('auth_user as u', 'u.id', '=', 's.user_id')
                ->join('organisation_organisation as o', 'o.id', '=', 's.organisation_id')
                ->join('common_grade as gr', 'gr.id', '=', 's.grade_id');

            // If group_id, lang, or language-specific group filters are present, join with organisation_group
            if ($group_id || $lang || $group_hindi || $group_marathi) {
                $totalCountQuery->leftJoin('organisation_group as g', 'g.id', '=', $selectedGroupColumn);
            }

            if ($division) {
                $totalCountQuery->where(function ($q) use ($division) {
                    $divs = is_array($division) ? $division : explode(',', $division);
                    foreach ($divs as $div) {
                        $q->orWhere('s.division', 'ILIKE', "%" . trim($div) . "%");
                    }
                });
            }

            if ($organisation) {
                $totalCountQuery->where('s.organisation_id', $organisation);
            }

            if ($grade) {
                $totalCountQuery->where('s.grade_id', $grade);
            }

            if ($grade_level) {
                $totalCountQuery->where('gr.level', $grade_level);
            }

            if ($promoted !== '') {
                $isPromoted = filter_var($promoted, FILTER_VALIDATE_BOOLEAN);
                if ($isPromoted) {
                    $totalCountQuery->whereExists(function ($q) {
                        $q->select(DB::raw(1))
                            ->from('organisation_studentpromotionhistory as sph')
                            ->whereColumn('sph.student_id', 's.id')
                            ->whereColumn('sph.grade_id', 's.grade_id');
                    });
                } else {
                    $totalCountQuery->whereNotExists(function ($q) {
                        $q->select(DB::raw(1))
                            ->from('organisation_studentpromotionhistory as sph')
                            ->whereColumn('sph.student_id', 's.id')
                            ->whereColumn('sph.grade_id', 's.grade_id');
                    });
                }
            }

            if ($group_id) {
                $totalCountQuery->where($selectedGroupColumn, $group_id);
            }

            if ($group_hindi) {
                $totalCountQuery->where('s.group_hindi_id', $group_hindi);
            }

            if ($group_marathi) {
                $totalCountQuery->where('s.group_marathi_id', $group_marathi);
            }

            if ($lang) {
                $totalCountQuery->whereNotNull($selectedGroupColumn);
                if ($langId) {
                    $totalCountQuery->whereExists(function ($q) use ($langId) {
                        $q->select(DB::raw(1))
                            ->from('access_student_languages')
                            ->whereColumn('student_id', 's.id')
                            ->where('language_id', $langId);
                    });
                }
            }

            if ($teacher_id) {
                // Filter by teacher's groups
                $totalCountQuery->whereExists(function ($q) use ($teacher_id, $selectedGroupColumn) {
                    $q->select(DB::raw(1))
                        ->from('access_teacher_groups as tg')
                        ->whereColumn('tg.group_id', $selectedGroupColumn)
                        ->where('tg.teacher_id', $teacher_id);
                });
            }

            if ($teacher_id && !empty($teacherDivisions)) {
                $totalCountQuery->where(function ($q) use ($teacherDivisions) {
                    $q->whereIn(DB::raw('UPPER(s.division)'), $teacherDivisions)
                      ->orWhereNull('s.division')
                      ->orWhere('s.division', '');
                });
            }

            $total = $totalCountQuery->count(DB::raw('DISTINCT s.id'));

            $results = $query->offset($offset)
                ->limit($limit)
                ->when($orderColumn === 'g.title', function ($q) use ($orderDirection) {
                    $q->orderBy(DB::raw('LENGTH(g.title)'), $orderDirection);
                })
                ->orderBy($orderColumn, $orderDirection)
                ->get()
                ->map($mapper);
        }

        // --- BATCH FETCH GROUP METRICS (Fix for group_detail counts) ---
        $uniqueGroupIds = $results->pluck('group_id')->filter()->unique()->values()->all();
        $groupMetrics = [];

        if (!empty($uniqueGroupIds)) {
            $langName = $lang ?: 'English';
            $groupsInfo = DB::table('organisation_group as g')
                ->whereIn('g.id', $uniqueGroupIds)
                ->get();

            foreach ($groupsInfo as $group) {
                // 1. Student Count - Respect division and language association (conditional)
                $scQuery = DB::table('access_student as s')
                    ->where('s.' . $groupColumn, $group->id);

                if ($lang && $langId) {
                    $scQuery->join('access_student_languages as asl', 'asl.student_id', '=', 's.id')
                        ->where('asl.language_id', $langId);
                }

                if ($division) {
                    $scQuery->where(function ($q) use ($division) {
                        $divs = is_array($division) ? $division : explode(',', $division);
                        foreach ($divs as $div) {
                            $q->orWhere('s.division', 'ILIKE', '%' . trim($div) . '%');
                        }
                    });
                }

                // Teacher Division Filtering for parity with /users API
                if ($teacher_id && !empty($teacherDivisions)) {
                    $scQuery->where(function ($q) use ($teacherDivisions) {
                        $q->whereIn(DB::raw('UPPER(s.division)'), $teacherDivisions)
                          ->orWhereNull('s.division')
                          ->orWhere('s.division', '');
                    });
                }

                $studentCount = $scQuery->count(DB::raw('DISTINCT s.id'));

                // 2. Teacher Count
                $teacherCount = DB::table('access_teacher as t')
                    ->join('access_teacher_groups as tg', 'tg.teacher_id', '=', 't.id')
                    ->join('access_teacher_languages as tl', 'tl.teacher_id', '=', 't.id')
                    ->where('tg.group_id', $group->id)
                    ->where('tl.language_id', $langId)
                    ->count(DB::raw('DISTINCT t.id'));

                // 3. Average
                $avgQuery = DB::table('assessment_assessment as a')
                    ->join('passage_passage as p', 'a.passage_id', '=', 'p.id')
                    ->join('access_student as s', 'a.student_id', '=', 's.id')
                    ->join('access_student_languages as sl', 's.id', '=', 'sl.student_id')
                    ->where('a.group_id', $group->id)
                    ->where('s.' . $groupColumn, $group->id)
                    ->where('p.language_id', $langId)
                    ->where('sl.language_id', $langId);

                if ($division) {
                    $avgQuery->where(function ($q) use ($division) {
                        $divs = is_array($division) ? $division : explode(',', $division);
                        foreach ($divs as $div) {
                            $q->orWhere('s.division', 'ILIKE', '%' . trim($div) . '%');
                        }
                    });
                }

                // Show students based on division and if division is not empty, show students of that division and if division is empty, show all students
                if ($teacher_id && !empty($teacherDivisions)) {
                    $avgQuery->where(function ($q) use ($teacherDivisions) {
                        $q->whereIn(DB::raw('UPPER(s.division)'), $teacherDivisions)
                          ->orWhereNull('s.division')
                          ->orWhere('s.division', '');
                    });
                }

                $avgCorrect = $avgQuery->avg(DB::raw('a.last_word_index + 1 - a.no_error_words'));

                $goal = $this->resolveBenchmarkGoal(
                    $group->organisation_id,
                    $langId,
                    $group->grade_id,
                    $group->level_id,
                    $request->input('assessment_period')
                );

                $benchmarkTemplateId = $this->resolveBenchmarkTemplateId($group->organisation_id, $langId);
                if (!$goal) {
                    $benchmarkTemplateId = null;
                }

                // 5. Last Activity
                $ltQuery = DB::table('assessment_assessment as a')
                    ->join('passage_passage as p', 'a.passage_id', '=', 'p.id')
                    ->join('access_student as s', 'a.student_id', '=', 's.id')
                    ->where('p.language_id', $langId)
                    ->where(function ($q) use ($group, $groupColumn) {
                        $q->where('a.group_id', $group->id)
                            ->orWhere('s.' . $groupColumn, $group->id);
                    });

                if ($division) {
                    $ltQuery->where(function ($q) use ($division) {
                        $divs = is_array($division) ? $division : explode(',', $division);
                        foreach ($divs as $div) {
                            $q->orWhere('s.division', 'ILIKE', '%' . trim($div) . '%');
                        }
                    });
                }

                if ($teacher_id && !empty($teacherDivisions)) {
                    $ltQuery->where(function ($q) use ($teacherDivisions) {
                        $q->whereIn(DB::raw('UPPER(s.division)'), $teacherDivisions)
                          ->orWhereNull('s.division')
                          ->orWhere('s.division', '');
                    });
                }

                $rawTime = $ltQuery->orderBy('a.created', 'desc')->value('a.created');

                $groupMetrics[$group->id] = [
                    'id'                   => $group->id,
                    'teachers_count'       => (int) $teacherCount,
                    'students_count'       => (int) $studentCount,
                    'last_assessment_time' => $rawTime ? $this->formatAssessmentTime($rawTime) : null,
                    'goal'                 => (float) ($goal ?? 0),
                    'current_average'      => (float) ($avgCorrect ?? 0),
                    'created'              => $group->created ? $this->formatDateTime($group->created) : null,
                    'updated'              => $group->updated ? $this->formatDateTime($group->updated) : null,
                    'title'                => $this->translateTitle($group->title, $langName),
                    'description'          => $group->description ?? null,
                    'organisation'         => $group->organisation_id,
                    'grade'                => $group->grade_id,
                    'level'                => $group->level_id,
                    'benchmark_template_id'=> $benchmarkTemplateId,
                ];
            }
        }

        // Final Mapping to attach group_detail
        foreach ($results as $item) {
            if ($item->group_id && isset($groupMetrics[$item->group_id])) {
                $item->group_detail = $groupMetrics[$item->group_id];
                $item->benchmark_template_id = $item->group_detail['benchmark_template_id'];
                unset($item->group_detail['benchmark_template_id']);
            } else {
                $item->group_detail = null;
            }
            // Cleanup internal helper fields
            unset($item->group_id, $item->group_title, $item->group_description, $item->group_organisation, $item->group_grade, $item->group_level, $item->group_created, $item->group_updated);
        }

        // Format API response
        $response = [
            'count' => $total,
            'next' => ($offset + $limit < $total) ? url()->current() . '?' . http_build_query(array_merge($request->query(), ['offset' => $offset + $limit])) : null,
            'previous' => $offset > 0 ? url()->current() . '?' . http_build_query(array_merge($request->query(), ['offset' => max(0, $offset - $limit)])) : null,
            'results' => $results
        ];
        return $response;
    }

    /**
     * Delete a student and their associated user account.
     *
     * @param string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteStudent($id)
    {
        try {
            $user = auth()->user();
            $admin = DB::table('access_admin')->where('user_id', $user->id)->first();

            if (!$admin) {
                return response()->json(['message' => 'User is not authorized to delete a student'], 403);
            }

            DB::transaction(function () use ($id) {
                $student = Student::findOrFail($id);
                $user = $student->user;

                // Detach relationships to prevent foreign key constraint violations
                if (method_exists($student, 'languages')) {
                    $student->languages()->detach();
                }

                // Delete student record first to avoid FK violation if user is deleted first
                $student->delete();

                // Delete the associated user record if it exists
                if ($user) {
                    $user->delete();
                }
            });

            return response()->json("Deleted", 202);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete student',
                'details' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Translate the group title based on the selected language.
     *
     * @param string|null $title
     * @param string $langName
     * @return string
     */
    protected function translateTitle(?string $title, string $langName): string
    {
        if (!$title) return "";
        $langName = ucfirst(strtolower($langName));
        if ($langName === 'English') {
            return $title;
        }

        $parts = explode(' ', trim($title), 2);
        $translatedParts = [];

        foreach ($parts as $part) {
            $translatedParts[] = TranslationConstants::GROUP_TRANSLATIONS[$part][$langName] ?? $part;
        }

        return implode(' ', $translatedParts);
    }

    /**
     * Format assessment time to ISO 8601 Zulu string with microseconds.
     */
    protected function formatAssessmentTime($time): ?string
    {
        if (!$time) return null;
        try {
            return Carbon::parse($time)->toIso8601ZuluString('microsecond');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Format date time to ISO 8601 Zulu string.
     */
    protected function formatDateTime($time): ?string
    {
        if (!$time) return null;
        try {
            return Carbon::parse($time)->toIso8601ZuluString();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Sanitize UUID inputs to prevent malformed queries.
     *
     * @param string|null $val
     * @return string|null
     */
    protected function sanitizeUuid(?string $val): ?string
    {
        if (!$val) return null;
        return preg_replace('/[^a-fA-F0-9-]/', '', $val);
    }
}
