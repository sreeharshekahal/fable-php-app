<?php

namespace App\Http\Controllers;

use App\Models\Organisation;
use App\Models\Teacher;
use App\Models\Student;
use App\Models\Grade;
use App\Models\Language;
use App\Models\OrganisationGroup;
use App\Models\Passage;
use App\Models\Checklist;
use App\Constants\TranslationConstants;
use App\Services\WordCategorizationService;
use App\Models\BenchmarkTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;
use Carbon\Carbon;

class OrganisationController extends Controller
{
    /**
     * List organisations.
     * Admins see all; Teachers see their own.
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        // Filter by language for counts
        $langName = $request->get('language', $request->get('languages__name', 'English'));

        $search = $request->get('search');

        $admin = DB::table('access_admin')->where('user_id', optional($user)->id)->first();

        if ($admin) {
            $query = Organisation::query();
        } else {
            $teacher = Teacher::where('user_id', optional($user)->id)->first();
            if (!$teacher) {
                return response()->json([
                    'count' => 0,
                    'next' => null,
                    'previous' => null,
                    'results' => []
                ]);
            }
            $query = Organisation::where('id', $teacher->organisation_id);
        }

        // Search/Filter
        if ($request->filled('title')) {
            $query->where('title', 'ILIKE', '%' . $request->input('title') . '%');
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ILIKE', '%' . $search . '%');
            });
        }

        if ($request->filled('languages__name')) {
            $filterLang = $request->input('languages__name');
            $query->whereHas('languages', function ($q) use ($filterLang) {
                $q->where('name', 'ILIKE', $filterLang);
            });
        }

        $totalCount = $query->count();
        $limit = $request->input('limit', 50);
        $offset = $request->input('offset', 0);

        $organisations = $query->orderBy('title')
            ->skip($offset)
            ->take($limit)
            ->get();

        $results = $organisations->map(function ($org) use ($langName) {
            return $this->formatOrganisation($org, $langName, true);
        });

        return response()->json([
            'count' => $totalCount,
            'next' => $this->getPaginationLink($request, $totalCount, $limit, $offset, true),
            'previous' => $this->getPaginationLink($request, $totalCount, $limit, $offset, false),
            'results' => $results
        ]);
    }

    /**
     * Create a new organisation (Admin only).
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        $admin = DB::table('access_admin')->where('user_id', $user->id)->first();

        if (!$admin) {
            return response()->json(['message' => 'User is not authorized to create an organisation'], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|unique:organisation_organisation,title',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors()
            ], 422);
        }

        $id = (string) Str::uuid();

        $createdByUuid = $admin->id;

        $org = Organisation::create([
            'id' => $id,
            'title' => $request->title,
            'description' => $request->description,
            'help_word_analysis' => $request->help_word_analysis ?? false,
            'created_by_id' => $createdByUuid,
            'created' => now(),
            'updated' => now(),
        ]);

        if ($request->has('languages')) {
            $langIds = Language::whereIn('name', $request->languages)->pluck('id');
            $org->languages()->sync($langIds);
        }

        if ($request->has('grades')) {
            $org->grades()->sync($request->grades);
            foreach ($request->grades as $gradeId) {
                $grade = Grade::find($gradeId);
                if ($grade) {
                    $org->createGroupsForGrade($grade);
                }
            }
        }

        return response()->json($this->formatOrganisation($org, 'English', true), 201);
    }

    /**
     * Full details including groups_details and grades_details.
     */
    public function show(Request $request, $id)
    {
        $org = Organisation::findOrFail($id);
        $langName = $request->get('language', $request->get('languages__name', 'English'));

        $data = $this->formatOrganisation($org, $langName, false);

        // groups_details
        $isAdminParam = $request->get('is_admin');
        $isAdmin = false;
        if ($isAdminParam !== null) {
            $decoded = json_decode($isAdminParam, true);
            $isAdmin = is_bool($decoded) ? $decoded : filter_var($isAdminParam, FILTER_VALIDATE_BOOLEAN);
        }

        $groupsQuery = $org->groups()->with(['grade', 'level']);
        if (!$isAdmin) {
            $groupsQuery->whereHas('grade', function ($q) {
                $q->where('level', '!=', 6);
            });
        }

        $groups = $groupsQuery->get();
        $formattedGroups = $groups->sort(function ($a, $b) {
            $isArchivedA = in_array($a->title, ['Archived', 'आर्काइव', 'संग्रहणालय']);
            $isArchivedB = in_array($b->title, ['Archived', 'आर्काइव', 'संग्रहणालय']);

            if ($isArchivedA !== $isArchivedB) {
                return $isArchivedA <=> $isArchivedB;
            }

            $gradeLevelA = optional($a->grade)->level ?? 0;
            $gradeLevelB = optional($b->grade)->level ?? 0;

            if ($gradeLevelA !== $gradeLevelB) {
                return $gradeLevelA <=> $gradeLevelB;
            }

            $levelRankA = optional($a->level)->rank ?? 0;
            $levelRankB = optional($b->level)->rank ?? 0;

            if ($levelRankA !== $levelRankB) {
                return $levelRankA <=> $levelRankB;
            }

            return strnatcasecmp($a->title, $b->title);
        })->map(function ($group) use ($langName, $request) {
            return $this->formatGroup($group, $langName, $request);
        })->values()->all();

        // grades_details
        $grades = $org->grades;

        $formattedGrades = $grades->map(function ($grade) {
            return [
                'id' => $grade->id,
                'created' => $this->formatDateTime($grade->created),
                'updated' => $this->formatDateTime($grade->updated),
                'title' => $grade->title,
                'description' => $grade->description,
                'level' => $grade->level,
            ];
        })->sortBy('level')->values()->all();

        // Reconstruct response in exact Python order
        $finalResponse = [
            'id' => $data['id'],
            'groups_count' => $data['groups_count'],
            'groups_details' => $formattedGroups,
            'grades_details' => $formattedGrades,
            'languages' => $data['languages'],
            'created' => $data['created'],
            'updated' => $data['updated'],
            'title' => $data['title'],
            'description' => $data['description'],
            'help_word_analysis' => $data['help_word_analysis'],
            'created_by' => $data['created_by'],
            'grades' => $data['grades'],
        ];

        return response()->json($finalResponse);
    }

