<?php

namespace App\Http\Controllers;

use App\Constants\TranslationConstants;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\BenchmarkTemplate;

/**
 * Class OrganisationGroupController
 *
 * Handles organisation group metrics
 * with align to Python reference implementations.
 *
 * @package App\Http\Controllers
 */
class OrganisationGroupController extends Controller
{
    /**
     * Get groups for a specific organisation with detailed metrics.
     *
     * Matches Python logic for:
     * - Teachers count (language filtered)
     * - Current average (strict benchmarking: language + primary group matching)
     * - Last assessment time (inclusive activity: student group or assessment group)
     *
     * @param Request $request
     * @param string $id Organisation ID (UUID)
     * @return \Illuminate\Http\JsonResponse
     */
    public function getOrganisationGroups(Request $request, $id)
    {
        $org_id = $this->sanitizeUuid($id);
        // Handle is_admin parameter
        $isAdminParam = $request->get('is_admin');
        $isAdmin = false;
        if ($isAdminParam !== null) {
            // Handle both literal booleans and JSON-encoded strings as in Python reference
            $decoded = json_decode($isAdminParam, true);
            $isAdmin = is_bool($decoded) ? $decoded : filter_var($isAdminParam, FILTER_VALIDATE_BOOLEAN);
        }

        $ordering = $request->get('ordering', 'title');
        $limit = $request->input('limit', 50);
        $offset = (int) $request->input('offset', 0);

        // Language Handling
        if ($request->has('lang')) {
            $langName = $request->get('lang');
        } elseif ($request->has('language')) {
            $langName = $request->get('language');
        } elseif ($request->has('languages__name')) {
            $langName = $request->get('languages__name');
        } else {
            $langName = 'English';
        }

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

        // Filters: Division & Teacher
        if ($request->has('teachers_id')) {
            $rawTeacher = $request->get('teachers_id');
        } elseif ($request->has('teacher_id')) {
            $rawTeacher = $request->get('teacher_id');
        } else {
            $rawTeacher = $request->get('teachers__id');
        }
        $teacher_id = $this->sanitizeUuid($rawTeacher);

        if ($request->has('division')) {
            $division = $request->get('division');
        } elseif ($request->has('division__name')) {
            $division = $request->get('division__name');
        } else {
            $division = '';
        }

        // Fetch teacher divisions for parity with /students API
        $teacherDivisions = [];
        if ($teacher_id) {
            $teacher = DB::table('access_teacher')->where('id', $teacher_id)->first();
            if ($teacher && $teacher->division) {
                // Normalize teacher divisions to uppercase for robust matching
                $teacherDivisions = array_map('trim', explode(',', $teacher->division));
                $teacherDivisions = array_filter(array_map('strtoupper', $teacherDivisions));
            }
        }

        // Base Query
        $query = DB::table('organisation_group')
            ->join('common_grade', 'organisation_group.grade_id', '=', 'common_grade.id')
            ->where('organisation_group.organisation_id', $org_id);

        // Grade Filtering (exclude Grade 6 for non-admins)
        if (!$isAdmin) {
            $query->where('common_grade.level', '!=', 6);
        }

        // Teacher Membership Filter
        if ($teacher_id) {
            $query->whereExists(function ($q) use ($teacher_id) {
                $q->select(DB::raw(1))
                    ->from('access_teacher_groups as tg')
                    ->whereColumn('tg.group_id', 'organisation_group.id')
                    ->where('tg.teacher_id', $teacher_id);
            });
        }

        // Organisation Language Filter
        $orgLang = $request->get('organisation__languages__name');
        if ($orgLang) {
            $query->whereExists(function ($q) use ($orgLang) {
                $q->select(DB::raw(1))
                    ->from('organisation_organisation_languages as ool')
                    ->join('common_language as cl', 'ool.language_id', '=', 'cl.id')
                    ->whereColumn('ool.organisation_id', 'organisation_group.organisation_id')
                    ->where('cl.name', 'ILIKE', $orgLang);
            });
        }


        // Track if language filter was explicitly requested
        $isLangExplicit = $request->has('lang') || $request->has('language') || $request->has('languages__name');

        // Sorting Logic
        $direction = 'asc';
        if (str_starts_with($ordering, '-')) {
            $direction = 'desc';
            $ordering = ltrim($ordering, '-');
        }

        // Primary Sort: Archived groups to the end
        $query->orderBy(DB::raw("(CASE WHEN organisation_group.title = 'Archived' THEN 1 ELSE 0 END)"), 'asc');

        // Secondary Sort: Grade Level (Grade-wise grouping requirement)
        $query->orderBy('common_grade.level', $direction);

        if ($ordering === 'level__rank') {
            $query->join('common_level', 'organisation_group.level_id', '=', 'common_level.id')
                ->orderBy('common_level.rank', $direction);
        } elseif ($ordering !== 'grade__level') {
            $column = in_array($ordering, ['id', 'title', 'created', 'updated']) ? $ordering : 'title';
            if ($column === 'title') {
                $query->orderBy(DB::raw('LENGTH(organisation_group.title)'), $direction);
            }
            $query->orderBy('organisation_group.' . $column, $direction);
        }

        $totalCount = $query->count();

        if ($limit) {
            $query->skip($offset)->take($limit);
        }

        $groups = $query->select('organisation_group.*')->get();
        $resultArr = [];

        foreach ($groups as $group) {
            $group_id = $group->id;

            // 1. Student Count - Conditionally filter by language association
            $scQuery = DB::table('access_student as s')
                ->where('s.' . $groupColumn, $group_id);

            if ($isLangExplicit && $language_id) {
                $scQuery->join('access_student_languages as asl', 'asl.student_id', '=', 's.id')
                    ->where('asl.language_id', $language_id);
            }

            // Filtering for parity with /students API
            if ($division) {
                $scQuery->where(function ($q) use ($division) {
                    $divs = is_array($division) ? $division : explode(',', $division);
                    foreach ($divs as $div) {
                        $q->orWhere('s.division', 'ILIKE', '%' . trim($div) . '%');
                    }
                });
            }

            if ($teacher_id && !empty($teacherDivisions)) {
                $scQuery->where(function ($q) use ($teacherDivisions) {
                    $q->whereIn(DB::raw('UPPER(s.division)'), $teacherDivisions)
                        ->orWhereNull('s.division')
                        ->orWhere('s.division', '');
                });
            }

            $studentCount = $scQuery->count(DB::raw('DISTINCT s.id'));

            // 2. Teacher Count - Conditionally filter by language association
            $tcQuery = DB::table('access_teacher as t')
                ->join('access_teacher_groups as tg', 'tg.teacher_id', '=', 't.id')
                ->where('tg.group_id', $group_id);

            if ($isLangExplicit && $language_id) {
                $tcQuery->join('access_teacher_languages as tl', 'tl.teacher_id', '=', 't.id')
                    ->where('tl.language_id', $language_id);
            }

            $teacherCount = $tcQuery->count(DB::raw('DISTINCT t.id'));

            // 3. Average - Strict benchmarking: language + primary group matching
            $avgQuery = DB::table('assessment_assessment as a')
                ->join('passage_passage as p', 'a.passage_id', '=', 'p.id')
                ->join('access_student as s', 'a.student_id', '=', 's.id')
                ->join('access_student_languages as sl', 's.id', '=', 'sl.student_id')
                ->where('a.group_id', $group_id)
                ->where('s.' . $groupColumn, $group_id)
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

            // Teacher Filtering for parity with /students API
            if ($teacher_id && !empty($teacherDivisions)) {
                $avgQuery->where(function ($q) use ($teacherDivisions) {
                    $q->whereIn(DB::raw('UPPER(s.division)'), $teacherDivisions)
                        ->orWhereNull('s.division')
                        ->orWhere('s.division', '');
                });
            }

            $avgCorrect = $avgQuery->avg(DB::raw('a.last_word_index + 1 - a.no_error_words'));

            $goal = $this->resolveBenchmarkGoal(
                $org_id,
                $language_id,
                $group->grade_id,
                $group->level_id,
                $request->input('assessment_period')
            );

            // 5. Last Activity - Inclusive activity: student group or assessment group
            $ltQuery = DB::table('assessment_assessment as a')
                ->join('passage_passage as p', 'a.passage_id', '=', 'p.id')
                ->join('access_student as s', 'a.student_id', '=', 's.id')
                ->where('p.language_id', $language_id)
                ->where(function ($q) use ($group_id, $groupColumn) {
                    $q->where('a.group_id', $group_id)
                        ->orWhere('s.' . $groupColumn, $group_id);
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

            $resultArr[] = [
                'id'                   => $group->id,
                'teachers_count'       => (int) $teacherCount,
                'students_count'       => (int) $studentCount,
                'last_assessment_time' => $rawTime ? $this->formatAssessmentTime($rawTime) : null,
                'goal'                 => $goal ?? 0,
                'current_average'      => (float) ($avgCorrect ?? 0),
                'created'              => $group->created ? $this->formatDateTime($group->created) : null,
                'updated'              => $group->updated ? $this->formatDateTime($group->updated) : null,
                'title'                => $this->translateTitle($group->title, $langName),
                'description'          => $group->description ?? null,
                'organisation'         => $group->organisation_id,
                'grade'                => $group->grade_id,
                'level'                => $group->level_id
            ];
        }

        // Pagination Links (Django LimitOffsetPagination style)
        $next = null;
        if ($offset + $limit < $totalCount) {
            $next = $request->fullUrlWithQuery(['limit' => $limit, 'offset' => $offset + $limit]);
        }

        $previous = null;
        if ($offset > 0) {
            $prevOffset = max(0, $offset - $limit);
            if ($prevOffset === 0) {
                // DRF omits offset=0 in previous link usually, or keeps it.
                // We'll keep it for consistency or omit if we want to be exact.
                $previous = $request->fullUrlWithQuery(['limit' => $limit, 'offset' => null]);
                $previous = rtrim(str_replace('offset=', '', $previous), '?&');
            } else {
                $previous = $request->fullUrlWithQuery(['limit' => $limit, 'offset' => $prevOffset]);
            }
        }

        $paginated = [
            'count'    => (int) $totalCount,
            'next'     => $next,
            'previous' => $previous,
            'results'  => $resultArr
        ];

        return response()->json($paginated, 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Translate the group title based on the selected language.
     *
     * Logic matches Python's to_representation hook:
     * 1. Splits by first space (e.g. "G1 Benchmarking" -> ["G1", "Benchmarking"])
     * 2. Translates each component using the mapping dictionary.
     *
     * @param string $title
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
