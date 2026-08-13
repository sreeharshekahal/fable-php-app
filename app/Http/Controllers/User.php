<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\UserModel;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Imports\StudentImport;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use App\Models\Student;
use App\Models\BenchmarkTemplate;
use App\Constants\TranslationConstants;


class User extends Controller
{
    public function index()
    {
        // $new_user = new UserModel();
        // $new_user->id = Str::uuid();
        // $new_user->first_name = 'Komal';
        // $new_user->password = Hash::make('123456');
        // $new_user->save();
        // echo "<pre>"; print_r($new_user); exit;
    }

    public function updateStudent(Request $request, $id)
    {
        $validatedData = $request->validate([
            // 'picture' => 'required',
        ]);

        $student = UserModel::find($id);

        if (!$student) {
            return response()->json(['message' => 'Student not found'], 404);
        }

        //Upload picture on s3
        $image = $request->get('picture');

        // Extract the base64 part
        if ($image) {
            if (preg_match('/^data:image\/(\w+);base64,/', $image, $type)) {
                $image = substr($image, strpos($image, ',') + 1);
                $type = strtolower($type[1]); // jpg, png, gif

                // Decode the image
                $image = base64_decode($image);

                if ($image === false) {
                    return response()->json(['error' => 'Base64 decode failed'], 400);
                }
            } else {
                return response()->json(['error' => 'Invalid image data'], 400);
            }

            // Create a unique filename
            $filename = uniqid() . '.' . $type;

            // Store the image in S3
            Storage::disk('s3')->put("uploads/{$filename}", $image);

            // Get the URL of the stored image
            $imageUrl = Storage::disk('s3')->url("uploads/{$filename}");
        } else {
            $imageUrl = $student->picture;
        }

        $updateData = [
            'picture' => $imageUrl,
            'group_hindi_id' => $request->get('group_hindi_id'),
            'group_marathi_id' => $request->get('group_marathi_id'),
            'division' => $request->get('division')
        ];

        $student->update($updateData);

        return response()->json($student, 200);
    }

    public function updateTeacher(Request $request, $id)
    {
        $teacher = DB::table('access_teacher')->where('id', $id);

        if (!$teacher) {
            return response()->json(['message' => 'Teacher not found'], 404);
        }

        $updateData = [
            'division' => $request->get('division')
        ];

        $teacher->update($updateData);

        $user_id = $teacher->first()->user_id;

        if ($user_id && $request->get('password')) {
            $password = $request->get('password');
            $salt = Str::random(12); // You can use random_bytes if you want binary
            $iterations = 150000;

            // Generate raw binary hash
            $rawHash = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);

            // Encode it in base64
            $base64Hash = base64_encode($rawHash);

            // Build final string
            $hashedPassword = "pbkdf2_sha256\${$iterations}\${$salt}\${$base64Hash}";
            DB::table('auth_user')->where('id', $user_id)->update(['password' => $hashedPassword]);
        }

