<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Constants\TranslationConstants;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use App\Models\BenchmarkTemplate;

class TeacherController extends Controller
{
    /**
     * Get a list of teachers with pagination, filtering, and search.
     * Matches Python structure /api/teachers/
     */
    public function index(Request $request)
    {
        $limit = $request->get('limit', 50);
        $offset = $request->get('offset', 0);
        $search = $request->get('search', '');
        $organisation = $this->sanitizeUuid($request->get('organisation', ''));
        $division = $request->get('division', $request->get('division__name', ''));
        $teacher_id = $this->sanitizeUuid($request->get('teachers_id', $request->get('teacher_id', $request->get('teachers__id', ''))));
        $ordering = $request->get('ordering', '-date_joined');

        // Dynamic ordering logic
        $orderColumn = 'u.date_joined';
        $orderDirection = 'desc';

        if ($ordering) {
            if (str_starts_with($ordering, '-')) {
                $orderDirection = 'desc';
                $ordering = substr($ordering, 1);
            } else {
                $orderDirection = 'asc';
            }

            if ($ordering === 'date_joined' || $ordering === 'created_at') {
                $orderColumn = 'u.date_joined';
            } elseif ($ordering === 'username') {
                $orderColumn = 'u.username';
            } elseif ($ordering === 'first_name') {
                $orderColumn = 'u.first_name';
            } elseif ($ordering === 'id') {
                $orderColumn = 't.id';
            }
        }
        // Base query building
        $query = DB::table('access_teacher as t')
            ->select([
                't.id',
                't.division',
                't.type',
                't.gender',
                't.user_id as user',
                't.organisation_id as organisation',
                'o.title as organisation_title',
                'u.username',
                'u.first_name',
                'u.last_name',
                'u.email',
                'u.date_joined',
                DB::raw("(
                    SELECT json_agg(cl.name)
                    FROM access_teacher_languages atl
                    JOIN common_language cl ON cl.id = atl.language_id
                    WHERE atl.teacher_id = t.id
                ) AS languages"),
                DB::raw("(
                    SELECT json_agg(og.id)
                    FROM access_teacher_groups atg
                    JOIN organisation_group og ON og.id = atg.group_id
                    WHERE atg.teacher_id = t.id
                ) AS groups")
            ])
            ->join('auth_user as u', 'u.id', '=', 't.user_id')
            ->join('organisation_organisation as o', 'o.id', '=', 't.organisation_id');

        // Apply filters
        if ($organisation) {
            $query->where('t.organisation_id', $organisation);
        }

        if ($teacher_id) {
            $query->where('t.id', $teacher_id);
        }

        if ($division) {
            $query->where(function ($q) use ($division) {
                $divs = is_array($division) ? $division : explode(',', $division);
                foreach ($divs as $div) {
                    $q->orWhere('t.division', 'ILIKE', "%" . trim($div) . "%");
                }
            });
        }

        // 1. Fetch Teachers (Paginated)
        $teachersResults = [];
        if ($search) {
            $searchLower = strtolower($search);
            $allResults = $query->orderBy($orderColumn, $orderDirection)->get();

            $filteredResults = $allResults->filter(function ($item) use ($searchLower) {
                // Decrypt for name search
                $firstName = $item->first_name;
                $lastName = $item->last_name;
                try {
                    if (!empty($firstName) && str_contains($firstName, 'eyJpdiI6')) {
                        $firstName = Crypt::decryptString($firstName);
                    }
                    if (!empty($lastName) && str_contains($lastName, 'eyJpdiI6')) {
                        $lastName = Crypt::decryptString($lastName);
                    }
                } catch (\Exception $e) {
                }

                $fullName = trim($firstName . ' ' . $lastName);
                return str_contains(strtolower($firstName), $searchLower) ||
                    str_contains(strtolower($lastName), $searchLower) ||
                    str_contains(strtolower($fullName), $searchLower) ||
                    str_contains(strtolower($item->username), $searchLower) ||
                    str_contains(strtolower($item->email), $searchLower);
            });

            $total = $filteredResults->count();
            $teachersResults = $filteredResults->slice($offset, $limit)->values();
        } else {
            $total = $query->count();
            $teachersResults = $query->orderBy($orderColumn, $orderDirection)
                ->offset($offset)
                ->limit($limit)
                ->get();
        }

        // 2. Extract unique group IDs and calculate metrics efficiently
        $allGroupIds = [];
        foreach ($teachersResults as $t) {
            $teacherGroups = is_string($t->groups) ? json_decode($t->groups, true) : ($t->groups ?: []);
            if ($teacherGroups) {
                $allGroupIds = array_merge($allGroupIds, $teacherGroups);
            }
        }
        $uniqueGroupIds = array_unique($allGroupIds);

        // Language Handling (global for the listing)
        $langName = $request->get('lang', $request->get('language', $request->get('languages__name', 'English')));
        $language = DB::table('common_language')->where('name', 'ilike', $langName)->first();
        if (!$language) {
            $language = DB::table('common_language')->where('name', 'ilike', 'English')->first();
        }
        $language_id = $language ? $language->id : null;

        $groupColumn = 'group_id';
        if (strtolower($langName) === 'hindi') {
            $groupColumn = 'group_hindi_id';
        } elseif (strtolower($langName) === 'marathi') {
            $groupColumn = 'group_marathi_id';
        }

        // Track if language filter was explicitly requested
        $isLangExplicit = $request->has('lang') || $request->has('language') || $request->has('languages__name');

        // Batch Fetch Group Metrics
        $groupMetrics = [];
        if (!empty($uniqueGroupIds)) {
            $groupsInfo = DB::table('organisation_group as g')
                ->whereIn('g.id', $uniqueGroupIds)
                ->get();

            foreach ($groupsInfo as $group) {
                // Fetch grade level and level rank for sorting
                $grade = DB::table('common_grade')->where('id', $group->grade_id)->first();
                $level = DB::table('common_level')->where('id', $group->level_id)->first();

                // 1. Student Count - Conditionally filter by language association
                $scQuery = DB::table('access_student as s')
                    ->where('s.' . $groupColumn, $group->id);

                if ($isLangExplicit && $language_id) {
                    $scQuery->join('access_student_languages as asl', 'asl.student_id', '=', 's.id')
                        ->where('asl.language_id', $language_id);
                }

                if ($division) {
                    $scQuery->where(function ($q) use ($division) {
                        $divs = is_array($division) ? $division : explode(',', $division);
                        foreach ($divs as $div) {
                            $q->orWhere('s.division', 'ILIKE', '%' . trim($div) . '%');
                        }
                    });
                }

                $studentCount = $scQuery->count(DB::raw('DISTINCT s.id'));

                // 2. Teacher Count
                $teacherCount = DB::table('access_teacher as t')
                    ->join('access_teacher_groups as tg', 'tg.teacher_id', '=', 't.id')
                    ->join('access_teacher_languages as tl', 'tl.teacher_id', '=', 't.id')
                    ->where('tg.group_id', $group->id)
                    ->where('tl.language_id', $language_id)
                    ->count(DB::raw('DISTINCT t.id'));

                // 3. Average
                $avgQuery = DB::table('assessment_assessment as a')
                    ->join('passage_passage as p', 'a.passage_id', '=', 'p.id')
                    ->join('access_student as s', 'a.student_id', '=', 's.id')
                    ->join('access_student_languages as sl', 's.id', '=', 'sl.student_id')
                    ->where('a.group_id', $group->id)
                    ->where('s.' . $groupColumn, $group->id)
                    ->where('p.language_id', $language_id)
                    ->where('sl.language_id', $language_id);

                if ($division) {
                    $avgQuery->where(function ($q) use ($division) {
                        $divs = is_array($division) ? $division : explode(',', $division);
                        foreach ($divs as $div) {
                            $q->orWhere('s.division', 'ILIKE', '%' . trim($div) . '%');
                        }
                    });
                }

                $avgCorrect = $avgQuery->avg(DB::raw('a.last_word_index + 1 - a.no_error_words'));

                $goal = $this->resolveBenchmarkGoal(
                    $group->organisation_id,
                    $language_id,
                    $group->grade_id,
                    $group->level_id,
                    $request->input('assessment_period')
                );

                // 5. Last Activity
                $ltQuery = DB::table('assessment_assessment as a')
                    ->join('passage_passage as p', 'a.passage_id', '=', 'p.id')
                    ->join('access_student as s', 'a.student_id', '=', 's.id')
                    ->where('p.language_id', $language_id)
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
                    'grade_level'          => $grade ? $grade->level : 0,
                    'level_rank'           => $level ? $level->rank : 0,
                ];
            }
        }