    /**
     * Update metadata (title, languages).
     */
    public function update(Request $request, $id)
    {
        $org = Organisation::findOrFail($id);

        if ($request->has('title')) {
            $org->title = $request->title;
        }
        if ($request->has('description')) {
            $org->description = $request->description;
        }
        if ($request->has('help_word_analysis')) {
            $org->help_word_analysis = $request->help_word_analysis;
        }

        $org->updated = now();
        $org->save();

        if ($request->has('languages')) {
            $langIds = Language::whereIn('name', $request->languages)->pluck('id');
            $org->languages()->sync($langIds);
        }

        return response()->json($this->formatOrganisation($org, 'English', true));
    }

    /**
     * Remove organisation and its relations.
     */
    public function destroy($id)
    {
        $org = Organisation::findOrFail($id);

        DB::transaction(function () use ($org) {
            // Delete students and their related data
            foreach ($org->students as $student) {
                $student->languages()->detach();

                // Delete assessments and their related data
                foreach ($student->assessments as $assessment) {
                    $assessment->checklists()->detach();
                    $assessment->errorWords()->detach();
                    $assessment->delete();
                }

                if ($student->user) {
                    $student->user->delete();
                }
                $student->delete();
            }

            // Delete teachers and their related data
            foreach ($org->teachers as $teacher) {
                $teacher->languages()->detach();
                $teacher->groups()->detach();
                if ($teacher->user) {
                    $teacher->user->delete();
                }
                $teacher->delete();
            }

            // Delete groups
            $org->groups()->delete();

            // Detach languages and grades (pivot tables)
            $org->languages()->detach();
            $org->grades()->detach();

            // Delete the organisation itself
            $org->delete();
        });

        return response()->json("Deleted", 204);
    }

    /**
     * Helper to format organisation data.
     */
    public function formatOrganisation($org, $langName, $includeCounts = false)
    {
        $language = Language::where('name', 'ILIKE', $langName)->first();
        $languageId = $language ? $language->id : null;

        $teacherCount = 0;
        if ($includeCounts) {
            $teachersQuery = $org->teachers();
            if ($languageId) {
                $teachersQuery->whereHas('languages', function ($q) use ($languageId) {
                    $q->where('language_id', $languageId);
                });
            }
            $teacherCount = $teachersQuery->count();
        }

        $studentCount = 0;
        if ($includeCounts) {
            $studentsQuery = $org->students();
            if ($languageId) {
                $studentsQuery->whereHas('languages', function ($q) use ($languageId) {
                    $q->where('language_id', $languageId);
                });
            }
            $studentCount = $studentsQuery->count();
        }

        $data = [
            'id' => $org->id,
            'groups_count' => $org->groups()->count(),
        ];

        if ($includeCounts) {
            $data['teachers_count'] = $teacherCount;
            $data['students_count'] = $studentCount;
            $data['users_count'] = $teacherCount + $studentCount;
        }

        $data = array_merge($data, [
            'languages' => $org->languages->pluck('name')->toArray(),
            'created' => $this->formatDateTime($org->created),
            'updated' => $this->formatDateTime($org->updated),
            'title' => $org->title,
            'description' => $org->description,
            'help_word_analysis' => (bool) $org->help_word_analysis,
            'created_by' => $org->created_by ? $org->created_by : $org->created_by_id,
            'grades' => $org->grades->pluck('id')->toArray(),
        ]);

        return $data;
    }

