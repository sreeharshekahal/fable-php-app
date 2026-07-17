<?php

namespace App\Http\Controllers;

use App\Models\Accuracy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use App\Models\Assessment;
use App\Models\User;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\OrganisationGroup;
use App\Models\Grade;
use App\Models\Organisation;
use App\Models\Passage;
use App\Models\Checklist;
use App\Models\PassageWord;
use App\Models\GroupingParameter;
use App\Models\Language;
use App\Models\Level;
use App\Models\BenchmarkTemplate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssessmentController extends Controller
{
    /**
     * Gender integer → human-readable string
     */
    private function genderLabel($val): ?string
    {
        if ($val === null)
            return null;
        $map = [0 => 'Male', 1 => 'Female', 2 => 'Other'];
        return $map[(int) $val] ?? (string) $val;
    }

    public function formatDateTime($val): ?string
    {
        if (!$val) return null;
        return \Carbon\Carbon::parse($val)->format('Y-m-d\TH:i:s.uP');
    }

    /**
     * Decrypt a name field, returning the original value on failure.
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

    public function getAssessments(Request $request)
    {
        $limit = $request->input('limit', 50);
        $offset = $request->input('offset', 0);
        $ordering = $request->input('ordering');

        $query = DB::table('assessment_assessment as a')
            ->leftJoin('access_student as s', 'a.student_id', '=', 's.id')
            ->leftJoin('auth_user as u', 's.user_id', '=', 'u.id')
            ->leftJoin('passage_passage as p', 'a.passage_id', '=', 'p.id')
            ->leftJoin('access_teacher as t', 'a.conducted_by_id', '=', 't.id')
            ->leftJoin('auth_user as conductor', 't.user_id', '=', 'conductor.id')
            ->leftJoin('organisation_organisation as org', 'a.organisation_id', '=', 'org.id')
            ->leftJoin('organisation_group as grp', 'a.group_id', '=', 'grp.id')
            ->leftJoin('common_grade as grade', 'a.grade_id', '=', 'grade.id')
            ->leftJoin('common_grade as pgrade', 'p.grade_id', '=', 'pgrade.id')
            ->select(
                'a.*',
                'u.first_name as student_first_name',
                'u.last_name as student_last_name',
                's.gender as student_gender',
                'p.passage_name as passage_title',
                'p.grade_id as passage_grade_id',
                'p.language_id as passage_language_id',
                'p.raw_content',
                'p.number as passage_no',
                'pgrade.level as passage_grade_level',
                'grp.title as group_title',
                'grp.level_id as group_level_id',
                'conductor.first_name as conductor_first_name',
                'conductor.last_name as conductor_last_name',
                'org.title as organisation_name',
                'grade.level as grade_level',
                'a.assessment_period'
            );

        // --- Filters ---
        if ($request->filled('student__id')) {
            $query->where('a.student_id', $request->input('student__id'));
        }
        if ($request->filled('passage__id')) {
            $query->where('a.passage_id', $request->input('passage__id'));
        }
        if ($request->filled('conducted_by__id')) {
            $query->where('a.conducted_by_id', $request->input('conducted_by__id'));
        }
        if ($request->filled('group__id')) {
            $query->where('a.group_id', $request->input('group__id'));
        }
        if ($request->filled('type')) {
            $query->where('a.type', $request->input('type'));
        }
        if ($request->filled('organisation')) {
            $query->where('a.organisation_id', $request->input('organisation'));
        }
        if ($request->filled('organisation__id')) {
            $query->where('a.organisation_id', $request->input('organisation__id'));
        }
        if ($request->filled('grade__id')) {
            $query->where('a.grade_id', $request->input('grade__id'));
        }

        // Language Filters
        if ($request->filled('student__languages__name')) {
            $val = $request->input('student__languages__name');
            $query->whereExists(function ($q) use ($val) {
                $q->select(DB::raw(1))
                    ->from('access_student_languages as asl')
                    ->join('common_language as cl', 'asl.language_id', '=', 'cl.id')
                    ->whereColumn('asl.student_id', 'a.student_id')
                    ->where('cl.name', $val);
            });
        }
        if ($request->filled('conducted_by__languages__name')) {
            $val = $request->input('conducted_by__languages__name');
            $query->whereExists(function ($q) use ($val) {
                $q->select(DB::raw(1))
                    ->from('access_teacher_languages as atl')
                    ->join('common_language as cl', 'atl.language_id', '=', 'cl.id')
                    ->whereColumn('atl.teacher_id', 'a.conducted_by_id')
                    ->where('cl.name', $val);
            });
        }
        if ($request->filled('passage__language__name') || $request->filled('language')) {
            $val = $request->input('passage__language__name') ?? $request->input('language');
            $query->whereExists(function ($q) use ($val) {
                $q->select(DB::raw(1))
                    ->from('passage_passage as pp')
                    ->join('common_language as cl', 'pp.language_id', '=', 'cl.id')
                    ->whereColumn('pp.id', 'a.passage_id')
                    ->where('cl.name', $val);
            });
        }
        if ($request->filled('organisation__languages__name')) {
            $val = $request->input('organisation__languages__name');
            $query->whereExists(function ($q) use ($val) {
                $q->select(DB::raw(1))
                    ->from('organisation_organisation_languages as ool')
                    ->join('common_language as cl', 'ool.language_id', '=', 'cl.id')
                    ->whereColumn('ool.organisation_id', 'a.organisation_id')
                    ->where('cl.name', $val);
            });
        }
        if ($request->filled('checklists__language__name')) {
            $val = $request->input('checklists__language__name');
            $query->whereExists(function ($q) use ($val) {
                $q->select(DB::raw(1))
                    ->from('assessment_assessment_checklists as aac')
                    ->join('assessment_checklist as ac', 'aac.checklist_id', '=', 'ac.id')
                    ->join('common_language as cl', 'ac.language_id', '=', 'cl.id')
                    ->whereColumn('aac.assessment_id', 'a.id')
                    ->where('cl.name', $val);
            });
        }

        // Date range
        if ($request->filled('from')) {
            $query->whereDate('a.created', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('a.created', '<=', $request->input('to'));
        }

        // --- Ordering ---
        if ($ordering) {
            $dir = 'asc';
            if (str_starts_with($ordering, '-')) {
                $dir = 'desc';
                $ordering = substr($ordering, 1);
            }
            $col = match ($ordering) {
                'created' => 'a.created',
                'updated' => 'a.updated',
                default => $ordering,
            };
            $query->orderBy($col, $dir);
        } else {
            $query->orderBy('a.created', 'desc');
        }

        $count = $query->count();
        $results = $query->offset($offset)->limit($limit)->get();

        $assessmentIds = $results->pluck('id')->toArray();

        // -- History lookup --
        $histories = DB::table('assessment_assessmentstudentgrouphistory as h')
            ->leftJoin('organisation_group as fg', 'h.from_group_id', '=', 'fg.id')
            ->leftJoin('organisation_group as tg', 'h.to_group_id', '=', 'tg.id')
            ->whereIn('h.assessment_id', $assessmentIds)
            ->select('h.assessment_id', 'fg.title as from_group_title', 'tg.title as to_group_title')
            ->get()
            ->keyBy('assessment_id');

        // -- Goals lookup (Benchmark Template) --
        $passageLanguageIds = $results->pluck('passage_language_id')->filter()->unique()->values()->toArray();
        $orgIds = $results->pluck('organisation_id')->filter()->unique()->values()->toArray();
        $allGoals = collect();

        $templates = BenchmarkTemplate::all();
        $templateMap = [];
        $activePeriodMap = [];
        foreach ($orgIds as $oid) {
            foreach ($passageLanguageIds as $lid) {
                $template = $templates->first(function ($t) use ($oid, $lid) {
                    return in_array($oid, $t->organisation_ids ?? [], true)
                        && in_array($lid, $t->language_ids ?? [], true);
                });

                $templateMap[$oid][$lid] = $template?->id;
                $activePeriodMap[$oid][$lid] = $template?->activeAssessmentPeriodForLanguage($lid);
            }
        }

        if (!empty($passageLanguageIds)) {
            $allGoals = DB::table('common_groupingparameter as gp')
                ->join('organisation_group as og', function ($join) {
                    $join->on('gp.level_id', '=', 'og.level_id')
                        ->on('gp.grade_id', '=', 'og.grade_id');
                })
                ->whereIn('gp.language_id', $passageLanguageIds)
                ->whereIn('og.organisation_id', $orgIds)
                ->select('og.id as group_id', 'gp.language_id', 'gp.goal', 'gp.benchmark_template_id', 'gp.assessment_period')
                ->get();
        }

        // -- Linked checklist IDs --
        $linkedChecklists = DB::table('assessment_assessment_checklists as ac')
            ->whereIn('ac.assessment_id', $assessmentIds)
            ->select('ac.assessment_id', 'ac.checklist_id')
            ->get()
            ->groupBy('assessment_id');

        // -- All checklists for each passage language --
        $allChecklistsByLanguage = collect();
        if (!empty($passageLanguageIds)) {
            $allChecklistsByLanguage = DB::table('assessment_checklist as c')
                ->leftJoin('common_language as l', 'c.language_id', '=', 'l.id')
                ->whereIn('c.language_id', $passageLanguageIds)
                ->select('c.id', 'c.title', 'c.type', 'c.language_id', 'c.created', 'c.updated', 'l.name as language_name')
                ->get()
                ->groupBy('language_id');
        }

        // -- Error words --
        $errorWords = DB::table('assessment_errorwords as ew')
            ->leftJoin('passage_word as pw', 'ew.word_id', '=', 'pw.id')
            ->whereIn('ew.assessment_id', $assessmentIds)
            ->select('ew.assessment_id', 'ew.word_id', 'pw.text as word_text')
            ->get()
            ->groupBy('assessment_id');

        $formattedResults = $results->map(function ($row) use ($linkedChecklists, $allChecklistsByLanguage, $errorWords, $histories, $allGoals, $templateMap, $activePeriodMap) {
            $linked = $linkedChecklists->get($row->id, collect());
            $selectedIds = $linked->pluck('checklist_id')->values()->toArray();

            $allChecklists = $allChecklistsByLanguage->get($row->passage_language_id, collect());
            $checklistsDetails = $allChecklists->map(fn($c) => [
                'id' => $c->id,
                'selected' => in_array($c->id, $selectedIds),
                'language' => $c->language_name,
                'created' => $this->formatDateTime($c->created),
                'updated' => $this->formatDateTime($c->updated),
                'title' => $c->title,
                'type' => $c->type,
            ])->values()->all();

            $rowErrors = $errorWords->get($row->id, collect());
            $errorWordIds = $rowErrors->pluck('word_id')->values()->all();
            $wordDetails = $rowErrors->map(fn($ew) => [
                'id' => $ew->word_id,
                'text' => $ew->word_text,
            ])->values()->all();

            $history = $histories->get($row->id);
            $benchmarkTemplateId = $templateMap[$row->organisation_id][$row->passage_language_id] ?? null;
            $resolvedPeriod = $row->assessment_period
                ?: ($activePeriodMap[$row->organisation_id][$row->passage_language_id] ?? null);

            $goal = null;

            // 1. Check for benchmark_template_id not null and assessment_period not null
            if ($benchmarkTemplateId && $resolvedPeriod) {
                $goal = $allGoals->where('group_id', $row->group_id)
                    ->where('language_id', $row->passage_language_id)
                    ->where('benchmark_template_id', $benchmarkTemplateId)
                    ->where('assessment_period', $resolvedPeriod)
                    ->first();
            }

            // 2. Check for benchmark_template_id null and assessment_period not null
            if (!$goal && $resolvedPeriod) {
                $goal = $allGoals->where('group_id', $row->group_id)
                    ->where('language_id', $row->passage_language_id)
                    ->whereNull('benchmark_template_id')
                    ->where('assessment_period', $resolvedPeriod)
                    ->first();
            }

            // 3. Fallback: check for benchmark_template_id not null and assessment_period null
            if (!$goal && $benchmarkTemplateId) {
                $goal = $allGoals->where('group_id', $row->group_id)
                    ->where('language_id', $row->passage_language_id)
                    ->where('benchmark_template_id', $benchmarkTemplateId)
                    ->whereNull('assessment_period')
                    ->first();
            }

            // 4. Check for both benchmark_template_id null and assessment_period null
            if (!$goal) {
                $goal = $allGoals->where('group_id', $row->group_id)
                    ->where('language_id', $row->passage_language_id)
                    ->whereNull('benchmark_template_id')
                    ->whereNull('assessment_period')
                    ->first();
            }

            // Decrypt names
            $firstName = $this->tryDecrypt($row->student_first_name);
            $lastName  = $this->tryDecrypt($row->student_last_name);

            return [
                'id' => $row->id,
                'has_recording' => $row->audio || $row->retell_audio,
                'retell_audio' => $row->retell_audio ? $row->retell_audio : null,
                'audio' => $row->audio ? $row->audio : null,
                'passage_title' => $row->passage_grade_level . '-' . $row->passage_no . ' ' . $row->passage_title,
                'passage_grade' => $row->passage_grade_level,
                'total_words_count' => ($row->last_word_index + 1),
                'student_name' => trim($firstName . ' ' . $lastName) ?: null,
                'student_first_name' => $firstName,
                'student_last_name' => $lastName,
                'correct_words_count' => ($row->last_word_index + 1) - $row->no_error_words,
                'accuracy' => ($row->last_word_index + 1) > 0
                    ? (($row->last_word_index + 1) - $row->no_error_words) / ($row->last_word_index + 1) * 100
                    : 0,
                'grade_level' => $row->grade_level,
                'group_name' => $row->group_title,
                'conductor_first_name' => $row->conductor_first_name,
                'conductor_last_name' => $row->conductor_last_name,
                'passage_no' => $row->passage_grade_level . '-' . $row->passage_no,
                'student_gender' => $this->genderLabel($row->student_gender),
                'organisation_name' => $row->organisation_name,
                'benchmark_goal' => (int) ($row->benchmark_goal ?: ($goal->goal ?? 0)),
                'from_group' => $history ? $history->from_group_title : ($row->type == 1 ? 'G' . $row->grade_level . ' Benchmarking' : $row->group_title),
                'to_group' => $history ? $history->to_group_title : ($row->type == 1 ? $row->group_title : null),
                'created' => $this->formatDateTime($row->created),
                'updated' => $this->formatDateTime($row->updated ?? $row->created),
                'type' => $row->type,
                'last_word_index' => $row->last_word_index,
                'last_word' => $row->last_word,
                'notes' => $row->notes,
                'no_error_words' => $row->no_error_words,
                'passage' => $row->passage_id,
                'student' => $row->student_id,
                'conducted_by' => $row->conducted_by_id,
                'organisation' => $row->organisation_id,
                'group' => $row->group_id,
                'grade' => $row->grade_id,
                'error_words' => $errorWordIds,
                'checklists' => $selectedIds,
                'checklists_details' => $checklistsDetails,
                'word_details' => $wordDetails,
                'assessment_period' => $row->assessment_period,
            ];
        });

        $next = null;
        if ($offset + $limit < $count) {
            $next = $request->fullUrlWithQuery(['offset' => $offset + $limit, 'limit' => $limit]);
        }
        $previous = null;
        if ($offset > 0) {
            $prevOffset = max(0, $offset - $limit);
            $previous = $request->fullUrlWithQuery(['offset' => $prevOffset, 'limit' => $limit]);
        }

        return response()->json([
            'count' => $count,
            'next' => $next,
            'previous' => $previous,
            'results' => $formattedResults,
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }


    public function getAssessmentDetails(Request $request, $id)
    {
        $row = DB::table('assessment_assessment as a')
            ->leftJoin('access_student as s', 'a.student_id', '=', 's.id')
            ->leftJoin('auth_user as u', 's.user_id', '=', 'u.id')
            ->leftJoin('passage_passage as p', 'a.passage_id', '=', 'p.id')
            ->leftJoin('access_teacher as t', 'a.conducted_by_id', '=', 't.id')
            ->leftJoin('auth_user as conductor', 't.user_id', '=', 'conductor.id')
            ->leftJoin('organisation_organisation as org', 'a.organisation_id', '=', 'org.id')
            ->leftJoin('organisation_group as grp', 'a.group_id', '=', 'grp.id')
            ->leftJoin('common_grade as grade', 'a.grade_id', '=', 'grade.id')
            ->leftJoin('common_grade as pgrade', 'p.grade_id', '=', 'pgrade.id')
            ->select(
                'a.*',
                'u.first_name as student_first_name',
                'u.last_name as student_last_name',
                's.gender as student_gender',
                'p.passage_name as passage_title',
                'p.grade_id as passage_grade_id',
                'p.language_id as passage_language_id',
                'p.number as passage_no',
                'pgrade.level as passage_grade_level',
                'grp.title as group_title',
                'grp.level_id as group_level_id',
                'conductor.first_name as conductor_first_name',
                'conductor.last_name as conductor_last_name',
                'org.title as organisation_name',
                'grade.level as grade_level',
                'a.assessment_period'
            )
            ->where('a.id', $id)
            ->first();

        if (!$row) {
            return response()->json(['message' => 'Assessment not found'], 404);
        }

        $linked = DB::table('assessment_assessment_checklists')
            ->where('assessment_id', $id)
            ->pluck('checklist_id')
            ->toArray();

        $checklistsDetails = [];
        if ($row->passage_language_id) {
            $allChecklists = DB::table('assessment_checklist as c')
                ->leftJoin('common_language as l', 'c.language_id', '=', 'l.id')
                ->where('c.language_id', $row->passage_language_id)
                ->select('c.id', 'c.title', 'c.type', 'c.created', 'c.updated', 'l.name as language_name')
                ->get();

            $checklistsDetails = $allChecklists->map(fn($c) => [
                'id' => $c->id,
                'selected' => in_array($c->id, $linked),
                'language' => $c->language_name,
                'created' => $this->formatDateTime($c->created),
                'updated' => $this->formatDateTime($c->updated),
                'title' => $c->title,
                'type' => $c->type,
            ])->values()->all();
        }

        $errorWordRows = DB::table('assessment_errorwords as ew')
            ->leftJoin('passage_word as pw', 'ew.word_id', '=', 'pw.id')
            ->where('ew.assessment_id', $id)
            ->select('ew.word_id', 'pw.text as word_text')
            ->get();

        $errorWordIds = $errorWordRows->pluck('word_id')->values()->all();
        $wordDetails = $errorWordRows->map(fn($ew) => [
            'id' => $ew->word_id,
            'text' => $ew->word_text,
        ])->values()->all();

        $sFname = $this->tryDecrypt($row->student_first_name);
        $sLname = $this->tryDecrypt($row->student_last_name);

        // Benchmark Goal lookup (Benchmark Template)
        $benchmarkGoal = (int) ($row->benchmark_goal ?: $this->resolveBenchmarkGoal(
            $row->organisation_id,
            $row->passage_language_id,
            $row->grade_id,
            $row->group_level_id,
            $row->assessment_period
        ));

        // History lookup
        $history = DB::table('assessment_assessmentstudentgrouphistory as h')
            ->leftJoin('organisation_group as fg', 'h.from_group_id', '=', 'fg.id')
            ->leftJoin('organisation_group as tg', 'h.to_group_id', '=', 'tg.id')
            ->where('h.assessment_id', $id)
            ->select('fg.title as from_group_title', 'tg.title as to_group_title')
            ->first();

        // Decrypt names
        $firstName = $this->tryDecrypt($row->student_first_name);
        $lastName  = $this->tryDecrypt($row->student_last_name);

        return response()->json([
            'id' => $row->id,
            'word_details' => $wordDetails,
            'checklists_details' => $checklistsDetails,
            'passage_title' => $row->passage_grade_level . '-' . $row->passage_no . ' ' . $row->passage_title,
            'passage_grade' => $row->passage_grade_level,
            'total_words_count' => ($row->last_word_index + 1),
            'student_name' => trim($firstName . ' ' . $lastName) ?: null,
            'student_first_name' => $firstName,
            'student_last_name' => $lastName,
            'correct_words_count' => ($row->last_word_index + 1) - $row->no_error_words,
            'accuracy' => ($row->last_word_index + 1) > 0
                ? (($row->last_word_index + 1) - $row->no_error_words) / ($row->last_word_index + 1) * 100
                : 0,
            'grade_level' => $row->grade_level,
            'group_name' => $row->group_title,
            'conductor_first_name' => $row->conductor_first_name,
            'conductor_last_name' => $row->conductor_last_name,
            'passage_no' => $row->passage_grade_level . '-' . $row->passage_no,
            'student_gender' => $this->genderLabel($row->student_gender),
            'organisation_name' => $row->organisation_name,
            'benchmark_goal' => $benchmarkGoal,
            'from_group' => $history ? $history->from_group_title : $row->group_title,
            'to_group' => $history ? $history->to_group_title : null,
            'created' => $this->formatDateTime($row->created),
            'updated' => $this->formatDateTime($row->updated ?? $row->created),
            'type' => $row->type,
            'last_word_index' => $row->last_word_index,
            'last_word' => $row->last_word,
            'notes' => $row->notes,
            'no_error_words' => $row->no_error_words,
            'passage' => $row->passage_id,
            'student' => $row->student_id,
            'conducted_by' => $row->conducted_by_id,
            'organisation' => $row->organisation_id,
            'group' => $row->group_id,
            'grade' => $row->grade_id,
            'error_words' => $errorWordIds,
            'checklists' => $linked,
            'has_recording' => $row->audio || $row->retell_audio,
            'audio' => $row->audio,
            'retell_audio' => $row->retell_audio,
            'assessment_period' => $row->assessment_period,
            'language_id' => $row->passage_language_id,
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }


    public function postAssessments(Request $request)
    {
        $user = auth()->user();
        if ($user && $user->is_superuser) {
            return response()->json(['message' => 'Admins cannot create assessments'], 403);
        }

        $teacher = Teacher::where('user_id', $user->id)->first();
        if (!$teacher) {
            return response()->json(['message' => 'Authenticated user is not a teacher'], 403);
        }

        $student = Student::find($request->student);
        if (!$student) {
            return response()->json(['message' => 'Student not found'], 404);
        }

        $passage = Passage::find($request->passage);
        if (!$passage) {
            return response()->json(['message' => 'Passage not found'], 404);
        }

        // Grade 6 check (from Python)
        if ($student->grade && $student->grade->level == 6) {
            return response()->json(['error' => 'Assessments for Student in Grade 6 cannot be conducted'], 422);
        }

        if ($teacher->organisation_id !== $student->organisation_id) {
            return response()->json(['message' => 'You can only create assessments for students in your organisation'], 403);
        }

        $no_error_words = is_array($request->words) ? count($request->words) : 0;
        $assessmentId = (string) Str::uuid();
        $languageName = $passage->language ? $passage->language->name : ($request->language__name ?? 'English');

        // Safely resolve the correct language ID. Passages are sometimes seeded with wrong language IDs.
        $language = Language::whereRaw('LOWER(name) = ?', [strtolower($request->language__name ?? $languageName)])->first();
        $assessment_language_id = $language ? $language->id : $passage->language_id;

        // identify the 'from' group
        $fromGroup = null;
        if (strtoupper($languageName) === 'ENGLISH') {
            $fromGroup = $student->group;
        } elseif (strtoupper($languageName) === 'HINDI') {
            $fromGroup = $student->group_hindi;
        } elseif (strtoupper($languageName) === 'MARATHI') {
            $fromGroup = $student->group_marathi;
        }

        $levelId = $fromGroup ? $fromGroup->level_id : null;

        $assessmentPeriod = $this->resolveBenchmarkPeriod(
            $student->organisation_id,
            $assessment_language_id
        );

        $benchmarkGoal = $this->resolveBenchmarkGoal(
            $student->organisation_id,
            $assessment_language_id,
            $student->grade_id,
            $levelId,
            $assessmentPeriod
        );


        $assessment = Assessment::create([
            'id' => $assessmentId,
            'student_id' => $student->id,
            'passage_id' => $request->passage,
            'conducted_by_id' => $teacher->id,
            'organisation_id' => $student->organisation_id,
            'group_id' => $fromGroup ? $fromGroup->id : null,
            'grade_id' => $student->grade_id,
            'type' => $request->type ?? 0,
            'last_word_index' => $request->last_word_index,
            'last_word' => $request->last_word,
            'no_error_words' => $no_error_words,
            'notes' => $request->notes,
            'audio' => $request->audio,
            'retell_audio' => $request->retell_audio,
            'assessment_period' => $assessmentPeriod ?? null,
            'benchmark_goal' => $benchmarkGoal,
            'created' => now(),
            'updated' => now(),
        ]);

        if ($request->has('checklists')) {
            $assessment->checklists()->sync($request->checklists);
        }

        if ($request->has('words') && is_array($request->words)) {
            foreach ($request->words as $wordItem) {
                // Normalization like Python: lowercase and trim non-word characters
                $wordText = strtolower($wordItem['word']);
                $wordText = preg_replace('/^\W+|\W+$/u', '', $wordText);

                $word = PassageWord::where('text', $wordText)->first();
                if ($word) {
                    $assessment->errorWords()->attach($word->id, ['index' => $wordItem['index']]);
                }
            }
        }

        // --- Group Reassignment Logic (from Python create & signal) ---
        $correct_words = ($assessment->last_word_index + 1) - $no_error_words;

        $param = $this->resolveBenchmarkGroupingParameter(
            $student->organisation_id,
            $assessment_language_id,
            $student->grade_id,
            $correct_words,
            $assessment->assessment_period
        );

        if ($param) {
            $newGroup = OrganisationGroup::where('grade_id', $student->grade_id)
                ->where('level_id', $param->level_id)
                ->where('organisation_id', $student->organisation_id)
                ->first();

            if ($newGroup) {
                // Update Assessment's group
                $assessment->group_id = $newGroup->id;
                $assessment->save();

                // Signal-like logic for Benchmark assessments (type 1)
                if ($assessment->type == 1) {
                    $finalNewGroup = $newGroup;

                    // Accuracy-based fallback to Level 4 (Rank 4) using template-aware accuracy
                    $accuracy = $assessment->accuracy;
                    $level4 = Level::where('rank', 4)->first();
                    $gradeAccuracy = $level4
                        ? $this->resolveBenchmarkAccuracy(
                            $student->organisation_id,
                            $assessment_language_id,
                            $student->grade_id,
                            $level4->id,
                            $assessment->assessment_period
                        )
                        : null;

                    if ($gradeAccuracy && $accuracy < $gradeAccuracy->percentage && $level4) {
                        $fallbackGroup = OrganisationGroup::where('organisation_id', $student->organisation_id)
                            ->where('grade_id', $student->grade_id)
                            ->where('level_id', $level4->id)
                            ->first();
                        if ($fallbackGroup) {
                            $finalNewGroup = $fallbackGroup;
                        }
                    }

                    // Update student group based on language
                    if (strtoupper($language->name) === 'ENGLISH') {
                        $student->group_id = $finalNewGroup->id;
                    } elseif (strtoupper($language->name) === 'HINDI') {
                        $student->group_hindi_id = $finalNewGroup->id;
                    } elseif (strtoupper($language->name) === 'MARATHI') {
                        $student->group_marathi_id = $finalNewGroup->id;
                    }
                    $student->save();

                    // Record history in assessment_assessmentstudentgrouphistory
                    DB::table('assessment_assessmentstudentgrouphistory')->insert([
                        'id' => (string) Str::uuid(),
                        'assessment_id' => $assessment->id,
                        'from_group_id' => $fromGroup ? $fromGroup->id : null,
                        'to_group_id' => $finalNewGroup->id,
                        'created' => now(),
                        'updated' => now(),
                    ]);
                }
            }
        }

        return $this->getAssessmentDetails($request, $assessment->id)->setStatusCode(201);
    }



    public function getChecklists(Request $request)
    {
        $limit = $request->input('limit', 50);
        $offset = $request->input('offset', 0);

        $query = DB::table('assessment_checklist as c')
            ->leftJoin('common_language as l', 'c.language_id', '=', 'l.id');

        // Filter by language name if provided (support only language__name)
        if ($request->filled('language__name')) {
            $query->where('l.name', $request->input('language__name'));
        }

        // Filter by type if provided, otherwise default to Fluency (0) and Retell (1)
        if ($request->filled('type')) {
            $query->where('c.type', $request->input('type'));
        } else {
            $query->whereIn('c.type', [0, 1]);
        }

        $query->select('c.*', 'l.name as language_name');

        $count = $query->count();
        $results = $query->offset($offset)->limit($limit)->get();

        $formatted = $results->map(function ($row) {
            return [
                'id' => $row->id,
                'selected' => false,
                'language' => $row->language_name,
                'created' => $this->formatDateTime($row->created),
                'updated' => $this->formatDateTime($row->updated),
                'title' => $row->title,
                'type' => (int) $row->type,
            ];
        });

        $next = null;
        if ($offset + $limit < $count) {
            $next = $request->fullUrlWithQuery(['offset' => $offset + $limit, 'limit' => $limit]);
        }
        $previous = null;
        if ($offset > 0) {
            $prevOffset = max(0, $offset - $limit);
            $previous = $request->fullUrlWithQuery(['offset' => $prevOffset, 'limit' => $limit]);
        }

        return response()->json([
            'count' => $count,
            'next' => $next,
            'previous' => $previous,
            'results' => $formatted->values()->all(),
        ]);
    }

    public function getChecklistDetails(Request $request, $id)
    {
        $row = DB::table('assessment_checklist as c')
            ->leftJoin('common_language as l', 'c.language_id', '=', 'l.id')
            ->select('c.*', 'l.name as language_name')
            ->where('c.id', $id)
            ->first();

        if (!$row) {
            return response()->json(['message' => 'Checklist not found'], 404);
        }

        return response()->json([
            'id' => $row->id,
            'selected' => false,
            'language' => $row->language_name,
            'created' => $this->formatDateTime($row->created),
            'updated' => $this->formatDateTime($row->updated),
            'title' => $row->title,
            'type' => (int) $row->type,
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Generate CSV Excel report.
     *
     * Get assessments based on the following filters:
     * - language
     * - organisation
     * - assessment_type
     * - from_date
     * - to_date
     */
    public function generateExcel(Request $request)
    {
        // return "Debug: hitting generateExcel";
        $language = $request->query('language');
        $organisation_id = $request->query('organisation');
        $assessment_type = $request->query('type');
        $from_date = $request->query('from');
        $to_date = $request->query('to');

        $query = DB::table('assessment_assessment as a')
            ->leftJoin('access_student as s', 'a.student_id', '=', 's.id')
            ->leftJoin('auth_user as u', 's.user_id', '=', 'u.id')
            ->leftJoin('common_grade as g', 'a.grade_id', '=', 'g.id')
            ->leftJoin('passage_passage as p', 'a.passage_id', '=', 'p.id')
            ->leftJoin('common_language as l', 'p.language_id', '=', 'l.id')
            ->leftJoin('organisation_organisation as o', 'a.organisation_id', '=', 'o.id')
            ->leftJoin('organisation_group as grp', 'a.group_id', '=', 'grp.id')
            ->leftJoin('common_level as lvl', 'grp.level_id', '=', 'lvl.id')
            ->leftJoin('access_teacher as t', 'a.conducted_by_id', '=', 't.id')
            ->leftJoin('auth_user as ct', 't.user_id', '=', 'ct.id')
            ->select(
                'o.title as organisation_name',
                'u.first_name as student_first_name',
                'u.last_name as student_last_name',
                'g.title as grade_title',
                'g.id as grade_id',
                'grp.title as group_title',
                'grp.id as group_id',
                'lvl.id as level_id',
                'ct.first_name as conductor_first_name',
                'ct.last_name as conductor_last_name',
                's.gender as student_gender',
                'a.created',
                'a.type as assessment_type_code',
                'p.number as passage_number',
                'p.passage_name',
                'g.level as grade_level',
                'a.last_word_index',
                'a.no_error_words',
                'l.name as language_name',
                'a.organisation_id',
                'a.assessment_period',
                'p.language_id as language_id',
                'a.id as assessment_id',
                'a.student_id',
                'a.passage_id',
                'grp.id as group_id',
                'lvl.id as level_id'
            )
            ->orderBy('a.created', 'desc');

        // Filters - Use $request->filled for more robust check
        if ($request->filled('language')) {
            $query->where('l.name', $request->input('language'));
        }
        if ($request->filled('organisation')) {
            $query->where('a.organisation_id', $request->input('organisation'));
        }
        if ($request->has('type') && $request->input('type') !== null && $request->input('type') !== '') {
            $query->where('a.type', $request->input('type'));
        }
        if ($request->filled('from') && $request->filled('to')) {
            $query->whereDate('a.created', '>=', $request->input('from'))
                ->whereDate('a.created', '<=', $request->input('to'));
        } elseif (($request->filled('from') && !$request->filled('to')) || (!$request->filled('from') && $request->filled('to'))) {
            return response()->json(['error' => 'error in the sent query parameters'], 400);
        }

        $columns = [
            'organisation',
            'student',
            'grade',
            'from_group',
            'to_group',
            'conducted_by',
            'gender',
            'year',
            'date',
            'assessment_type',
            'Passage_no',
            'passage',
            'correct_words',
            'no_error_words',
            'Accuracy',
            'Benchmark Goal',
            'Checklist'
        ];

        return new StreamedResponse(function () use ($query, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);

            $query->chunk(500, function ($results) use ($file) {
                $languageIds = $results->pluck('language_id')->filter()->unique();
                $assessmentIds = $results->pluck('assessment_id');

                $allChecklists = DB::table('assessment_checklist')
                    ->whereIn('language_id', $languageIds)
                    ->get()
                    ->groupBy('language_id');

                $selectedChecklists = DB::table('assessment_assessment_checklists')
                    ->whereIn('assessment_id', $assessmentIds)
                    ->get()
                    ->groupBy('assessment_id');

                foreach ($results as $row) {
                    $total_words = ($row->last_word_index + 1);
                    $correct_words = ($total_words - $row->no_error_words);
                    $accuracy = $total_words > 0 ? ($correct_words * 100) / $total_words : 0;

                    $student_name = trim($this->tryDecrypt($row->student_first_name) . ' ' . $this->tryDecrypt($row->student_last_name));
                    $conductor_name = trim($row->conductor_first_name . ' ' . $row->conductor_last_name);
                    $created = \Carbon\Carbon::parse($row->created);

                    $goal = $this->resolveBenchmarkGoal(
                        $row->organisation_id,
                        $row->language_id,
                        $row->grade_id,
                        $row->level_id,
                        $row->assessment_period
                    );

                    // Checklist Status
                    $sentence = "";
                    $checklistsForLang = $allChecklists->get($row->language_id, collect());
                    $selectedForAssessment = $selectedChecklists->get($row->assessment_id, collect())->pluck('checklist_id')->toArray();

                    foreach ($checklistsForLang as $check) {
                        $status = in_array($check->id, $selectedForAssessment) ? "Done" : "Not Done";
                        $sentence .= $check->title . " - " . $status . " \n";
                    }

                    fputcsv($file, [
                        $row->organisation_name,
                        $student_name,
                        $row->grade_title,
                        $row->assessment_type_code == 1 ? 'G' . $row->grade_level . ' Benchmarking' : $row->group_title,
                        $row->assessment_type_code == 1 ? $row->group_title : '-',
                        $conductor_name,
                        $this->genderLabel($row->student_gender),
                        $created->year,
                        $created->format('Y-m-d'),
                        $row->assessment_type_code == 0 ? 'Progressive' : 'Benchmark',
                        $row->grade_level . '-' . $row->passage_number,
                        $row->passage_name,
                        $correct_words,
                        $row->no_error_words,
                        round($accuracy, 2),
                        $goal,
                        trim($sentence)
                    ]);
                }
            });

            fclose($file);
        }, 200, [
            "Content-Type" => "text/csv",
            "Content-Disposition" => "attachment; filename=\"Overall Analytics.csv\"",
        ]);
    }
}