        // 3. Map everything into results
        $results = $teachersResults->map(function ($item) use ($groupMetrics, $langName) {
            $teacherGroups = is_string($item->groups) ? json_decode($item->groups, true) : ($item->groups ?: []);

            $firstName = $item->first_name;
            $lastName = $item->last_name;
            try {
                if (!empty($firstName) && str_contains($firstName, 'eyJpdiI6')) {
                    $firstName = Crypt::decryptString($firstName);
                }
                if (!empty($lastName) && str_contains($lastName, 'eyJpdiI6')) {
                    $lastName = Crypt::decryptString($lastName);
                }
            } catch (\Exception $e) {
            }

            $fullName = trim($firstName . ' ' . $lastName);

            $gDetails = [];
            if ($teacherGroups) {
                foreach ($teacherGroups as $gId) {
                    if (isset($groupMetrics[$gId])) {
                        $gDetails[] = $groupMetrics[$gId];
                    }
                }
                // Sort group_details by grade_level and level_rank to match Python reference
                usort($gDetails, function ($a, $b) {
                    if ($a['grade_level'] !== $b['grade_level']) {
                        return $a['grade_level'] <=> $b['grade_level'];
                    }
                    return $a['level_rank'] <=> $b['level_rank'];
                });
            }

            $gTitles = array_column($gDetails, 'title');

            $languages = is_string($item->languages) ? json_decode($item->languages, true) : ($item->languages ?: []);

            return [
                'id' => $item->id,
                'group_details' => $gDetails,
                'user_detail' => [
                    'username' => $item->username,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'role_id' => $item->id,
                    'full_name' => $fullName,
                    'email' => $item->email,
                    'role' => 'Teacher',
                    'organisation' => [
                        'id' => $item->organisation,
                        'title' => $item->organisation_title
                    ],
                    'groups' => $gTitles
                ],
                'organisation_title' => $item->organisation_title,
                'role' => 'Teacher',
                'languages' => $languages,
                'type' => $item->type,
                'gender' => $item->gender,
                'user' => $item->user,
                'organisation' => $item->organisation,
                'groups' => $teacherGroups
            ];
        });