    /**
     * Helper to format group data (simplified for organisation details).
     */
    protected function formatGroup($group, $langName, $request)
    {
        $language = Language::where('name', 'ILIKE', $langName)->first();
        $languageId = $language ? $language->id : null;

        $groupField = 'access_student.group_id';
        if (strcasecmp($langName, 'Marathi') === 0) {
            $groupField = 'access_student.group_marathi_id';
        } elseif (strcasecmp($langName, 'Hindi') === 0) {
            $groupField = 'access_student.group_hindi_id';
        }

        $studentCount = DB::table('access_student')
            ->join('access_student_languages', 'access_student_languages.student_id', '=', 'access_student.id')
            ->where($groupField, $group->id)
            ->where('access_student_languages.language_id', $languageId)
            ->count();

        $teacherCount = DB::table('access_teacher')
            ->join('access_teacher_groups', 'access_teacher_groups.teacher_id', '=', 'access_teacher.id')
            ->join('access_teacher_languages', 'access_teacher_languages.teacher_id', '=', 'access_teacher.id')
            ->where('access_teacher_groups.group_id', $group->id)
            ->where('access_teacher_languages.language_id', $languageId)
            ->count(DB::raw('DISTINCT access_teacher.id'));

        $goal = $this->resolveBenchmarkGoal(
            $group->organisation_id,
            $languageId,
            $group->grade_id,
            $group->level_id,
            $request->input('assessment_period')
        );

        $currentAverage = DB::table('assessment_assessment as a')
            ->join('passage_passage as p', 'a.passage_id', '=', 'p.id')
            ->join('access_student as s', 'a.student_id', '=', 's.id')
            ->join('access_student_languages as sl', 's.id', '=', 'sl.student_id')
            ->where('a.group_id', $group->id)
            ->where('s.group_id', $group->id)
            ->where('p.language_id', $languageId)
            ->where('sl.language_id', $languageId)
            ->avg(DB::raw('a.last_word_index + 1 - a.no_error_words'));

        $rawTime = DB::table('assessment_assessment as a')
            ->join('passage_passage as p', 'a.passage_id', '=', 'p.id')
            ->where('a.group_id', $group->id)
            ->where('p.language_id', $languageId)
            ->orderBy('a.created', 'desc')
            ->value('a.created');

        return [
            'id' => $group->id,
            'teachers_count' => (int) $teacherCount,
            'students_count' => (int) $studentCount,
            'last_assessment_time' => $rawTime ? $this->formatAssessmentTime($rawTime) : null,
            'goal' => $goal ?? 0,
            'current_average' => (float) ($currentAverage ?? 0),
            'created' => $this->formatDateTime($group->created),
            'updated' => $this->formatDateTime($group->updated),
            'title' => $this->translateGroupTitle($group->title, $langName),
            'description' => $group->description,
            'organisation' => $group->organisation_id,
            'grade' => $group->grade_id,
            'level' => $group->level_id,
        ];
    }

