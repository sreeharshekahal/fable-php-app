<?php

namespace App\Http\Controllers;

use App\Constants\TranslationConstants;
use App\Models\BenchmarkTemplate;
use App\Models\UserModel;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function me(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json("User does not exists", 404);
        }

        $admin = DB::table('access_admin')->where('user_id', $user->id)->first();
        if ($admin) {
            $firstName = $user->first_name;
            $lastName = $user->last_name;
            try { if ($firstName && str_contains($firstName, 'eyJpdiI6')) $firstName = Crypt::decryptString($firstName); } catch (\Exception $e) {}
            try { if ($lastName && str_contains($lastName, 'eyJpdiI6')) $lastName = Crypt::decryptString($lastName); } catch (\Exception $e) {}

            return response()->json([
                'id' => $admin->id,
                'user_detail' => [
                    'username' => $user->username,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'full_name' => trim($firstName . ' ' . $lastName),
                    'email' => $user->email,
                    'role' => 'Admin',
                    'role_id' => $user->id,
                    'organisation' => 'Administration',
                    'groups' => 'Administration'
                ],
                'role' => 'Admin',
                'user' => $user->id
            ], 200);
        }

        $teacher = DB::table('access_teacher')
            ->leftJoin('organisation_organisation as o', 'o.id', '=', 'access_teacher.organisation_id')
            ->select('access_teacher.*', 'o.title as organisation_title')
            ->where('access_teacher.user_id', $user->id)
            ->first();

        if ($teacher) {
            $firstName = $user->first_name;
            $lastName = $user->last_name;
            try { if ($firstName && str_contains($firstName, 'eyJpdiI6')) $firstName = Crypt::decryptString($firstName); } catch (\Exception $e) {}
            try { if ($lastName && str_contains($lastName, 'eyJpdiI6')) $lastName = Crypt::decryptString($lastName); } catch (\Exception $e) {}

            $userDetail = [
                'username' => $user->username,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'role_id' => $teacher->id,
                'full_name' => trim($firstName . ' ' . $lastName),
                'email' => $user->email,
                'role' => 'Teacher',
                'organisation' => [
                    'id' => $teacher->organisation_id,
                    'title' => $teacher->organisation_title
                ]
            ];

            $langName = $request->query('language', $request->query('lang', $request->query('languages__name', 'English')));
            $language = DB::table('common_language')->where('name', 'ilike', $langName)->first();
            $languageId = $language ? $language->id : DB::table('common_language')->where('name', 'ilike', 'English')->value('id');

            $groupDetails = [];
            $groups = DB::table('access_teacher_groups')
                ->join('organisation_group', 'organisation_group.id', '=', 'access_teacher_groups.group_id')
                ->join('common_grade', 'common_grade.id', '=', 'organisation_group.grade_id')
                ->join('common_level', 'common_level.id', '=', 'organisation_group.level_id')
                ->where('access_teacher_groups.teacher_id', $teacher->id)
                ->select('organisation_group.*')
                ->orderBy('common_grade.level', 'asc')
                ->orderBy('common_level.rank', 'asc')
                ->get();

            $groupTitles = [];
            $groupIds = [];

            foreach ($groups as $groupRaw) {
                $groupIds[] = $groupRaw->id;
                $groupTitle = $this->translateTitle($groupRaw->title, $langName);
                $groupTitles[] = $groupTitle;

                $goal = $this->resolveBenchmarkGoal(
                    $teacher->organisation_id,
                    $languageId,
                    $groupRaw->grade_id,
                    $groupRaw->level_id,
                    $request->input('assessment_period')
                );

                $groupColumn = 'group_id';
                if (strtolower($langName) === 'hindi') $groupColumn = 'group_hindi_id';
                elseif (strtolower($langName) === 'marathi') $groupColumn = 'group_marathi_id';

                $avgQuery = DB::table('assessment_assessment as a')
                    ->join('passage_passage as p', 'a.passage_id', '=', 'p.id')
                    ->join('access_student as s', 'a.student_id', '=', 's.id')
                    ->join('access_student_languages as sl', 's.id', '=', 'sl.student_id')
                    ->where('a.group_id', $groupRaw->id)
                    ->where('s.' . $groupColumn, $groupRaw->id)
                    ->where('sl.language_id', $languageId)
                    ->where('p.language_id', $languageId);

                $avgCorrect = $avgQuery->avg(DB::raw('a.last_word_index + 1 - a.no_error_words'));

                $lastAssessmentTime = DB::table('assessment_assessment as a')
                    ->join('passage_passage as p', 'a.passage_id', '=', 'p.id')
                    ->where('a.group_id', $groupRaw->id)
                    ->where('p.language_id', $languageId)
                    ->orderByDesc('a.created')
                    ->value('a.created');

                $tcQuery = DB::table('access_teacher as t')
                    ->join('access_teacher_groups as atg', 'atg.teacher_id', '=', 't.id')
                    ->where('atg.group_id', $groupRaw->id);
                if ($languageId) {
                    $tcQuery->join('access_teacher_languages as atl', 'atl.teacher_id', '=', 't.id')
                        ->where('atl.language_id', $languageId);
                }
                $teachersCountInGroup = $tcQuery->count(DB::raw('DISTINCT t.id'));

                $studentsCountQuery = DB::table('access_student as s')
                    ->where('s.' . $groupColumn, $groupRaw->id);
                if ($languageId) {
                    $studentsCountQuery->join('access_student_languages as asl', 'asl.student_id', '=', 's.id')
                        ->where('asl.language_id', $languageId);
                }
                $studentsCountInGroup = $studentsCountQuery->count(DB::raw('DISTINCT s.id'));

                $groupDetails[] = [
                    'id' => $groupRaw->id,
                    'teachers_count' => (int) $teachersCountInGroup,
                    'students_count' => (int) $studentsCountInGroup,
                    'last_assessment_time' => $lastAssessmentTime ? $this->formatAssessmentTime($lastAssessmentTime) : null,
                    'goal' => (int) ($goal ?? 0),
                    'current_average' => (float) ($avgCorrect ?? 0),
                    'created' => $groupRaw->created ? $this->formatDateTime($groupRaw->created) : null,
                    'updated' => $groupRaw->updated ? $this->formatDateTime($groupRaw->updated) : null,
                    'title' => $groupTitle,
                    'description' => $groupRaw->description,
                    'organisation' => $groupRaw->organisation_id,
                    'grade' => $groupRaw->grade_id,
                    'level' => $groupRaw->level_id,
                ];
            }
            $userDetail['groups'] = $groupTitles;

            $languages = DB::table('access_teacher_languages as atl')
                ->join('common_language as cl', 'cl.id', '=', 'atl.language_id')
                ->where('atl.teacher_id', $teacher->id)
                ->pluck('cl.name');

            return response()->json([
                'id' => $teacher->id,
                'group_details' => $groupDetails,
                'user_detail' => $userDetail,
                'organisation_title' => $teacher->organisation_title,
                'role' => 'Teacher',
                'languages' => $languages,
                'division' => $teacher->division,
                'type' => $teacher->type,
                'gender' => $teacher->gender,
                'user' => $user->id,
                'organisation' => $teacher->organisation_id,
                'groups' => $groupIds
            ], 200);
        }

        return response()->json("User not Authorized", 404);
    }

    public function getUsers(Request $request)
    {
        $limit = $request->input('limit', 50);
        $offset = $request->input('offset', 0);
        $search = $request->input('search');
        $role = $request->input('role');
        $organisationId = $request->input('organisation__id');
        $ordering = $request->input('ordering');

        // Querying access_student table (Student role).
        $studentsQuery = DB::table('access_student')
            ->join('auth_user', 'access_student.user_id', '=', 'auth_user.id')
            ->leftJoin('organisation_organisation', 'access_student.organisation_id', '=', 'organisation_organisation.id')
            ->leftJoin('organisation_group', 'access_student.group_id', '=', 'organisation_group.id')
            ->select(
                'access_student.id as role_id',
                'auth_user.id as user_id',
                'auth_user.username',
                'auth_user.first_name',
                'auth_user.last_name',
                'auth_user.email',
                'organisation_organisation.id as org_id',
                'organisation_organisation.title as org_title',
                'organisation_group.title as group_title',
                DB::raw("'Student' as role")
            );

        $teachersQuery = DB::table('access_teacher')
            ->join('auth_user', 'access_teacher.user_id', '=', 'auth_user.id')
            ->leftJoin('organisation_organisation', 'access_teacher.organisation_id', '=', 'organisation_organisation.id')
            ->select(
                'access_teacher.id as role_id',
                'auth_user.id as user_id',
                'auth_user.username',
                'auth_user.first_name',
                'auth_user.last_name',
                'auth_user.email',
                'organisation_organisation.id as org_id',
                'organisation_organisation.title as org_title',
                DB::raw("NULL as group_title"),
                DB::raw("'Teacher' as role")
            );

        // Filter by Organisation
        if ($organisationId) {
            $studentsQuery->where('access_student.organisation_id', $organisationId);
            $teachersQuery->where('access_teacher.organisation_id', $organisationId);
        }

        // Filter by Role (Student or Teacher)
        if ($role) {
            if (strtolower($role) === 'student') {
                $usersQuery = $studentsQuery;
            } elseif (strtolower($role) === 'teacher') {
                $usersQuery = $teachersQuery;
            } else {
                return response()->json([
                    'count' => 0,
                    'next' => null,
                    'previous' => null,
                    'results' => [],
                    'message' => "Successfully fetched users!",
                ], 200);
            }
        } else {
            $usersQuery = $studentsQuery->union($teachersQuery);
        }

        $hasExplicitOrdering = $request->has('ordering');

        // Ordering
        $direction = 'asc';
        if ($ordering) {
            if (str_starts_with($ordering, '-')) {
                $direction = 'desc';
                $ordering = ltrim($ordering, '-');
            }

            $validInMemory = ['first_name', 'last_name', 'username', 'group_title', 'title'];
            $validSql = ['role', 'org_title', 'email', 'role_id', 'user_id'];

            if (in_array($ordering, $validInMemory)) {
                // These require in-memory sorting
            } elseif (in_array($ordering, $validSql)) {
                $usersQuery->orderBy($ordering, $direction);
            } else {
                $usersQuery->orderBy('user_id', 'asc');
                $ordering = 'user_id';
            }
        } else {
            // Default ordering by user_id in SQL for high-performance SQL pagination
            $usersQuery->orderBy('user_id', 'asc');
        }

        // In-memory processing for encrypted search/sort or natural sort requirements.
        $needsInMemoryProcessing = (!empty($search) || ($hasExplicitOrdering && in_array($ordering, ['first_name', 'last_name', 'username', 'group_title', 'title'])));

        if ($needsInMemoryProcessing) {
            $allCandidates = $usersQuery->get(); // Get all matching org/role

            $processed = $allCandidates->map(function ($user) {
                try {
                    $user->first_name_decrypted = Crypt::decryptString($user->first_name);
                } catch (\Exception $e) {
                    $user->first_name_decrypted = $user->first_name;
                }

                try {
                    $user->last_name_decrypted = Crypt::decryptString($user->last_name);
                } catch (\Exception $e) {
                    $user->last_name_decrypted = $user->last_name;
                }

                return $user;
            });

            // Filter by Search
            if ($search) {
                $searchLower = strtolower($search);
                $processed = $processed->filter(function ($user) use ($searchLower) {
                    return str_contains(strtolower($user->first_name_decrypted), $searchLower) ||
                        str_contains(strtolower($user->last_name_decrypted), $searchLower);
                });
            }

            // Sort by requested field (Lexicographical Sort to match Python default)
            $isDescending = ($direction === 'desc');
            $sortFlags = SORT_STRING; // Python default is case-sensitive lexicographical

            if ($ordering === 'first_name') {
                $processed = $processed->sortBy('first_name_decrypted', $sortFlags, $isDescending);
            } elseif ($ordering === 'last_name') {
                $processed = $processed->sortBy('last_name_decrypted', $sortFlags, $isDescending);
            } elseif ($ordering === 'username') {
                $processed = $processed->sortBy('username', $sortFlags, $isDescending);
            } elseif ($ordering === 'group_title' || $ordering === 'title') {
                $processed = $processed->sortBy('group_title', $sortFlags, $isDescending);
            }

            $totalCount = $processed->count();
            // Paginate Manually
            $resultsSlice = $processed->slice($offset, $limit)->values();

            $results = $resultsSlice->map(function ($user) {
                $groups = $user->group_title;
                if ($user->role === 'Teacher') {
                    $teacherGroups = DB::table('access_teacher_groups')
                        ->join('organisation_group', 'access_teacher_groups.group_id', '=', 'organisation_group.id')
                        ->where('access_teacher_groups.teacher_id', $user->role_id)
                        ->pluck('organisation_group.title')
                        ->toArray();
                    $groups = $teacherGroups;

                    if (!empty($groups)) {
                        usort($groups, function ($a, $b) {
                            $isArchivedA = in_array($a, ['Archived', 'आर्काइव', 'संग्रहणालय']);
                            $isArchivedB = in_array($b, ['Archived', 'आर्काइव', 'संग्रहणालय']);

                            if ($isArchivedA !== $isArchivedB) {
                                return $isArchivedA <=> $isArchivedB;
                            }
                            return strnatcasecmp($a, $b);
                        });
                    }
                }

                return [
                    "username" => $user->username,
                    "first_name" => $user->first_name_decrypted,
                    "last_name" => $user->last_name_decrypted,
                    "role_id" => $user->role_id,
                    "full_name" => $user->first_name_decrypted . " " . $user->last_name_decrypted,
                    "email" => $user->email,
                    "role" => $user->role,
                    "organisation" => [
                        "id" => $user->org_id,
                        "title" => $user->org_title
                    ],
                    "groups" => $groups
                ];
            });

            $nextUrl = null;
            if (($offset + $limit) < $totalCount) {
                $params = ['offset' => $offset + $limit, 'limit' => $limit];
                if ($ordering !== 'first_name' || $direction !== 'asc') {
                    $params['ordering'] = ($direction === 'desc' ? '-' : '') . $ordering;
                }
                $nextUrl = $request->fullUrlWithQuery($params);
            }
            $prevUrl = null;
            if ($offset > 0) {
                $params = ['offset' => max(0, $offset - $limit), 'limit' => $limit];
                if ($ordering !== 'first_name' || $direction !== 'asc') {
                    $params['ordering'] = ($direction === 'desc' ? '-' : '') . $ordering;
                }
                $prevUrl = $request->fullUrlWithQuery($params);
            }

            return response()->json([
                'count' => $totalCount,
                'next' => $nextUrl,
                'previous' => $prevUrl,
                'results' => $results,
            ], 200);
        } else {
            // SQL Pagination
            $totalCount = $usersQuery->count();
            $usersQuery->skip($offset)->take($limit);

            $results = $usersQuery->get()->map(function ($user) {
                try {
                    $firstName = Crypt::decryptString($user->first_name);
                } catch (\Exception $e) {
                    $firstName = $user->first_name;
                }

                try {
                    $lastName = Crypt::decryptString($user->last_name);
                } catch (\Exception $e) {
                    $lastName = $user->last_name;
                }

                $groups = $user->group_title;
                if ($user->role === 'Teacher') {
                    $teacherGroups = DB::table('access_teacher_groups')
                        ->join('organisation_group', 'access_teacher_groups.group_id', '=', 'organisation_group.id')
                        ->where('access_teacher_groups.teacher_id', $user->role_id)
                        ->pluck('organisation_group.title')
                        ->toArray();
                    $groups = $teacherGroups;

                    if (!empty($groups)) {
                        usort($groups, function ($a, $b) {
                            $isArchivedA = in_array($a, ['Archived', 'आर्काइव', 'संग्रहणालय']);
                            $isArchivedB = in_array($b, ['Archived', 'आर्काइव', 'संग्रहणालय']);

                            if ($isArchivedA !== $isArchivedB) {
                                return $isArchivedA <=> $isArchivedB;
                            }
                            return strnatcasecmp($a, $b);
                        });
                    }
                }

                return [
                    "username" => $user->username,
                    "first_name" => $firstName,
                    "last_name" => $lastName,
                    "role_id" => $user->role_id,
                    "full_name" => $firstName . " " . $lastName,
                    "email" => $user->email,
                    "role" => $user->role,
                    "organisation" => [
                        "id" => $user->org_id,
                        "title" => $user->org_title
                    ],
                    "groups" => $groups
                ];
            });

            $nextUrl = null;
            if (($offset + $limit) < $totalCount) {
                $params = ['offset' => $offset + $limit, 'limit' => $limit];
                if ($ordering !== 'first_name' || $direction !== 'asc') {
                    $params['ordering'] = ($direction === 'desc' ? '-' : '') . $ordering;
                }
                $nextUrl = $request->fullUrlWithQuery($params);
            }
            $prevUrl = null;
            if ($offset > 0) {
                $params = ['offset' => max(0, $offset - $limit), 'limit' => $limit];
                if ($ordering !== 'first_name' || $direction !== 'asc') {
                    $params['ordering'] = ($direction === 'desc' ? '-' : '') . $ordering;
                }
                $prevUrl = $request->fullUrlWithQuery($params);
            }

            return response()->json([
                'count' => $totalCount,
                'next' => $nextUrl,
                'previous' => $prevUrl,
                'results' => $results,
            ], 200);
        }
    }

    public function getUserDetails(Request $request, $id)
    {
        $student = DB::table('access_student')
            ->join('auth_user', 'access_student.user_id', '=', 'auth_user.id')
            ->leftJoin('organisation_organisation', 'access_student.organisation_id', '=', 'organisation_organisation.id')
            ->leftJoin('organisation_group', 'access_student.group_id', '=', 'organisation_group.id')
            ->leftJoin('common_grade', 'access_student.grade_id', '=', 'common_grade.id')
            ->where('access_student.id', $id)
            ->select(
                'access_student.*',
                'auth_user.username',
                'auth_user.first_name',
                'auth_user.last_name',
                'auth_user.email',
                'organisation_organisation.id as org_id',
                'organisation_organisation.title as org_title',
                'organisation_group.id as group_id',
                'organisation_group.title as group_title',
                'organisation_group.created as group_created',
                'organisation_group.updated as group_updated',
                'organisation_group.description as group_description',
                'organisation_group.level_id as group_level_id',
                'common_grade.title as grade_title'
            )
            ->first();

        if (!$student) {
            return response()->json([
                'message' => "User not found!",
            ], 404);
        }

        $firstName = $student->first_name;
        $lastName = $student->last_name;
        try {
            $firstName = Crypt::decryptString($firstName);
        } catch (\Exception $e) {
        }
        try {
            $lastName = Crypt::decryptString($lastName);
        } catch (\Exception $e) {
        }

        // Group Details
        $groupDetail = null;
        if ($student->group_id) {
            $studentsCount = DB::table('access_student')->where('group_id', $student->group_id)->count();
            $teachersCount = DB::table('access_teacher')
                ->join('access_teacher_groups', 'access_teacher_groups.teacher_id', '=', 'access_teacher.id')
                ->where('access_teacher.organisation_id', $student->organisation_id)
                ->where('access_teacher_groups.group_id', $student->group_id)
                ->count();

            $langId = DB::table('common_language')->where('name', 'ilike', 'English')->value('id');
            $goal = $this->resolveBenchmarkGoal(
                $student->organisation_id,
                $langId,
                $student->grade_id,
                $student->group_level_id,
                $request->input('assessment_period')
            );

            $groupDetail = [
                "id" => $student->group_id,
                "teachers_count" => $teachersCount,
                "students_count" => $studentsCount,
                "last_assessment_time" => null,
                "goal" => (int) ($goal ?? 0),
                "current_average" => isset($groupRaw->current_average) ? $groupRaw->current_average : 0,
                "created" => $this->formatDateTime($student->group_created),
                "updated" => $this->formatDateTime($student->group_updated),
                "title" => $student->group_title,
                "description" => $student->group_description,
                "organisation" => $student->organisation_id,
                "grade" => $student->grade_id,
                "level" => $student->group_level_id
            ];
        }

        // Last Assessment Time
        $lastAssessment = DB::table('assessment_assessment')
            ->where('student_id', $student->id)
            ->orderBy('id', 'desc')
            ->first();

        $lastAssessedTime = null;
        if ($lastAssessment) {
            if (isset($lastAssessment->created))
                $lastAssessedTime = $lastAssessment->created;
            elseif (isset($lastAssessment->created_at))
                $lastAssessedTime = $lastAssessment->created_at;
            elseif (isset($lastAssessment->date_created))
                $lastAssessedTime = $lastAssessment->date_created;
        }

        if ($groupDetail) {
            $groupDetail['last_assessment_time'] = $lastAssessedTime ? $this->formatAssessmentTime($lastAssessedTime) : null;
        }

        $languages = DB::table('access_student_languages')
            ->join('common_language', 'access_student_languages.language_id', '=', 'common_language.id')
            ->where('student_id', $student->id)
            ->pluck('name');

        $userDetail = [
            "username" => $student->username,
            "first_name" => $firstName,
            "last_name" => $lastName,
            "role_id" => $student->id,
            "user_id" => $student->id,
            "full_name" => $firstName . " " . $lastName,
            "email" => $student->email,
            "role" => "Student",
            "organisation" => [
                "id" => $student->org_id,
                "title" => $student->org_title
            ],
            "groups" => $student->group_title
        ];

        return response()->json([
            "id" => $student->id,
            "group_detail" => $groupDetail,
            "user_detail" => $userDetail,
            "organisation_title" => $student->org_title,
            "grade_title" => $student->grade_title,
            "role" => "Student",
            "last_assessment_time" => $lastAssessedTime ? $this->formatAssessmentTime($lastAssessedTime) : null,
            "is_assessed" => !is_null($lastAssessedTime),
            "languages" => $languages,
            "division" => $student->division,
            "date_of_birth" => $student->date_of_birth,
            "gender" => $student->gender,
            "is_english_second_language" => (bool) $student->is_english_second_language,
            "disability" => (bool) $student->disability,
            "picture" => $student->picture === '' ? null : $student->picture,
            "user" => $student->user_id,
            "organisation" => $student->organisation_id,
            "grade" => $student->grade_id,
            "group" => $student->group_id,
            "group_hindi" => $student->group_hindi_id,
            "group_marathi" => $student->group_marathi_id
        ], 200);
    }

    /**
     * Returns a combined list of teachers and students assigned to a specific group.
     * Matches Python: /api/group_wise_users
     */
    public function groupWiseUsers(Request $request)
    {
        $role = $request->query('role');
        $groupId = $this->sanitizeUuid($request->query('group__id', $request->query('group_id')));
        // Match User.php language check
        $isLangExplicit = $request->has('lang') || $request->has('language') || $request->has('languages__name');
        $langName = $request->query('language', $request->query('lang', $request->query('languages__name', 'English')));

        if (!$groupId) {
            return response()->json(['details' => 'Group id not mentioned'], 400);
        }

        // Authenticate and authorize
        $user = auth()->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // Match User.php isAdmin logic (allow override if admin, or check DB)
        $isActualAdmin = DB::table('access_admin')->where('user_id', $user->id)->exists();
        $isAdmin = $request->has('is_admin') ? filter_var($request->get('is_admin'), FILTER_VALIDATE_BOOLEAN) : $isActualAdmin;

        // Contextual Teacher/Division filtering
        $teacherIdContext = $this->sanitizeUuid($request->get('teachers__id', $request->get('teacher_id', $request->get('teachers_id'))));
        $teacherRecord = null;
        if ($teacherIdContext) {
            $teacherRecord = DB::table('access_teacher')->where('id', $teacherIdContext)->first();
        } else {
            // Default to current user's teacher record if not admin
            $teacherRecord = DB::table('access_teacher')->where('user_id', $user->id)->first();
        }

        if (!$isActualAdmin && !$teacherRecord) {
            return response()->json(['message' => 'User is not authorized to access this resource'], 403);
        }

        $teacherDivisions = [];
        if ($teacherRecord && $teacherRecord->division) {
            $teacherDivisions = array_map('trim', explode(',', $teacherRecord->division));
            $teacherDivisions = array_filter(array_map('strtoupper', $teacherDivisions));
        }

        // Language Handling
        $language = DB::table('common_language')->where('name', 'ilike', $langName)->first();
        if (!$language) {
            $language = DB::table('common_language')->where('name', 'ilike', 'English')->first();
        }
        $languageId = $language ? $language->id : (DB::table('common_language')->where('name', 'ilike', 'English')->value('id'));

        $groupColumn = 'group_id';
        if (strtolower($langName) === 'hindi') {
            $groupColumn = 'group_hindi_id';
        } elseif (strtolower($langName) === 'marathi') {
            $groupColumn = 'group_marathi_id';
        }

        // Fetch Group Details (Once for all results)
        $groupRaw = DB::table('organisation_group')->where('id', $groupId)->first();
        $groupDetail = null;
        if ($groupRaw) {
            // Metrics for group_detail
            $studentsCountQuery = DB::table('access_student as s')
                ->where('s.' . $groupColumn, $groupId);

            if ($isLangExplicit && $languageId) {
                $studentsCountQuery->join('access_student_languages as asl', 'asl.student_id', '=', 's.id')
                    ->where('asl.language_id', $languageId);
            }

            if (!empty($teacherDivisions)) {
                $studentsCountQuery->where(function ($q) use ($teacherDivisions) {
                    $q->whereIn(DB::raw('UPPER(s.division)'), $teacherDivisions)
                        ->orWhereNull('s.division')
                        ->orWhere('s.division', '');
                });
            }
            $studentsCountInGroup = $studentsCountQuery->count(DB::raw('DISTINCT s.id'));

            // Teacher Count (Conditionally language-filtered)
            $tcQuery = DB::table('access_teacher as t')
                ->join('access_teacher_groups as atg', 'atg.teacher_id', '=', 't.id')
                ->where('atg.group_id', $groupId);

            if ($isLangExplicit && $languageId) {
                $tcQuery->join('access_teacher_languages as atl', 'atl.teacher_id', '=', 't.id')
                    ->where('atl.language_id', $languageId);
            }
            $teachersCountInGroup = $tcQuery->count(DB::raw('DISTINCT t.id'));

            $lastAssessmentTime = DB::table('assessment_assessment as a')
                ->join('passage_passage as p', 'a.passage_id', '=', 'p.id')
                ->where('a.group_id', $groupId)
                ->where('p.language_id', $languageId)
                ->orderByDesc('a.created')
                ->value('a.created');

            $avgQuery = DB::table('assessment_assessment as a')
                ->join('passage_passage as p', 'a.passage_id', '=', 'p.id')
                ->join('access_student as s', 'a.student_id', '=', 's.id')
                ->join('access_student_languages as sl', 's.id', '=', 'sl.student_id')
                ->where('a.group_id', $groupId)
                ->where('s.' . $groupColumn, $groupId)
                ->where('sl.language_id', $languageId)
                ->where('p.language_id', $languageId);

            if (!empty($teacherDivisions)) {
                $avgQuery->where(function ($q) use ($teacherDivisions) {
                    $q->whereIn(DB::raw('UPPER(s.division)'), $teacherDivisions)
                        ->orWhereNull('s.division')
                        ->orWhere('s.division', '');
                });
            }

            $avgCorrect = $avgQuery->avg(DB::raw('a.last_word_index + 1 - a.no_error_words'));

            $goal = $this->resolveBenchmarkGoal(
                $groupRaw->organisation_id,
                $languageId,
                $groupRaw->grade_id,
                $groupRaw->level_id,
                $request->input('assessment_period')
            );

            $groupDetail = [
                'id'                   => $groupRaw->id,
                'teachers_count'       => (int) $teachersCountInGroup,
                'students_count'       => (int) $studentsCountInGroup,
                'last_assessment_time' => $lastAssessmentTime ? $this->formatAssessmentTime($lastAssessmentTime) : null,
                'goal'                 => (float) ($goal ?? 0),
                'current_average'      => (float) ($avgCorrect ?? 0),
                'created'              => $groupRaw->created ? $this->formatDateTime($groupRaw->created) : null,
                'updated'              => $groupRaw->updated ? $this->formatDateTime($groupRaw->updated) : null,
                'title'                => $this->translateTitle($groupRaw->title, $langName),
                'description'          => $groupRaw->description,
                'organisation'         => $groupRaw->organisation_id,
                'grade'                => $groupRaw->grade_id,
                'level'                => $groupRaw->level_id,
            ];
        }

        $type = 2; // both
        if (strtolower($role) === 'teacher') {
            $type = 0;
        } elseif (strtolower($role) === 'student') {
            $type = 1;
        }

        $results = [];

        // Fetch teachers
        if ($type === 0 || $type === 2) {
            $teachersQuery = DB::table('access_teacher as t')
                ->leftJoin('auth_user as u', 'u.id', '=', 't.user_id')
                ->join('access_teacher_groups as atg', 'atg.teacher_id', '=', 't.id')
                ->leftJoin('organisation_organisation as o', 'o.id', '=', 't.organisation_id')
                ->where('atg.group_id', $groupId)
                ->select([
                    't.id',
                    't.division',
                    't.type',
                    't.gender',
                    't.user_id as user',
                    't.organisation_id as organisation',
                    'u.username',
                    'u.first_name',
                    'u.last_name',
                    'u.email',
                    'u.date_joined',
                    'o.title as organisation_title'
                ])
                ->distinct();

            if ($isLangExplicit && $languageId) {
                $teachersQuery->join('access_teacher_languages as atl', 'atl.teacher_id', '=', 't.id')
                    ->where('atl.language_id', $languageId);
            }

            $teachersRaw = $teachersQuery->get();
            foreach ($teachersRaw as $teacher) {
                $results[] = $this->formatGroupWiseTeacherResult($teacher, $groupDetail);
            }
        }

        // Fetch students
        if ($type === 1 || $type === 2) {
            $studentsQuery = DB::table('access_student as s')
                ->leftJoin('auth_user as u', 'u.id', '=', 's.user_id')
                ->leftJoin('organisation_organisation as o', 'o.id', '=', 's.organisation_id')
                ->leftJoin('common_grade as gr', 'gr.id', '=', 's.grade_id')
                ->where('s.' . $groupColumn, $groupId)
                ->select([
                    's.id',
                    's.division',
                    's.date_of_birth',
                    's.gender',
                    's.is_english_second_language',
                    's.disability',
                    's.picture',
                    's.user_id as user',
                    's.organisation_id as organisation',
                    's.grade_id as grade',
                    's.group_id as group',
                    'u.username',
                    'u.first_name',
                    'u.last_name',
                    'u.email',
                    'u.date_joined',
                    'o.title as organisation_title',
                    'gr.title as grade_title'
                ])
                ->distinct();

            if ($isLangExplicit && $languageId) {
                $studentsQuery->join('access_student_languages as asl', 'asl.student_id', '=', 's.id')
                    ->where('asl.language_id', $languageId);
            }

            if (!empty($teacherDivisions)) {
                $studentsQuery->where(function ($q) use ($teacherDivisions) {
                    $q->whereIn(DB::raw('UPPER(s.division)'), $teacherDivisions)
                        ->orWhereNull('s.division')
                        ->orWhere('s.division', '');
                });
            }

            $studentsRaw = $studentsQuery->get();
            foreach ($studentsRaw as $student) {
                // Fetch student languages
                $student->languages = DB::table('access_student_languages as asl')
                    ->join('common_language as cl', 'cl.id', '=', 'asl.language_id')
                    ->where('asl.student_id', $student->id)
                    ->pluck('cl.name');

                // Fetch assessment status
                $student->last_assessment_time = DB::table('assessment_assessment')
                    ->where('student_id', $student->id)
                    ->orderByDesc('created')
                    ->value('created');
                $student->is_assessed = DB::table('assessment_assessment')
                    ->where('student_id', $student->id)
                    ->exists();

                $results[] = $this->formatGroupWiseStudentResult($student, $groupDetail);
            }
        }

        // Natural Sorting (Python Version Sorting: Teachers then Students, each naturally sorted by name)
        usort($results, function ($a, $b) {
            $roleA = $a['user_detail']['role'] ?? "";
            $roleB = $b['user_detail']['role'] ?? "";

            // Teachers (0) before Students (1)
            if ($roleA !== $roleB) {
                return ($roleA === 'Teacher') ? -1 : 1;
            }

            $nameA = $a['user_detail']['full_name'] ?? "";
            $nameB = $b['user_detail']['full_name'] ?? "";
            return strnatcasecmp($nameA, $nameB);
        });

        return response()->json($results, 200);
    }

    private function formatGroupWiseTeacherResult($teacher, $groupDetail = null)
    {
        $firstName = $teacher->first_name;
        $lastName = $teacher->last_name;
        try {
            if ($firstName && str_contains($firstName, 'eyJpdiI6')) $firstName = Crypt::decryptString($firstName);
            if ($lastName && str_contains($lastName, 'eyJpdiI6')) $lastName = Crypt::decryptString($lastName);
        } catch (\Exception $e) {
        }

        return [
            'id' => $teacher->id,
            'group_detail' => $groupDetail,
            'role' => 'Teacher',
            'user_detail' => [
                'username' => $teacher->username,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'full_name' => trim($firstName . ' ' . $lastName),
                'email' => $teacher->email,
                'role' => 'Teacher',
                'organisation' => [
                    'id' => $teacher->organisation,
                    'title' => $teacher->organisation_title
                ],
                'groups' => $groupDetail['title'] ?? null
            ],
            'organisation_title' => $teacher->organisation_title,
            'division' => $teacher->division,
            'type' => $teacher->type,
            'gender' => $teacher->gender,
            'user' => $teacher->user,
            'organisation' => $teacher->organisation
        ];
    }

    private function formatGroupWiseStudentResult($student, $groupDetail = null)
    {
        $firstName = $student->first_name;
        $lastName = $student->last_name;
        try {
            if ($firstName && str_contains($firstName, 'eyJpdiI6')) $firstName = Crypt::decryptString($firstName);
            if ($lastName && str_contains($lastName, 'eyJpdiI6')) $lastName = Crypt::decryptString($lastName);
        } catch (\Exception $e) {
        }

        return [
            'id' => $student->id,
            'group_detail' => $groupDetail,
            'role' => 'Student',
            'user_detail' => [
                'username' => $student->username,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'full_name' => trim($firstName . ' ' . $lastName),
                'email' => $student->email,
                'role' => 'Student',
                'organisation' => [
                    'id' => $student->organisation,
                    'title' => $student->organisation_title
                ],
                'groups' => $groupDetail['title'] ?? null
            ],
            'organisation_title' => $student->organisation_title,
            'grade_title' => $student->grade_title,
            'last_assessment_time' => $student->last_assessment_time ? $this->formatAssessmentTime($student->last_assessment_time) : null,
            'is_assessed' => (bool) $student->is_assessed,
            'languages' => $student->languages,
            'division' => $student->division,
            'date_of_birth' => $student->date_of_birth,
            'gender' => $student->gender,
            'is_english_second_language' => (bool)$student->is_english_second_language,
            'disability' => (bool)$student->disability,
            'picture' => $student->picture === '' ? null : $student->picture,
            'user' => $student->user,
            'organisation' => $student->organisation,
            'grade' => $student->grade,
            'group' => $student->group
        ];
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
}