        return response()->json(['success' => 'Updated successfully'], 200);
    }

    /*
     *   Save Audio
     */
    public function saveAudio(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'audio' => 'required_without:retell_audio',
                'retell_audio' => 'required_without:audio',
                'assessment_id' => 'required',
                'student_id' => 'required'
            ]);

            $base64Audio = $request->input('audio');
            $filename = time() . '_audio';

            $audioData = base64_decode($base64Audio);

            if ($audioData === false) {
                return response()->json(['error' => 'Invalid Base64 encoding'], 400);
            }

            $filePath = "uploads/audio/{$filename}.mp3";

            Storage::disk('s3')->put($filePath, $audioData);

            $fileUrl = Storage::disk('s3')->url($filePath);
            $retellfileUrl = '';

            if ($request->input('retell_audio')) {
                $base64Audio = $request->input('retell_audio');
                $filename = time() . '_retell_audio';

                $audioData = base64_decode($base64Audio);

                if ($audioData === false) {
                    return response()->json(['error' => 'Invalid Base64 encoding'], 400);
                }

                $filePath = "uploads/audio/{$filename}.mp3";

                Storage::disk('s3')->put($filePath, $audioData);

                $retellfileUrl = Storage::disk('s3')->url($filePath);
            }

            $model = new UserModel();
            $getStudentAssessment = $model->getStudentAssessment($request->get('assessment_id'), $request->get('student_id'));

            if ($getStudentAssessment) {
                $studentGroup = $model->saveAudio($request->get('assessment_id'), $request->get('student_id'), $fileUrl, $retellfileUrl);
                return response()->json(['message' => 'Audio Saved'], 200);
            } else {
                return response()->json(['message' => 'Student assessment not found'], 404);
            }
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }
    }

    /*
     *   Get Audio
     */
    public function getAudio(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'assessment_id' => 'required',
                'student_id' => 'required'
            ]);

            $assessment_id = $request->get('assessment_id');
            $student_id = $request->get('student_id');
            $model = new UserModel();
            $audio = $model->getAudio($assessment_id, $student_id);
            return response()->json(['message' => 'Data Found', 'data' => $audio], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }
    }

    /*
     *   Get Student Profile pic
     */
    public function getStudentPic(Request $request, $id)
    {
        $student = UserModel::select('picture')->find($id);
        return response()->json(['message' => 'Data Found', 'data' => $student], 200);
    }

    /*
     *   Get Teacher
     */
    public function getTeacher(Request $request, $id)
    {
        $teacher = DB::table('access_teacher')
            ->select('access_teacher.division', 'auth_user.password')
            ->join('auth_user', 'access_teacher.user_id', '=', 'auth_user.id')
            ->where('access_teacher.id', $id)
            ->first();

        if (!$teacher) {
            return response()->json(['message' => 'Teacher not found'], 404);
        }
        return response()->json(['message' => 'Data Found', 'data' => $teacher], 200);
    }

    /*
     *   Save Device Details
     */
    public function saveDeviceDetails(Request $request, $user_id)
    {
        try {
            $validatedData = $request->validate([
                'device_details' => 'required'
            ]);
            $device_details = $request->get('device_details');
            $model = new UserModel();
            $device_details = $model->saveDeviceDetails($user_id, $device_details);
            return response()->json(['message' => 'Data Found', 'data' => $device_details], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }
    }

    /*
     *   Get org user count
     */
    public function allOrgUsersCount(Request $request)
    {
        $organisations = DB::table('organisation_organisation')->get()->toArray();

        $resultArr = [];
        if ($organisations) {
            $i = 0;
            foreach ($organisations as $organisation) {
                $org_id = $organisation->id;
                $resultArr[$i]['organisation_id'] = $org_id;
                $resultArr[$i]['title'] = $organisation->title;

                $studentCount = DB::table('access_student')->where('organisation_id', $org_id)->count();
                $teacherCount = DB::table('access_teacher')->where('organisation_id', $org_id)->count();
                $resultArr[$i]['studentCount'] = $studentCount;
                $resultArr[$i]['teacherCount'] = $teacherCount;
                $resultArr[$i]['userCount'] = $studentCount + $teacherCount;
                $i++;
            }
        }

        return response()->json($resultArr, 200);
    }

    /*
     *   Get org user count
     */
    public function organisationUsersCount(Request $request, $org_id)
    {
        $isAdmin = $request->has('is_admin') ? filter_var($request->get('is_admin'), FILTER_VALIDATE_BOOLEAN) : false;
        $teacher_id = $this->sanitizeUuid($request->get('teachers_id', $request->get('teacher_id', $request->get('teachers__id'))));
        $query = DB::table('organisation_group')
            ->join('common_grade', 'organisation_group.grade_id', '=', 'common_grade.id')
            ->where('organisation_group.organisation_id', $org_id);

        if (!$isAdmin) {
            $query->where('common_grade.level', '!=', 6);
        }

        if ($teacher_id) {
            $query->join('access_teacher_groups', 'access_teacher_groups.group_id', '=', 'organisation_group.id')
                ->where('access_teacher_groups.teacher_id', $teacher_id);
        }

        $groups = $query->select('organisation_group.*')->get()->toArray();

        $teacherDivisions = [];
        if ($teacher_id) {
            $teacher = DB::table('access_teacher')->where('id', $teacher_id)->first();
            if ($teacher && $teacher->division) {
                $teacherDivisions = array_map('trim', explode(',', $teacher->division));
                $teacherDivisions = array_filter(array_map('strtoupper', $teacherDivisions));
            }
        }

        $resultArr = [];
        if ($groups) {
            foreach ($groups as $group) {
                $group_id = $group->id;

                // Add averages for mobile app
                $isLangExplicit = $request->has('lang') || $request->has('language') || $request->has('languages__name');
                $langName = $request->get('lang', $request->get('language', $request->get('languages__name', 'English')));
                $language = DB::table('common_language')->where('name', 'ilike', $langName)->first();
                $language_id = $language ? $language->id : (DB::table('common_language')->where('name', 'ilike', 'English')->value('id'));

                $groupColumn = 'group_id';
                if (strtolower($langName) === 'hindi') $groupColumn = 'group_hindi_id';
                elseif (strtolower($langName) === 'marathi') $groupColumn = 'group_marathi_id';

                // Student Count using the language-specific group column + conditional language filter
                $scQuery = DB::table('access_student')
                    ->where('access_student.' . $groupColumn, $group_id);

                if ($isLangExplicit && $language_id) {
                    $scQuery->join('access_student_languages', 'access_student_languages.student_id', '=', 'access_student.id')
                        ->where('access_student_languages.language_id', $language_id);
                }

                if (!empty($teacherDivisions)) {
                    $scQuery->whereIn(DB::raw('UPPER(access_student.division)'), $teacherDivisions);
                }

                $studentCount = $scQuery->count();

                // 2. Teacher Count - Conditionally filter by language association
                $tcQuery = DB::table('access_teacher as t')
                    ->join('access_teacher_groups as tg', 'tg.teacher_id', '=', 't.id')
                    ->where('tg.group_id', $group_id);

                if ($isLangExplicit && $language_id) {
                    $tcQuery->join('access_teacher_languages as tl', 'tl.teacher_id', '=', 't.id')
                        ->where('tl.language_id', $language_id);
                }

                $teacherCount = $tcQuery->count(DB::raw('DISTINCT t.id'));

                // Get average correct words (Strict Benchmarking)
                $avgQuery = DB::table('assessment_assessment as a')
                    ->join('passage_passage as p', 'a.passage_id', '=', 'p.id')
                    ->join('access_student as s', 'a.student_id', '=', 's.id')
                    ->join('access_student_languages as sl', 's.id', '=', 'sl.student_id')
                    ->where('a.group_id', $group_id)
                    ->where('s.' . $groupColumn, $group_id)
                    ->where('sl.language_id', $language_id)
                    ->where('p.language_id', $language_id);

                if (!empty($teacherDivisions)) {
                    $avgQuery->whereIn(DB::raw('UPPER(s.division)'), $teacherDivisions);
                }

                $avgCorrect = $avgQuery->avg(DB::raw('a.last_word_index + 1 - a.no_error_words'));

                $avgGoal = $this->resolveBenchmarkGoal(
                    $org_id,
                    $language_id,
                    $group->grade_id,
                    $group->level_id,
                    $request->input('assessment_period')
                );

                $resultArr[] = [
                    'id' => $group_id,
                    'group_id' => $group_id,
                    'teacherCount' => (int) $teacherCount,
                    'studentCount' => (int) $studentCount,
                    'avgCorrect' => round($avgCorrect ?? 0, 2),
                    'avgGoal' => (float) ($avgGoal ?? 0),
                    'title' => $this->translateTitle($group->title, $langName),
                ];
            }
        }

        usort($resultArr, function ($a, $b) {
            $archivedTitles = ['Archived', 'Archive', 'आर्काइव', 'संग्रहणालय'];
            $aArchived = in_array($a['title'], $archivedTitles) || in_array(ucfirst(strtolower($a['title'])), $archivedTitles);
            $bArchived = in_array($b['title'], $archivedTitles) || in_array(ucfirst(strtolower($b['title'])), $archivedTitles);

            if ($aArchived && !$bArchived) return 1;
            if (!$aArchived && $bArchived) return -1;

            return strnatcasecmp($a['title'], $b['title']);
        });

        return response()->json($resultArr, 200);
    }

    /**
     * Get Organisation Groups with detailed metrics
     * Matches Python GroupViewSet list implementation
     */
    public function getOrganisationGroups(Request $request, $org_id)
    {
        $org_id = $this->sanitizeUuid($org_id);
        $langName = $request->get('lang', $request->get('language', $request->get('languages__name', 'English')));
        $isAdmin = $request->has('is_admin') ? filter_var($request->get('is_admin'), FILTER_VALIDATE_BOOLEAN) : false;
        $ordering = $request->get('ordering', 'title');

        $limit = $request->input('limit');
        $offset = $request->input('offset', 0);

        // Filter parameters
        $teacher_id = $this->sanitizeUuid($request->get('teachers_id', $request->get('teacher_id', $request->get('teachers__id'))));
        $division = $request->get('division', $request->get('division__name', ''));

        // Find language
        $language = DB::table('common_language')->where('name', 'ilike', $langName)->first();
        if (!$language) {
            $language = DB::table('common_language')->where('name', 'ilike', 'English')->first();
        }
        $language_id = $language ? $language->id : null;

        // Group Column Logic
        $groupColumn = 'group_id';
        if (strtolower($langName) === 'hindi') {
            $groupColumn = 'group_hindi_id';
        } elseif (strtolower($langName) === 'marathi') {
            $groupColumn = 'group_marathi_id';
        }

        // Fetch teacher divisions if teacher_id is provided
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

        // Teacher Filter
        if ($teacher_id) {
            $query->join('access_teacher_groups', 'access_teacher_groups.group_id', '=', 'organisation_group.id')
                ->where('access_teacher_groups.teacher_id', $teacher_id);
        }

        // Division Filter (only return groups that have students in matching division)
        if (!empty($teacherDivisions) || $division) {
            $query->whereExists(function ($q) use ($teacherDivisions, $division, $groupColumn) {
                $q->select(DB::raw(1))
                    ->from('access_student as s')
                    ->whereColumn('s.' . $groupColumn, 'organisation_group.id');

                if (!empty($teacherDivisions)) {
                    $q->whereIn(DB::raw('UPPER(s.division)'), $teacherDivisions);
                }

                if ($division) {
                    $q->where(function ($sq) use ($division) {
                        $divs = is_array($division) ? $division : explode(',', $division);
                        foreach ($divs as $div) {
                            $sq->orWhere('s.division', 'ILIKE', "%" . trim($div) . "%");
                        }
                    });
                }
            });
        }

        // Admin Filter
        if (!$isAdmin) {
            $query->where('common_grade.level', '!=', 6);
        }

        // Sorting
        $direction = 'asc';
        if (str_starts_with($ordering, '-')) {
            $direction = 'desc';
            $ordering = ltrim($ordering, '-');
        }

        if ($ordering === 'grade__level') {
            $query->orderBy('common_grade.level', $direction);
        } elseif ($ordering === 'level__rank') {
            $query->join('common_level', 'organisation_group.level_id', '=', 'common_level.id')
                ->orderBy('common_level.rank', $direction);
        } else {
            // Default to title or other direct columns
            $query->orderBy('organisation_group.' . ($ordering ?: 'title'), $direction);
        }

        $totalCount = $query->count();

        if ($limit) {
            $query->skip($offset)->take($limit);
        }

        $groups = $query->select('organisation_group.*', 'common_grade.level as grade_level')->get();

        $resultArr = [];
        $isLangExplicit = $request->has('lang') || $request->has('language') || $request->has('languages__name');

        foreach ($groups as $group) {
            $group_id = $group->id;

            // 1. Student Count (filtered by group and division + conditional language filter)
            $studentCountQuery = DB::table('access_student')
                ->where('access_student.' . $groupColumn, $group_id);

            if ($isLangExplicit && $language_id) {
                $studentCountQuery->join('access_student_languages', 'access_student_languages.student_id', '=', 'access_student.id')
                    ->where('access_student_languages.language_id', $language_id);
            }

            if (!empty($teacherDivisions)) {
                $studentCountQuery->whereIn(DB::raw('UPPER(access_student.division)'), $teacherDivisions);
            }

            if ($division) {
                $studentCountQuery->where(function ($q) use ($division) {
                    $divs = is_array($division) ? $division : explode(',', $division);
                    foreach ($divs as $div) {
                        $q->orWhere('access_student.division', 'ILIKE', "%" . trim($div) . "%");
                    }
                });
            }

            $studentCount = $studentCountQuery->count();

            // 2. Teacher Count - Conditionally filter by language association
            $tcQuery = DB::table('access_teacher as t')
                ->join('access_teacher_groups as tg', 'tg.teacher_id', '=', 't.id')
                ->where('tg.group_id', $group_id);

            if ($isLangExplicit && $language_id) {
                $tcQuery->join('access_teacher_languages as tl', 'tl.teacher_id', '=', 't.id')
                    ->where('tl.language_id', $language_id);
            }

            $teacherCount = $tcQuery->count(DB::raw('DISTINCT t.id'));

            // 3. Current Average (Assessment metrics in specific language, filtered by division)
            $avgCorrectQuery = DB::table('assessment_assessment as a')
                ->join('passage_passage as p', 'a.passage_id', '=', 'p.id')
                ->join('access_student as s', 'a.student_id', '=', 's.id')
                ->join('access_student_languages as sl', 's.id', '=', 'sl.student_id')
                ->where('a.group_id', $group_id)
                ->where('p.language_id', $language_id)
                ->where('s.' . $groupColumn, $group_id)
                ->where('sl.language_id', $language_id);

            if (!empty($teacherDivisions)) {
                $avgCorrectQuery->whereIn(DB::raw('UPPER(s.division)'), $teacherDivisions);
            }

            if ($division) {
                $avgCorrectQuery->where(function ($q) use ($division) {
                    $divs = is_array($division) ? $division : explode(',', $division);
                    foreach ($divs as $div) {
                        $q->orWhere('s.division', 'ILIKE', "%" . trim($div) . "%");
                    }
                });
            }

            $avgCorrect = $avgCorrectQuery->avg(DB::raw('a.last_word_index + 1 - a.no_error_words'));

            $avgGoal = $this->resolveBenchmarkGoal(
                $org_id,
                $language_id,
                $group->grade_id,
                $group->level_id,
                $request->input('assessment_period')
            );

            // 5. Last Assessment timestamp (filtered by division)
            $lastAssessmentQuery = DB::table('assessment_assessment')
                ->join('passage_passage', 'assessment_assessment.passage_id', '=', 'passage_passage.id')
                ->where('assessment_assessment.group_id', $group_id)
                ->where('passage_passage.language_id', $language_id);

            if (!empty($teacherDivisions) || $division) {
                $lastAssessmentQuery->join('access_student as s', 'assessment_assessment.student_id', '=', 's.id');

                if (!empty($teacherDivisions)) {
                    $lastAssessmentQuery->whereIn(DB::raw('UPPER(s.division)'), $teacherDivisions);
                }

                if ($division) {
                    $lastAssessmentQuery->where(function ($q) use ($division) {
                        $divs = is_array($division) ? $division : explode(',', $division);
                        foreach ($divs as $div) {
                            $q->orWhere('s.division', 'ILIKE', "%" . trim($div) . "%");
                        }
                    });
                }
            }

            $lastAssessment = $lastAssessmentQuery->orderBy('assessment_assessment.id', 'desc')
                ->select('assessment_assessment.created')
                ->first();

            $lastAssessedTime = null;
            if ($lastAssessment) {
                $rawTime = $lastAssessment->created;
                if ($rawTime) {
                    try {
                        $lastAssessedTime = Carbon::parse($rawTime)->toIso8601ZuluString('microsecond');
                    } catch (\Exception $e) {
                    }
                }
            }

            $resultArr[] = [
                'id' => $group->id,
                'teachers_count' => (int) $teacherCount,
                'students_count' => (int) $studentCount,
                'last_assessment_time' => $lastAssessedTime ? $this->formatAssessmentTime($lastAssessedTime) : null,
                'goal' => (float) ($avgGoal ?? 0),
                'current_average' => (float) ($avgCorrect ?? 0),
                'created' => $group->created ? $this->formatDateTime($group->created) : null,
                'updated' => $group->updated ? $this->formatDateTime($group->updated) : null,
                'title' => $group->title,
                'description' => $group->description ?? null,
                'organisation' => $group->organisation_id,
                'grade' => $group->grade_id,
                'level' => $group->level_id,
            ];
        }

        $nextUrl = null;
        if ($limit && ($offset + $limit) < $totalCount) {
            $nextUrl = $request->fullUrlWithQuery(['offset' => $offset + $limit]);
        }
        $prevUrl = null;
        if ($offset > 0) {
            $prevUrl = $request->fullUrlWithQuery(['offset' => max(0, $offset - ($limit ?? 10))]);
        }

        return response()->json([
            'count' => $totalCount,
            'next' => $nextUrl,
            'previous' => $prevUrl,
            'results' => $resultArr
        ], 200);
    }

    /*
     *   Bulk upload students
     */
    public function bulkUploadStudent(Request $request)
    {
        $organisation = $request->get('organisation');
        $grade = $request->get('grade');

        try {
            $request->validate([
                'file' => 'required|file|mimes:xlsx,xls,csv',
            ]);

            // Check if file exists and is valid
            if (!$request->hasFile('file') || !$request->file('file')->isValid()) {
                return response()->json(['error' => 'Invalid or missing file'], 400);
            }

            Excel::import(
                new StudentImport($organisation, $grade),
                $request->file('file')
            );

            return response()->json(['success' => 'Users uploaded successfully'], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Laravel validation errors
            return response()->json([
                'error' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            // Custom errors like missing "languages"
            return response()->json([
                'error' => $e->getMessage(),
            ], 422); // validation-type error
        }
    }

    /*
     *   Bulk upload teacher
     */
    public function bulkUploadTeacher(Request $request)
    {
        $organisation = $request->get('organisation');
        $groups = $request->get('groups');
        $groups = json_decode($groups, true);

        try {
            $request->validate([
                'file' => 'required|file|mimes:xlsx,xls,csv,txt',
            ]);

            // Check if file exists and is valid
            if (!$request->hasFile('file') || !$request->file('file')->isValid()) {
                return response()->json(['error' => 'Invalid or missing file'], 400);
            }

            $data = Excel::toArray([], $request->file('file'));

            // First sheet
            $sheet = $data[0];

            if (empty($sheet)) {
                return response()->json(['error' => 'Empty sheet'], 400);
            }

            // Get headers from first row
            $headers = array_slice(array_map('trim', $sheet[0]), 0, 8);

            // Get data (excluding the header row)
            $rows = array_slice($sheet, 1);

            $mappedRows = array_filter(array_map(function ($row) use ($headers) {
                $data = array_combine($headers, array_slice($row, 0, 8));

                // Remove row if all values are null/empty
                if (collect($data)->filter()->isEmpty()) {
                    return null;
                }

                return $data;
            }, $rows));

            $resultArr = [];
            if (!empty($mappedRows)) {
                foreach ($mappedRows as $mappedRow) {
                    $password = Str::random(12);
                    $salt = Str::random(12); // You can use random_bytes if you want binary
                    $iterations = 150000;

                    // Generate raw binary hash
                    $rawHash = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);

                    // Encode it in base64
                    $base64Hash = base64_encode($rawHash);

                    // Build final string
                    $hashedPassword = "pbkdf2_sha256\${$iterations}\${$salt}\${$base64Hash}";

                    $currentTime = Carbon::now('Asia/Kolkata');
                    $date_joined = $currentTime->format('Y-m-d H:i:s.uP');

                    $genders = [
                        'Male' => 0,
                        'Female' => 1,
                        'Others' => 2
                    ];

                    $user_id = DB::table('auth_user')->insertGetId([
                        'first_name' => $mappedRow['first_name'],
                        'last_name' => $mappedRow['last_name'],
                        'email' => $mappedRow['email'],
                        'username' => $mappedRow['username'],
                        'password' => $hashedPassword,
                        'is_superuser' => 'f',
                        'is_staff' => 'f',
                        'is_active' => 't',
                        'date_joined' => $date_joined
                    ]);

                    $teacher_id = DB::table('access_teacher')->insertGetId([
                        'id' => Str::uuid(),
                        'type' => $mappedRow['type'],
                        'gender' => $genders[$mappedRow['gender']],
                        'user_id' => $user_id,
                        'organisation_id' => $organisation,
                        'division' => (isset($mappedRow['division'])) ? $mappedRow['division'] : null
                    ]);

                    $groups = $groups;
                    if (!empty($groups)) {
                        foreach ($groups as $group) {
                            DB::table('access_teacher_groups')->insert([
                                'teacher_id' => $teacher_id,
                                'group_id' => $group
                            ]);
                        }
                    }

                    $languages = $mappedRow['languages'];
                    if ($languages) {
                        foreach (explode(',', $languages) as $language) {
                            //Get language id
                            $language = DB::table('common_language')->where('name', $language)->first();
                            $language_id = $language->id;
                            DB::table('access_teacher_languages')->insert([
                                'teacher_id' => $teacher_id,
                                'language_id' => $language_id
                            ]);
                        }
                    }

                    $resultArr[] = $user_id;
                }

                return response()->json(['success' => 'teacher uploaded successfully'], 200);
            }
        } catch (\Exception $e) {
            // Return the real error
            return response()->json([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /*
     *   Bulk Promote Students
     */
    public function bulkPromoteStudents(Request $request)
    {
        $organisation = $request->get('organisation');
        $grade_level = $request->get('grade_level');
        $students = $request->get('students');
        $students = json_decode($students, true);

        $upgrade_level = $grade_level + 1;
        $new_grade = DB::table('common_grade')->where('level', $upgrade_level)->first();
        $new_grade_id = $new_grade->id;

        $level1 = DB::table('common_level')->where('rank', 1)->first();
        $level_id = $level1->id;

        $new_group = DB::table('organisation_group')->where('organisation_id', $organisation)->where('grade_id', $new_grade_id)->where('level_id', $level_id)->first();
        $new_group_id = $new_group->id;

        if (!empty($students)) {
            foreach ($students as $student) {
                $getStudent = DB::table('access_student')->where('id', $student)->first();

                $updateData['grade_id'] = $new_grade_id;
                if ($getStudent->group_id) {
                    $updateData['group_id'] = $new_group_id;
                }
                if ($getStudent->group_hindi_id) {
                    $updateData['group_hindi_id'] = $new_group_id;
                }
                if ($getStudent->group_marathi_id) {
                    $updateData['group_marathi_id'] = $new_group_id;
                }

                DB::table('access_student')
                    ->where('id', $student)
                    ->update($updateData);
            }
        }

        return response()->json(['success' => 'student promoted successfully'], 200);
    }

    /*
     *   Get Passage PDF
     */
    public function getPassagePDF(Request $request)
    {
        $passage_id = $request->id;
        $passage = DB::table('passage_passage')->select('passage_passage.*', 'common_grade.level', 'common_language.name as language_name')->join('common_grade', 'common_grade.id', '=', 'passage_passage.grade_id', 'left')->join('common_language', 'common_language.id', '=', 'passage_passage.language_id', 'left')->where('passage_passage.id', $passage_id)->first();

        $data = [
            'title' => $passage->passage_name,
            'content' => $passage->raw_content,
            'grade_level' => $passage->level,
            'passage_number' => $passage->number,
            'language_name' => $passage->language_name
        ];

        $pdf = Pdf::loadView('pdf', $data);

        return response($pdf->output(), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="api-sample.pdf"');
    }

    /*
     *   Add Student
     */
    public function addStudent(Request $request)
    {
        $level = DB::table('common_level')
            ->where('title', 'Benchmarking')
            ->first();

        $group = DB::table('organisation_group')
            ->where('organisation_id', $request->organisation)
            ->where('grade_id', $request->grade)
            ->where('level_id', $level->id)
            ->first();

        $group_id = $group ? $group->id : null;

        $password = Str::random(12);
        $salt = Str::random(12);
        $iterations = 150000;
        $hashedPassword = "pbkdf2_sha256\${$iterations}\${$salt}\$" . base64_encode(
            hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true)
        );

        // Encrypt first_name and last_name
        $firstName = \Illuminate\Support\Facades\Crypt::encryptString($request->first_name);
        $lastName = \Illuminate\Support\Facades\Crypt::encryptString($request->last_name);

        $user = \App\Models\User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'password' => $hashedPassword,
            'is_superuser' => 'f',
            'username' => Str::uuid(),
            'email' => '',
            'is_staff' => 'f',
            'is_active' => 't',
            'date_joined' => date('Y-m-d H:i:s')
        ]);

        $user_id = $user->id;

        $image = $request->get('picture');
        $imageUrl = '';
        // Extract the base64 part
        if ($image) {
            if (preg_match('/^data:image\/(\w+);base64,/', $image, $type)) {
                $image = substr($image, strpos($image, ',') + 1);
                $type = strtolower($type[1]); // jpg, png, gif

                // Decode the image
                $image = base64_decode($image);

                if ($image === false) {
                    return response()->json(['error' => 'Base64 decode failed'], 400);
                }
            } else {
                return response()->json(['error' => 'Invalid image data'], 400);
            }

            // Create a unique filename
            $filename = uniqid() . '.' . $type;

            // Store the image in S3
            Storage::disk('s3')->put("uploads/{$filename}", $image);

            // Get the URL of the stored image
            $imageUrl = Storage::disk('s3')->url("uploads/{$filename}");
        }

        $student_id = DB::table('access_student')->insertGetId([
            'id' => Str::uuid(),
            'date_of_birth' => $request->date_of_birth,
            'gender' => $request->gender,
            'is_english_second_language' => $request->is_english_second_language,
            'disability' => $request->disability,
            'division' => $request->division ?? null,
            'user_id' => $user_id,
            'grade_id' => $request->grade,
            'organisation_id' => $request->organisation,
            'picture' => $imageUrl,
            'group_id' => $group_id,
            'group_hindi_id' => $group_id,
            'group_marathi_id' => $group_id
        ]);

        foreach (explode(',', $request->languages) as $languageName) {
            $language = DB::table('common_language')->where('name', trim($languageName))->first();
            if ($language) {
                DB::table('access_student_languages')->insert([
                    'student_id' => $student_id,
                    'language_id' => $language->id
                ]);
            }
        }
        return response()->json(['success' => 'student added successfully', 'data' => $student_id], 200);
    }

    /*
     *   Edit Student
     */
    public function editStudent(Request $request)
    {
        $student_id = $request->id;

        //update access_student table
        $student = UserModel::find($student_id);
        if (!$student) {
            return response()->json(['message' => 'Student not found'], 404);
        }

        $level = DB::table('common_level')
            ->where('title', 'Benchmarking')
            ->first();

        $group = DB::table('organisation_group')
            ->where('organisation_id', $request->organisation)
            ->where('grade_id', $request->grade)
            ->where('level_id', $level->id)
            ->first();

        $group_id = $group ? $group->id : null;

        //Upload picture on s3
        $image = $request->get('picture');

        // Extract the base64 part
        if ($image) {
            if (preg_match('/^data:image\/(\w+);base64,/', $image, $type)) {
                $image = substr($image, strpos($image, ',') + 1);
                $type = strtolower($type[1]); // jpg, png, gif

                // Decode the image
                $image = base64_decode($image);

                if ($image === false) {
                    return response()->json(['error' => 'Base64 decode failed'], 400);
                }
            } else {
                return response()->json(['error' => 'Invalid image data'], 400);
            }

            // Create a unique filename
            $filename = uniqid() . '.' . $type;

            // Store the image in S3
            Storage::disk('s3')->put("uploads/{$filename}", $image);

            // Get the URL of the stored image
            $imageUrl = Storage::disk('s3')->url("uploads/{$filename}");
        } else {
            $imageUrl = $student->picture;
        }

        $updateData = [
            'picture' => $imageUrl,
            'date_of_birth' => $request->date_of_birth,
            'gender' => $request->gender,
            'is_english_second_language' => $request->is_english_second_language,
            'disability' => $request->disability,
            'division' => $request->division,
            'grade_id' => $request->grade,
            'organisation_id' => $request->organisation,
        ];

        if ($group_id) {
            $updateData['group_id'] = $group_id;
            $updateData['group_hindi_id'] = $group_id;
            $updateData['group_marathi_id'] = $group_id;
        }

        $student->update($updateData);

        // Parse input languages from request
        $requestedLanguages = array_map('trim', explode(',', $request->languages));

        // Get language IDs for input names
        $requestedLanguageIds = DB::table('common_language')
            ->whereIn('name', $requestedLanguages)
            ->pluck('id')
            ->sort()
            ->values()
            ->toArray();

        // Get current language IDs for this student
        $currentLanguageIds = DB::table('access_student_languages')
            ->where('student_id', $student_id)
            ->pluck('language_id')
            ->sort()
            ->values()
            ->toArray();

        // Check if they are different
        if ($requestedLanguageIds !== $currentLanguageIds) {
            // Delete old
            DB::table('access_student_languages')
                ->where('student_id', $student_id)
                ->delete();

            // Insert new
            $insertData = array_map(function ($languageId) use ($student_id) {
                return [
                    'student_id' => $student_id,
                    'language_id' => $languageId
                ];
            }, $requestedLanguageIds);

            if (!empty($insertData)) {
                DB::table('access_student_languages')->insert($insertData);
            }
        }

        $user_id = $student->user_id;

        // Encrypt & Update Student first_name and last_name
        $userUpdate = [];

        if ($request->filled('first_name')) {
            $userUpdate['first_name'] = Crypt::encryptString($request->first_name);
        }

        if ($request->filled('last_name')) {
            $userUpdate['last_name'] = Crypt::encryptString($request->last_name);
        }

        if (!empty($userUpdate)) {
            DB::table('auth_user')
                ->where('id', $user_id)
                ->update($userUpdate);
        }

        return response()->json(['success' => 'student updated successfully', 'data' => $student_id], 200);
    }

    /*
     *   Add teacher
     */
    public function addTeacher(Request $request)
    {
        // Check if username already exists
        $existingUsername = DB::table('auth_user')->where('username', $request->username)->exists();
        if ($existingUsername) {
            return response()->json(['error' => 'Username already exists.'], 409);
        }

        // Check if email already exists
        $existingEmail = DB::table('auth_user')->where('email', $request->email)->exists();
        if ($existingEmail) {
            return response()->json(['error' => 'Email already exists.'], 409);
        }

        $password = $request->password;
        $salt = Str::random(12);
        $iterations = 150000;
        $hashedPassword = "pbkdf2_sha256\${$iterations}\${$salt}\$" . base64_encode(
            hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true)
        );

        $user_id = DB::table('auth_user')->insertGetId([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'password' => $hashedPassword,
            'is_superuser' => 'f',
            'username' => $request->username,
            'email' => $request->email,
            'is_staff' => 'f',
            'is_active' => 't',
            'date_joined' => date('Y-m-d H:i:s')
        ]);

        $teacher_id = DB::table('access_teacher')->insertGetId([
            'id' => Str::uuid(),
            'gender' => $request->gender,
            'division' => $request->division,
            'user_id' => $user_id,
            'organisation_id' => $request->organisation,
            'type' => $request->type,
            'division' => $request->division
        ]);

        if ($request->groups) {
            foreach (explode(',', $request->groups) as $group) {
                if ($group) {
                    DB::table('access_teacher_groups')->insert([
                        'teacher_id' => $teacher_id,
                        'group_id' => $group
                    ]);
                }
            }
        }

        if ($request->languages) {
            foreach (explode(',', $request->languages) as $languageName) {
                $language = DB::table('common_language')->where('name', trim($languageName))->first();
                if ($language) {
                    DB::table('access_teacher_languages')->insert([
                        'teacher_id' => $teacher_id,
                        'language_id' => $language->id
                    ]);
                }
            }
        }

        return response()->json(['success' => 'teacher added successfully', 'data' => $teacher_id], 200);
    }

    /*
     *   Get Students
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
        $group_id = $this->sanitizeUuid($request->get('group_id', $request->get('group__id', '')));
        $lang = $request->get('lang', $request->get('language', $request->get('languages__name', '')));
        $teacher_id = $this->sanitizeUuid($request->get('teacher_id', $request->get('teachers__id', '')));


        // Dynamic ordering logic
        $ordering = $request->query('ordering', '-created_at');
        $orderColumn = 'u.date_joined';
        $orderDirection = 'desc';

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

        $selectedGroupColumn = 's.group_id';
        if ($lang) {
            $langLower = strtolower($lang);
            if ($langLower == 'hindi') {
                $selectedGroupColumn = 's.group_hindi_id';
            } elseif ($langLower == 'marathi') {
                $selectedGroupColumn = 's.group_marathi_id';
            }
        }

        $hasLanguageParam = $request->has('language') || $request->has('languages__name');

        // Pre-fetch language ID for safe interpolation
        $languageLookup = DB::table('common_language')->where('name', 'ilike', $lang ?: 'English')->first();
        if (!$languageLookup) {
            $languageLookup = DB::table('common_language')->where('name', 'ilike', 'English')->first();
        }
        $langId = $languageLookup ? $languageLookup->id : null;

        $query = DB::table('access_student as s')
            ->select([
                's.id',
                DB::raw("json_build_object(
            'id', g.id,
            'teachers_count', (
                SELECT COUNT(DISTINCT atg.teacher_id)
                FROM access_teacher_groups atg
                JOIN access_teacher as t ON atg.teacher_id = t.id
                JOIN access_teacher_languages as atl ON t.id = atl.teacher_id
                WHERE atg.group_id = g.id
                AND t.organisation_id = g.organisation_id
                AND atl.language_id = '" . $langId . "'
            ),
            'students_count', (
                SELECT COUNT(*)
                FROM access_student as ast
                " . ($hasLanguageParam ? "JOIN access_student_languages as astl ON ast.id = astl.student_id" : "") . "
                WHERE ast." . (str_replace('s.', '', $selectedGroupColumn)) . " = g.id
                " . ($hasLanguageParam ? "AND astl.language_id = '" . $langId . "'" : "") . "
                " . (!empty($teacherDivisions) ? "AND UPPER(ast.division) IN (" . implode(',', array_map(fn($d) => "'" . $d . "'", $teacherDivisions)) . ")" : "") . "
            ),
            'last_assessment_time', (
                SELECT ass.created
                FROM assessment_assessment ass
                JOIN access_student as asst ON ass.student_id = asst.id
                JOIN passage_passage pp ON ass.passage_id = pp.id
                WHERE pp.language_id = '" . $langId . "'
                AND (ass.group_id = g.id OR asst." . (str_replace('s.', '', $selectedGroupColumn)) . " = g.id)
                " . (!empty($teacherDivisions) ? "AND UPPER(asst.division) IN (" . implode(',', array_map(fn($d) => "'" . $d . "'", $teacherDivisions)) . ")" : "") . "
                ORDER BY ass.created DESC
                LIMIT 1
            ),
            'goal', (
                SELECT cgp.goal
                FROM common_groupingparameter cgp
                WHERE cgp.grade_id = g.grade_id
                AND cgp.level_id = g.level_id
                AND cgp.language_id = '" . $langId . "'
                AND (
                    (
                        cgp.benchmark_template_id = (
                            SELECT id FROM benchmark_templates
                            WHERE organisation_ids @> jsonb_build_array(g.organisation_id::text)
                            AND language_ids @> jsonb_build_array('" . $langId . "'::text)
                            LIMIT 1
                        )
                        AND cgp.assessment_period = COALESCE(
                            (SELECT assessment_period FROM organisation_organisation_languages WHERE organisation_id = g.organisation_id AND language_id = '" . $langId . "' LIMIT 1),
                            (SELECT assessment_period FROM benchmark_templates WHERE organisation_ids @> jsonb_build_array(g.organisation_id::text) AND language_ids @> jsonb_build_array('" . $langId . "'::text) LIMIT 1)
                        )
                    )
                    OR (
                        cgp.benchmark_template_id IS NULL
                        AND cgp.assessment_period = COALESCE(
                            (SELECT assessment_period FROM organisation_organisation_languages WHERE organisation_id = g.organisation_id AND language_id = '" . $langId . "' LIMIT 1),
                            (SELECT assessment_period FROM benchmark_templates WHERE organisation_ids @> jsonb_build_array(g.organisation_id::text) AND language_ids @> jsonb_build_array('" . $langId . "'::text) LIMIT 1)
                        )
                    )
                    OR (
                        cgp.benchmark_template_id = (
                            SELECT id FROM benchmark_templates
                            WHERE organisation_ids @> jsonb_build_array(g.organisation_id::text)
                            AND language_ids @> jsonb_build_array('" . $langId . "'::text)
                            LIMIT 1
                        )
                        AND cgp.assessment_period IS NULL
                    )
                    OR (
                        cgp.benchmark_template_id IS NULL
                        AND cgp.assessment_period IS NULL
                    )
                )
                ORDER BY
                    (
                        cgp.benchmark_template_id = (
                            SELECT id FROM benchmark_templates
                            WHERE organisation_ids @> jsonb_build_array(g.organisation_id::text)
                            AND language_ids @> jsonb_build_array('" . $langId . "'::text)
                            LIMIT 1
                        )
                        AND cgp.assessment_period = COALESCE(
                            (SELECT assessment_period FROM organisation_organisation_languages WHERE organisation_id = g.organisation_id AND language_id = '" . $langId . "' LIMIT 1),
                            (SELECT assessment_period FROM benchmark_templates WHERE organisation_ids @> jsonb_build_array(g.organisation_id::text) AND language_ids @> jsonb_build_array('" . $langId . "'::text) LIMIT 1)
                        )
                    ) DESC,
                    (
                        cgp.benchmark_template_id IS NULL
                        AND cgp.assessment_period = COALESCE(
                            (SELECT assessment_period FROM organisation_organisation_languages WHERE organisation_id = g.organisation_id AND language_id = '" . $langId . "' LIMIT 1),
                            (SELECT assessment_period FROM benchmark_templates WHERE organisation_ids @> jsonb_build_array(g.organisation_id::text) AND language_ids @> jsonb_build_array('" . $langId . "'::text) LIMIT 1)
                        )
                    ) DESC,
                    (
                        cgp.benchmark_template_id = (
                            SELECT id FROM benchmark_templates
                            WHERE organisation_ids @> jsonb_build_array(g.organisation_id::text)
                            AND language_ids @> jsonb_build_array('" . $langId . "'::text)
                            LIMIT 1
                        )
                        AND cgp.assessment_period IS NULL
                    ) DESC,
                    (
                        cgp.benchmark_template_id IS NULL
                        AND cgp.assessment_period IS NULL
                    ) DESC
                LIMIT 1
            ),
            'current_average', (
                SELECT AVG(ass.last_word_index + 1 - ass.no_error_words)
                FROM assessment_assessment ass
                JOIN passage_passage pp ON ass.passage_id = pp.id
                JOIN access_student asst ON ass.student_id = asst.id
                JOIN access_student_languages asst_l ON asst.id = asst_l.student_id
                WHERE ass.group_id = g.id
                AND asst." . (str_replace('s.', '', $selectedGroupColumn)) . " = g.id
                AND asst_l.language_id = '" . $langId . "'
                AND pp.language_id = '" . $langId . "'
                " . (!empty($teacherDivisions) ? "AND UPPER(asst.division) IN (" . implode(',', array_map(fn($d) => "'" . $d . "'", $teacherDivisions)) . ")" : "") . "
            ),
            'created', g.created,
            'updated', g.updated,
            'title', g.title,
            'description', g.description,
            'organisation', g.organisation_id,
            'grade', g.grade_id,
            'level', g.level_id
        ) as group_detail"),
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
                DB::raw("COALESCE(s.division, '') as division"),
                'g.title as title',
                's.date_of_birth as date_of_birth',
                's.gender as gender',
                's.is_english_second_language as is_english_second_language',
                's.disability as disability',
                's.picture as picture',
                's.user_id as user',
                's.organisation_id as organisation',
                's.grade_id as grade',
                's.group_id as group',
                's.group_hindi_id as group_hindi',
                's.group_marathi_id as group_marathi',
                DB::raw("(SELECT ass.created FROM assessment_assessment as ass WHERE ass.student_id = s.id ORDER BY ass.created DESC LIMIT 1) as last_assessment_time"),
                DB::raw("EXISTS(SELECT 1 FROM assessment_assessment as ass WHERE ass.student_id = s.id) as is_assessed"),
            ])
            ->leftJoin('organisation_group as g', 'g.id', '=', $selectedGroupColumn)
            ->join('organisation_organisation as o', 'o.id', '=', 's.organisation_id')
            ->join('common_grade as gr', 'gr.id', '=', 's.grade_id')
            ->join('auth_user as u', 'u.id', '=', 's.user_id');

        if ($hasLanguageParam) {
            $query->join('access_student_languages as asl_filter', 'asl_filter.student_id', '=', 's.id')
                ->where('asl_filter.language_id', $langId);
        }

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

        if ($lang) {
            $query->whereNotNull($selectedGroupColumn);
        }

        if ($teacher_id) {
            if (!empty($teacherDivisions)) {
                $query->whereIn(DB::raw('UPPER(s.division)'), $teacherDivisions);
            }
        }

        // Define common mapping function for decryption and cleanup
        $mapper = function ($item) use ($lang, $langId, $request) {
            if (is_string($item->group_detail)) {
                $item->group_detail = json_decode($item->group_detail, true);
                if ($item->group_detail && isset($item->group_detail['title'])) {
                    $item->group_detail['title'] = $this->translateTitle($item->group_detail['title'], $lang ?: 'English');
                }
            }
            if (is_array($item->group_detail) && $langId) {
                $item->group_detail['goal'] = $this->resolveBenchmarkGoal(
                    $item->group_detail['organisation'] ?? null,
                    $langId,
                    $item->group_detail['grade'] ?? null,
                    $item->group_detail['level'] ?? null,
                    $request->input('assessment_period')
                );
            }
            if ($item->title) {
                $item->title = $this->translateTitle($item->title, $lang ?: 'English');
            }
            if (is_string($item->user_detail)) {
                $item->user_detail = json_decode($item->user_detail, true);
            }
            if (is_string($item->languages)) {
                $item->languages = json_decode($item->languages, true) ?: [];
            } elseif (is_null($item->languages)) {
                $item->languages = [];
            }

            $item->is_assessed = (bool) $item->is_assessed;

            try {
                if (!empty($item->user_detail['first_name']) && str_contains($item->user_detail['first_name'], 'eyJpdiI6')) {
                    $item->user_detail['first_name'] = Crypt::decryptString($item->user_detail['first_name']);
                }
                if (!empty($item->user_detail['last_name']) && str_contains($item->user_detail['last_name'], 'eyJpdiI6')) {
                    $item->user_detail['last_name'] = Crypt::decryptString($item->user_detail['last_name']);
                }
                $item->user_detail['full_name'] = trim(($item->user_detail['first_name'] ?? '') . ' ' . ($item->user_detail['last_name'] ?? ''));
            } catch (\Exception $e) {
                \Log::error('Error decrypting user details: ' . $e->getMessage());
            }

            return $item;
        };

        // If search is present, fetch all results and filter them
        if ($search) {
            $allResults = $query->groupBy('s.id', 'g.id', 'o.id', 'gr.id', 'u.id')
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

            // Removed the offset-reset logic as it was causing issues with the pagination
            // if ($offset >= $total && $total > 0) {
            //     $offset = 0;
            // }
            $results = $filteredResults->slice($offset, $limit)->values();
        } else {
            $totalCountQuery = DB::table('access_student as s')
                ->join('auth_user as u', 'u.id', '=', 's.user_id')
                ->join('organisation_organisation as o', 'o.id', '=', 's.organisation_id')
                ->join('common_grade as gr', 'gr.id', '=', 's.grade_id');

            if ($hasLanguageParam) {
                $totalCountQuery->join('access_student_languages as asl_filter', 'asl_filter.student_id', '=', 's.id')
                    ->where('asl_filter.language_id', $langId);
            }

            // If group_id or lang is present, join with organisation_group
            if ($group_id || $lang) {
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

            if ($lang) {
                $totalCountQuery->whereNotNull($selectedGroupColumn);
            }

            if ($teacher_id) {
                if (!empty($teacherDivisions)) {
                    $totalCountQuery->whereIn(DB::raw('UPPER(s.division)'), $teacherDivisions);
                }
            }

            $total = $totalCountQuery->count(DB::raw('DISTINCT s.id'));

            // Removed the offset-reset logic as it was causing issues with the pagination
            // if ($offset >= $total && $total > 0) {
            //     $offset = 0;
            // }

            $results = $query->groupBy('s.id', 'g.id', 'o.id', 'gr.id', 'u.id')
                ->offset($offset)
                ->limit($limit)
                ->orderBy($orderColumn, $orderDirection)
                ->get()
                ->map($mapper);
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

    /*
     *   Get Passage PDF New - Enhanced for Devanagari Script with mPDF
     *   Properly renders Hindi/Marathi with matras and half letters using OpenType Layout
     */
    public function getPassagePDFNew(Request $request, $id)
    {
        $passage = DB::table('passage_passage')
            ->select('passage_passage.*', 'common_grade.level', 'common_language.name as language_name')
            ->join('common_grade', 'common_grade.id', '=', 'passage_passage.grade_id', 'left')
            ->join('common_language', 'common_language.id', '=', 'passage_passage.language_id', 'left')
            ->where('passage_passage.id', $this->sanitizeUuid($id))
            ->first();

        if (!$passage) {
            return response()->json(['error' => 'Passage not found'], 404);
        }

        $data = [
            'title' => $passage->passage_name,
            'content' => $passage->raw_content,
            'grade_level' => $passage->level,
            'passage_number' => $passage->number,
            'language_name' => $passage->language_name
        ];

        // Configure mPDF with proper font directories
        $defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();
        $fontDirs = $defaultConfig['fontDir'];

        $defaultFontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
        $fontData = $defaultFontConfig['fontdata'];

        // Initialize mPDF with Devanagari support
        // Using FreeSans which has better mPDF compatibility than Noto Sans
        $mpdf = new \Mpdf\Mpdf([
            'tempDir' => storage_path('app/mpdf'),
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 15,
            'margin_bottom' => 15,
            'default_font' => 'freesans',  // FreeSans has good Devanagari support
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
        ]);

        // Render the view to HTML
        $html = view('passage_pdf', $data)->render();

        // Write HTML to mPDF
        $mpdf->WriteHTML($html);

        // Generate safe filename
        $filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $passage->passage_name) . '.pdf';

        // Output PDF
        return response($mpdf->Output($filename, 'S'), 200)
            ->header('Content-Type', 'application/pdf; charset=utf-8')
            ->header('Content-Disposition', 'inline; filename="' . $filename . '"')
            ->header('Cache-Control', 'private, max-age=0, must-revalidate')
            ->header('Pragma', 'public');
    }
    public function getStudent(Request $request, $id)
    {
        $student = Student::with(['user', 'grade', 'languages', 'group'])->find($this->sanitizeUuid($id));

        if (!$student) {
            return response()->json(['message' => 'Student not found'], 404);
        }

        // Decrypt names
        $firstName = $student->user->first_name;
        $lastName  = $student->user->last_name;

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

        // Language and Grouping Parameters matching Python implementation
        $langName = $request->get('lang', $request->get('language', $request->get('languages__name', 'English')));
        $language = DB::table('common_language')->where('name', 'ilike', $langName)->first();
        if (!$language) {
            $language = DB::table('common_language')->where('name', 'ilike', 'English')->first();
        }
        $languageId = $language ? $language->id : null;

        $benchmarkTemplateId = $this->resolveBenchmarkTemplateId($student->organisation_id, $languageId);

        // Group mapping based on language
        $groupColumn = 'group_id';
        if (strtolower($langName) === 'hindi') {
            $groupColumn = 'group_hindi_id';
        } elseif (strtolower($langName) === 'marathi') {
            $groupColumn = 'group_marathi_id';
        }
        $studentGroupId = $student->$groupColumn;

        // Organisation
        $org = DB::table('organisation_organisation')
            ->where('id', $student->organisation_id)
            ->first();
        $orgTitle = $org->title ?? '';

        // Group detail (Based on language)
        $groupDetail = null;
        if ($studentGroupId) {
            $group = DB::table('organisation_group as g')
                ->where('g.id', $studentGroupId)
                ->first();

            if ($group) {
                // Match Python logic: last assessment time for group in this language
                $lastAssessmentTime = DB::table('assessment_assessment as a')
                    ->join('passage_passage as p', 'a.passage_id', '=', 'p.id')
                    ->where('a.group_id', $group->id)
                    ->where('p.language_id', $languageId)
                    ->orderByDesc('a.created')
                    ->value('a.created');

                // Match Python logic: students in this group
                $studentsCountQuery = DB::table('access_student')
                    ->where('access_student.' . $groupColumn, $group->id);

                if ($languageId) {
                    $studentsCountQuery->join('access_student_languages as sl', 'access_student.id', '=', 'sl.student_id')
                        ->where('sl.language_id', $languageId);
                }
                $studentsCountInGroup = $studentsCountQuery->count(DB::raw('DISTINCT access_student.id'));

                // Match Python logic: teachers in this group
                $teachersCountQuery = DB::table('access_teacher')
                    ->join('access_teacher_groups as tg', 'tg.teacher_id', '=', 'access_teacher.id')
                    ->where('tg.group_id', $group->id);

                if ($languageId) {
                    $teachersCountQuery->join('access_teacher_languages as tl', 'tl.teacher_id', '=', 'access_teacher.id')
                        ->where('tl.language_id', $languageId);
                }
                $teachersCountInGroup = $teachersCountQuery->count(DB::raw('DISTINCT access_teacher.id'));

                // Match Python logic: average performance of students in the group for the language
                $avgCorrect = DB::table('assessment_assessment as a')
                    ->join('passage_passage as p', 'a.passage_id', '=', 'p.id')
                    ->join('access_student as s', 'a.student_id', '=', 's.id')
                    ->join('access_student_languages as sl', 's.id', '=', 'sl.student_id')
                    ->where('a.group_id', $group->id)
                    ->where('s.' . $groupColumn, $group->id)
                    ->where('sl.language_id', $languageId)
                    ->where('p.language_id', $languageId)
                    ->avg(DB::raw('a.last_word_index + 1 - a.no_error_words'));
                $goal = $this->resolveBenchmarkGoal(
                    $student->organisation_id,
                    $languageId,
                    $group->grade_id,
                    $group->level_id,
                    $request->input('assessment_period')
                );

                if (!$goal || $goal == "") {
                    $benchmarkTemplateId = null;
                }

                $groupDetail = [
                    'id'                   => $group->id,
                    'teachers_count'       => (int) $teachersCountInGroup,
                    'students_count'       => (int) $studentsCountInGroup,
                    'last_assessment_time' => $lastAssessmentTime ? $this->formatAssessmentTime($lastAssessmentTime) : null,
                    'goal'                 => (float) ($goal ?? 0),
                    'current_average'      => (float) ($avgCorrect ?? 0),
                    'created'              => $group->created ? $this->formatDateTime($group->created) : null,
                    'updated'              => $group->updated ? $this->formatDateTime($group->updated) : null,
                    'title'                => $group->title,
                    'description'          => $group->description,
                    'organisation'         => $group->organisation_id,
                    'grade'                => $group->grade_id,
                    'level'                => $group->level_id,
                ];
            }
        }

        // Last assessment time & is_assessed for the student
        $lastAssessmentTimeForStudent = DB::table('assessment_assessment')
            ->where('student_id', $student->id)
            ->orderByDesc('created')
            ->value('created');

        $isAssessed = DB::table('assessment_assessment')
            ->where('student_id', $student->id)
            ->exists();

        return response()->json([
            'id'           => $student->id,
            'group_detail' => $groupDetail,
            'user_detail'  => [
                'username'     => $student->user->username,
                'first_name'   => $firstName,
                'last_name'    => $lastName,
                'role_id'      => $student->id,
                'full_name'    => trim($firstName . ' ' . $lastName),
                'email'        => $student->user->email,
                'role'         => 'Student',
                'organisation' => [
                    'id'    => $student->organisation_id,
                    'title' => $orgTitle,
                ],
                'groups'       => $groupDetail['title'] ?? null,
            ],
            'organisation_title'          => $orgTitle,
            'grade_title'                 => $student->grade->title ?? '',
            'role'                        => 'Student',
            'last_assessment_time'        => $lastAssessmentTimeForStudent ? $this->formatAssessmentTime($lastAssessmentTimeForStudent) : null,
            'is_assessed'                 => $isAssessed,
            'languages'                   => $student->languages->pluck('name')->values(),
            'division'                    => $student->division ?? null,
            'date_of_birth'               => $student->date_of_birth,
            'gender'                      => $student->gender,
            'is_english_second_language'  => $student->is_english_second_language,
            'disability'                  => $student->disability,
            'picture'                     => $student->picture ?: null,
            'user'                        => $student->user_id,
            'organisation'                => $student->organisation_id,
            'grade'                       => $student->grade_id,
            'group'                       => $student->group_id,
            'group_hindi'                 => $student->group_hindi_id,
            'group_marathi'               => $student->group_marathi_id,
            'benchmark_template_id'       => $benchmarkTemplateId,
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
}