    protected function translateGroupTitle($title, $langName)
    {
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
     * Critical mobile sync endpoint. Returns a snapshot of groups, grades, students, passages, and checklists.
     * Matches Python: fable/organisation/views.py:offline_data
     */
    public function offlineData(Request $request)
    {
        $user = auth()->user();

        // Match user requirement: if no teacher_id received, fetch from parameter
        $teacherId = $request->get('teacher_id') ?: $request->get('teacher');

        if ($teacherId) {
            $teacher = Teacher::find($teacherId);
        } else {
            $teacher = Teacher::where('user_id', optional($user)->id)->first();
        }

        if (!$teacher) {
            return response()->json(['message' => 'Teacher profile not found'], 404);
        }

        $organisation = $teacher->organisation;
        if (!$organisation) {
            return response()->json(['message' => 'Organisation not found'], 404);
        }

        $studentId = $request->get('student'); // Handle student parameter from curl
        $type = $request->filled('type') ? $request->get('type') : null;

        $wordService = app(WordCategorizationService::class);
        $languages = ['Marathi', 'Hindi', 'English'];
        $langModels = Language::whereIn('name', $languages)->get()->keyBy('name');

        // 1. Students (Entire organisation)
        $students = $organisation->students()
            ->select('access_student.*')
            ->join('auth_user', 'access_student.user_id', '=', 'auth_user.id')
            ->orderBy('auth_user.first_name', 'asc')
            ->with(['user', 'languages', 'grade', 'organisation', 'group'])
            ->get()
            ->map(fn($s) => $this->formatOfflineStudent($s, $type))
            ->values();

        // 2. Passages (All)
        $passages = Passage::with(['language', 'grade'])
            ->join('common_grade', 'passage_passage.grade_id', '=', 'common_grade.id')
            ->orderBy('common_grade.level')
            ->orderBy('passage_passage.number')
            ->select('passage_passage.*')
            ->get()
            ->map(fn($p) => $this->formatOfflinePassage($p, $wordService, $studentId, $type));

        // 3. Checklists
        $checklistFluency = Checklist::with('language')->where('type', 0)->get()
            ->map(fn($c) => $this->formatOfflineChecklist($c));
        $checklistRetell = Checklist::with('language')->where('type', 1)->get()
            ->map(fn($c) => $this->formatOfflineChecklist($c));

        $finalData = [
            'groups' => ['Marathi' => [], 'Hindi' => [], 'English' => []],
            'grades' => ['Marathi' => [], 'Hindi' => [], 'English' => []],
            'students' => $students,
            'passages' => $passages,
            'checklist_fluency' => $checklistFluency,
            'checklist_retell' => $checklistRetell,
        ];

        // 4. Grades of the organisation
        $grades = $organisation->grades()->get()->map(function ($grade) {
            return [
                'id' => $grade->id,
                'created' => $this->formatDateTime($grade->created),
                'updated' => $this->formatDateTime($grade->updated),
                'title' => $grade->title,
                'description' => $grade->description,
                'level' => $grade->level,
            ];
        })->sort(function ($a, $b) {
            $isArchivedA = in_array($a['title'], ['Archived', 'आर्काइव', 'संग्रहणालय']);
            $isArchivedB = in_array($b['title'], ['Archived', 'आर्काइव', 'संग्रहणालय']);

            if ($isArchivedA !== $isArchivedB) {
                return $isArchivedA <=> $isArchivedB;
            }

            return strnatcasecmp($a['title'], $b['title']);
        })->values();

        foreach ($languages as $langName) {
            $langId = isset($langModels[$langName]) ? $langModels[$langName]->id : null;

            // Fetch groups for this language (excluding level 6)
            $groups = $organisation->groups()->with(['grade', 'level'])->whereHas('grade', function ($q) {
                $q->where('level', '!=', 6);
            })->get()->sort(function ($a, $b) {
                $isArchivedA = in_array($a->title, ['Archived', 'आर्काइव', 'संग्रहणालय']);
                $isArchivedB = in_array($b->title, ['Archived', 'आर्काइव', 'संग्रहणालय']);

                if ($isArchivedA !== $isArchivedB) {
                    return $isArchivedA <=> $isArchivedB;
                }

                $gradeLevelA = optional($a->grade)->level ?? 0;
                $gradeLevelB = optional($b->grade)->level ?? 0;

                if ($gradeLevelA !== $gradeLevelB) {
                    return $gradeLevelA <=> $gradeLevelB;
                }

                $levelRankA = optional($a->level)->rank ?? 0;
                $levelRankB = optional($b->level)->rank ?? 0;

                if ($levelRankA !== $levelRankB) {
                    return $levelRankA <=> $levelRankB;
                }

                return strnatcasecmp($a->title, $b->title);
            })->map(function ($group) use ($langName, $request) {
                return $this->formatGroup($group, $langName, $request);
            })->values()->all();

            $finalData['groups'][$langName] = $groups;

            // Translated grades
            $finalData['grades'][$langName] = $grades->map(function ($grade) use ($langName) {
                $newGrade = $grade;
                $newGrade['title'] = $this->translateGroupTitle($grade['title'], $langName);
                return $newGrade;
            })->values()->all();
        }

        return response()->json($finalData, 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    protected function formatOfflineStudent(Student $student, $type = null)
    {
        $firstName = $student->user->first_name;
        $lastName = $student->user->last_name;
        try {
            if ($firstName && (str_contains($firstName, 'eyJpdiI6') || strlen($firstName) > 40)) $firstName = Crypt::decryptString($firstName);
            if ($lastName && (str_contains($lastName, 'eyJpdiI6') || strlen($lastName) > 40)) $lastName = Crypt::decryptString($lastName);
        } catch (\Exception $e) {
        }

        $assessmentsQuery = $student->assessments();
        if ($type !== null) {
            $assessmentsQuery->where('type', $type);
        }

        $langName = request()->get('language', request()->get('languages__name', 'English'));
        $groupDetail = null;
        if (strcasecmp($langName, 'Marathi') === 0 && $student->group_marathi) {
            $groupDetail = $this->formatGroup($student->group_marathi, $langName, request());
        } elseif (strcasecmp($langName, 'Hindi') === 0 && $student->group_hindi) {
            $groupDetail = $this->formatGroup($student->group_hindi, $langName, request());
        } elseif ($student->group) {
            $groupDetail = $this->formatGroup($student->group, $langName, request());
        }

        return [
            'id' => $student->id,
            'group_detail' => $groupDetail,
            'user_detail' => [
                'username' => $student->user->username,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'role_id' => $student->id,
                'full_name' => trim($firstName . ' ' . $lastName),
                'email' => $student->user->email ?? '',
                'role' => 'Student',
                'organisation' => [
                    'id' => $student->organisation_id,
                    'title' => $student->organisation?->title
                ],
                'groups' => $student->group?->title ?? ''
            ],
            'organisation_title' => $student->organisation?->title,
            'grade_title' => $student->grade?->title,
            'role' => 'Student',
            'last_assessment_time' => $this->formatLastAssessmentTime(clone $assessmentsQuery),
            'is_assessed' => (clone $assessmentsQuery)->exists(),
            'languages' => $student->languages->pluck('name')->toArray(),
            'division' => $student->division,
            'date_of_birth' => $student->date_of_birth,
            'gender' => $student->gender !== null ? (int)$student->gender : null,
            'is_english_second_language' => (bool)$student->is_english_second_language,
            'disability' => (bool)$student->disability,
            'picture' => $student->picture === '' ? null : ($student->picture ?? null),
            'user' => $student->user_id,
            'organisation' => $student->organisation_id,
            'grade' => $student->grade_id,
            'group' => $student->group_id,
            'group_hindi' => $student->group_hindi_id,
            'group_marathi' => $student->group_marathi_id,
        ];
    }

    protected function formatOfflinePassage(Passage $passage, $wordService, $studentId = null, $type = null)
    {
        $langName = $passage->language->name;
        $words = $wordService->getWordsFromPassage($passage->raw_content, $langName);
        $uniqueWords = array_unique($words);

        $assessmentsQuery = $passage->assessments();
        if ($type !== null) {
            $assessmentsQuery->where('type', $type);
        }

        $isCurrentStudentAssessed = null;
        if ($studentId) {
            $isCurrentStudentAssessed = (clone $assessmentsQuery)->where('student_id', $studentId)->exists();
        }

        return [
            'id' => $passage->id,
            'word_count' => count($words),
            'grade_no' => (int) ($passage->grade?->level ?? 0),
            'is_assessed' => (clone $assessmentsQuery)->exists(),
            'no_unique_words' => count($uniqueWords),
            'passage_no' => ($passage->grade?->level ?? '0') . '-' . $passage->number,
            'is_current_student_assessed' => $isCurrentStudentAssessed,
            'raw_content' => $passage->raw_content,
            'language' => $langName,
            'created' => $this->formatDateTime($passage->created),
            'updated' => $this->formatDateTime($passage->updated),
            'passage_name' => $passage->passage_name,
            'content' => $passage->content ?? $passage->raw_content,
            'number' => $passage->number,
            'readability_score' => (float) ($passage->readability_score ?? 0),
            'grade' => $passage->grade_id,
            'created_by' => $passage->created_by_id,
        ];
    }

    protected function formatOfflineChecklist(Checklist $checklist)
    {
        return [
            'id' => $checklist->id,
            'selected' => false,
            'language' => $checklist->language->name,
            'created' => $this->formatDateTime($checklist->created),
            'updated' => $this->formatDateTime($checklist->updated),
            'title' => $checklist->title,
            'type' => $checklist->type,
        ];
    }

    protected function formatLastAssessmentTime($assessmentsQuery)
    {
        $assessment = (clone $assessmentsQuery)->orderBy('created', 'desc')->first();
        return $assessment ? $this->formatAssessmentTime($assessment->created) : null;
    }

    protected function getPaginationLink(Request $request, $total, $limit, $offset, $isNext)
    {
        if ($isNext) {
            if ($offset + $limit >= $total) return null;
            return $request->fullUrlWithQuery(['offset' => $offset + $limit, 'limit' => $limit]);
        } else {
            if ($offset <= 0) return null;
            $prevOffset = max(0, $offset - $limit);
            return $request->fullUrlWithQuery(['offset' => $prevOffset, 'limit' => $limit]);
        }
    }
}