        return response()->json([
            'count' => $total,
            'next' => ($offset + $limit < $total) ? $request->fullUrlWithQuery(['offset' => $offset + $limit]) : null,
            'previous' => ($offset > 0) ? $request->fullUrlWithQuery(['offset' => max(0, $offset - $limit)]) : null,
            'results' => $results
        ], 200);
    }

    /**
     * Get details for a single teacher.
     * Matches Python structure /api/teachers/:id
     */
    public function show(Request $request, $id)
    {
        $id = $this->sanitizeUuid($id);
        if (!$id) {
            return response()->json(['message' => 'Invalid teacher ID'], 400);
        }

        $teacher = DB::table('access_teacher as t')
            ->select([
                't.id',
                't.division',
                't.type',
                't.gender',
                't.user_id as user',
                't.organisation_id as organisation',
                'o.title as organisation_title',
                'u.username',
                'u.first_name',
                'u.last_name',
                'u.email',
                'u.date_joined'
            ])
            ->join('auth_user as u', 'u.id', '=', 't.user_id')
            ->join('organisation_organisation as o', 'o.id', '=', 't.organisation_id')
            ->where('t.id', $id)
            ->first();

        if (!$teacher) {
            return response()->json(['message' => 'Teacher not found'], 404);
        }

        // Fetch teacher divisions
        $teacherDivisions = [];
        if ($teacher && $teacher->division) {
            $teacherDivisions = array_map('trim', explode(',', $teacher->division));
            $teacherDivisions = array_filter(array_map('strtoupper', $teacherDivisions));
        }

        // Language Handling (similar to OrganisationGroupController)
        $langName = $request->get('lang', $request->get('language', $request->get('languages__name', 'English')));
        $language = DB::table('common_language')->where('name', 'ilike', $langName)->first();
        if (!$language) {
            $language = DB::table('common_language')->where('name', 'ilike', 'English')->first();
        }
        $language_id = $language ? $language->id : null;

        $groupColumn = 'group_id';
        if (strtolower($langName) === 'hindi') {
            $groupColumn = 'group_hindi_id';
        } elseif (strtolower($langName) === 'marathi') {
            $groupColumn = 'group_marathi_id';
        }

        // Fetch groups associated with the teacher
        $groups = DB::table('organisation_group as g')
            ->join('access_teacher_groups as atg', 'atg.group_id', '=', 'g.id')
            ->join('common_grade as gr', 'g.grade_id', '=', 'gr.id')
            ->join('common_level as lv', 'g.level_id', '=', 'lv.id')
            ->where('atg.teacher_id', $id)
            ->select('g.*', 'gr.level as grade_level', 'lv.rank as level_rank')
            ->orderBy('gr.level', 'asc')
            ->orderBy('lv.rank', 'asc')
            ->get();

        // Track if language filter was explicitly requested
        $isLangExplicit = $request->has('lang') || $request->has('language') || $request->has('languages__name');

        $groupDetails = [];
        $groupTitles = [];
        $groupIds = [];

        foreach ($groups as $group) {
            $groupIds[] = $group->id;
            $groupTitles[] = $group->title;

            // 1. Student Count - Conditionally filter by language association
            $scQuery = DB::table('access_student as s')
                ->where('s.' . $groupColumn, $group->id);

            if ($isLangExplicit && $language_id) {
                $scQuery->join('access_student_languages as asl', 'asl.student_id', '=', 's.id')
                    ->where('asl.language_id', $language_id);
            }

            // Show students based on division and if division is not empty, show students of that division and if division is empty, show all students
            if (!empty($teacherDivisions)) {
                $scQuery->where(function ($q) use ($teacherDivisions) {
                    $q->whereIn(DB::raw('UPPER(s.division)'), $teacherDivisions)
                        ->orWhereNull('s.division')
                        ->orWhere('s.division', '');
                });
            }

            $studentCount = $scQuery->count(DB::raw('DISTINCT s.id'));

            // 2. Teacher Count (Language-filtered unique teachers)
            $teacherCount = DB::table('access_teacher as t')
                ->join('access_teacher_groups as tg', 'tg.teacher_id', '=', 't.id')
                ->join('access_teacher_languages as tl', 'tl.teacher_id', '=', 't.id')
                ->where('tg.group_id', $group->id)
                ->where('tl.language_id', $language_id)
                ->count(DB::raw('DISTINCT t.id'));

            // 3. Current Average
            $avgQuery = DB::table('assessment_assessment as a')
                ->join('passage_passage as p', 'a.passage_id', '=', 'p.id')
                ->join('access_student as s', 'a.student_id', '=', 's.id')
                ->join('access_student_languages as sl', 's.id', '=', 'sl.student_id')
                ->where('a.group_id', $group->id)
                ->where('s.' . $groupColumn, $group->id)
                ->where('p.language_id', $language_id)
                ->where('sl.language_id', $language_id);

            if (!empty($teacherDivisions)) {
                $avgQuery->where(function ($q) use ($teacherDivisions) {
                    $q->whereIn(DB::raw('UPPER(s.division)'), $teacherDivisions)
                        ->orWhereNull('s.division')
                        ->orWhere('s.division', '');
                });
            }

            $avgCorrect = $avgQuery->avg(DB::raw('a.last_word_index + 1 - a.no_error_words'));

            $goal = $this->resolveBenchmarkGoal(
                $teacher->organisation,
                $language_id,
                $group->grade_id,
                $group->level_id,
                $request->input('assessment_period')
            );

            // 5. Last Activity
            $ltQuery = DB::table('assessment_assessment as a')
                ->join('passage_passage as p', 'a.passage_id', '=', 'p.id')
                ->join('access_student as s', 'a.student_id', '=', 's.id')
                ->where('p.language_id', $language_id)
                ->where(function ($q) use ($group, $groupColumn) {
                    $q->where('a.group_id', $group->id)
                        ->orWhere('s.' . $groupColumn, $group->id);
                });

            if (!empty($teacherDivisions)) {
                $ltQuery->where(function ($q) use ($teacherDivisions) {
                    $q->whereIn(DB::raw('UPPER(s.division)'), $teacherDivisions)
                        ->orWhereNull('s.division')
                        ->orWhere('s.division', '');
                });
            }

            $rawTime = $ltQuery->orderBy('a.created', 'desc')->value('a.created');

            $groupDetails[] = [
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
            ];
        }

        // Fetch Languages
        $languages = DB::table('access_teacher_languages as atl')
            ->join('common_language as cl', 'cl.id', '=', 'atl.language_id')
            ->where('atl.teacher_id', $id)
            ->pluck('cl.name');

        // Decrypt names
        $firstName = $teacher->first_name;
        $lastName = $teacher->last_name;

        try {
            if (!empty($firstName) && str_contains($firstName, 'eyJpdiI6')) {
                $firstName = Crypt::decryptString($firstName);
            }
            if (!empty($lastName) && str_contains($lastName, 'eyJpdiI6')) {
                $lastName = Crypt::decryptString($lastName);
            }
        } catch (\Exception $e) {
            Log::error("TeacherController: Error decrypting teacher names for ID {$teacher->id}: " . $e->getMessage());
        }

        $fullName = trim($firstName . ' ' . $lastName);

        return response()->json([
            'id' => $teacher->id,
            'group_details' => $groupDetails,
            'user_detail' => [
                'username' => $teacher->username,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'role_id' => $teacher->id,
                'full_name' => $fullName,
                'email' => $teacher->email,
                'role' => 'Teacher',
                'organisation' => [
                    'id' => $teacher->organisation,
                    'title' => $teacher->organisation_title
                ],
                'groups' => $groupTitles
            ],
            'organisation_title' => $teacher->organisation_title,
            'role' => 'Teacher',
            'languages' => $languages,
            'division' => $teacher->division,
            'type' => $teacher->type,
            'gender' => $teacher->gender,
            'user' => $teacher->user,
            'organisation' => $teacher->organisation,
            'groups' => $groupIds
        ], 200);
    }

    /**
     * Translate the group title based on the selected language.
     * Matches Python logic.
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
     */
    protected function sanitizeUuid(?string $val): ?string
    {
        if (!$val) return null;
        return preg_replace('/[^a-fA-F0-9-]/', '', $val);
    }
}
