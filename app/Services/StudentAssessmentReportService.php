<?php

namespace App\Services;

use App\Constants\TranslationConstants;
use App\Models\Assessment;
use App\Models\BenchmarkTemplate;
use App\Models\Checklist;
use App\Models\GroupingParameter;
use App\Models\Language;
use App\Models\Organisation;
use App\Models\OrganisationGroup;
use App\Models\OrganisationLanguage;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StudentAssessmentReportService
{
    /**
     * Decrypt string safely if encrypted.
     */
    public function tryDecrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (!str_contains($value, 'eyJpdiI6')) {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            try {
                return Crypt::decrypt($value);
            } catch (\Exception $e2) {
                return $value;
            }
        }
    }

    /**
     * Sanitize and validate UUID.
     */
    public function sanitizeUuid(?string $val): ?string
    {
        if (!$val) {
            return null;
        }
        $val = substr($val, 0, 64);
        $val = trim(str_replace(['"', "'", ' ', '%22', '\n', '\r', '\t'], '', $val));
        if (!\Illuminate\Support\Str::isUuid($val)) {
            return null;
        }
        return $val;
    }

    /**
     * Build the entire student assessment report data.
     *
     * @param string $studentId
     * @param Request|array $filters
     * @return array
     */
    public function buildReport(string $studentId, $filters = []): array
    {
        $sanitizedStudentId = $this->sanitizeUuid($studentId) ?: $studentId;

        $student = Student::with([
            'user',
            'grade',
            'languages',
            'organisation',
            'group',
            'group_hindi',
            'group_marathi',
        ])->find($sanitizedStudentId);

        if (!$student) {
            throw new \InvalidArgumentException("Student not found for ID: {$studentId}");
        }

        // 1. Resolve Language
        $langParam = $filters instanceof Request
            ? $filters->get('lang', $filters->get('language', $filters->get('languages__name', 'English')))
            : ($filters['language'] ?? $filters['languages__name'] ?? $filters['lang'] ?? 'English');

        $language = Language::where('name', 'ilike', $langParam)->first();
        if (!$language) {
            $language = Language::where('name', 'ilike', 'English')->first();
        }
        $languageId = $language ? $language->id : null;
        $languageName = $language ? $language->name : 'English';

        // 2. Resolve Student Names
        $firstName = $this->tryDecrypt($student->user->first_name ?? '');
        $lastName = $this->tryDecrypt($student->user->last_name ?? '');
        $studentFullName = trim($firstName . ' ' . $lastName);
        if ($studentFullName === '') {
            $studentFullName = $student->user->username ?? 'Student';
        }

        // 3. Resolve Group according to Language
        $groupColumn = 'group_id';
        if (strcasecmp($languageName, 'Hindi') === 0) {
            $groupColumn = 'group_hindi_id';
        } elseif (strcasecmp($languageName, 'Marathi') === 0) {
            $groupColumn = 'group_marathi_id';
        }
        $groupId = $student->$groupColumn ?: $student->group_id;

        $groupTitle = null;
        if ($groupId) {
            $groupObj = OrganisationGroup::find($groupId);
            $groupTitle = $groupObj ? $groupObj->title : null;
        }
        if (!$groupTitle && $student->group) {
            $groupTitle = $student->group->title;
        }
        $groupTitle = $groupTitle ?: 'General Group';

        // 4. Fetch all assessments for this student and language
        $assessmentsQuery = DB::table('assessment_assessment as a')
            ->join('passage_passage as p', 'a.passage_id', '=', 'p.id')
            ->leftJoin('common_grade as pgrade', 'p.grade_id', '=', 'pgrade.id')
            ->leftJoin('common_grade as agrade', 'a.grade_id', '=', 'agrade.id')
            ->leftJoin('organisation_group as grp', 'a.group_id', '=', 'grp.id')
            ->where('a.student_id', $student->id);

        if ($languageId) {
            $assessmentsQuery->where('p.language_id', $languageId);
        }

        $allRawAssessments = $assessmentsQuery->select(
            'a.*',
            'p.passage_name',
            'p.number as passage_number',
            'p.grade_id as passage_grade_id',
            'p.language_id as passage_language_id',
            'pgrade.level as passage_grade_level',
            'pgrade.title as passage_grade_title',
            'agrade.level as assessment_grade_level',
            'agrade.title as assessment_grade_title',
            'grp.title as assessment_group_title',
            'grp.level_id as assessment_group_level_id'
        )->orderBy('a.created', 'asc')->get();

        // 5. Academic Year Calculation Logic
        $academicYearInfo = $this->calculateAcademicYearInfo($allRawAssessments);
        $academicYear = $academicYearInfo['academic_year'];
        $academicYearAssessments = $academicYearInfo['assessments'];

        // 6. Filter by Assessment Period
        $periodParam = $filters instanceof Request
            ? $filters->get('assessment_period', $filters->get('period', null))
            : ($filters['assessment_period'] ?? $filters['period'] ?? null);

        $normalizedPeriod = $this->normalizePeriod($periodParam);

        // Determine effective period filter (defaults to Full Year)
        if ($normalizedPeriod === null || $normalizedPeriod === 'ALL') {
            $effectivePeriod = 'ALL';
            $periodFilterLabel = 'Full Year';
        } else {
            $effectivePeriod = $normalizedPeriod;
            $periodFilterLabel = $normalizedPeriod;
        }

        $filteredAssessments = $academicYearAssessments;
        if ($effectivePeriod !== 'ALL') {
            $filteredAssessments = $academicYearAssessments->filter(function ($item) use ($effectivePeriod) {
                return $this->normalizePeriod($item->assessment_period) === $effectivePeriod;
            })->values();
        }

        // 7. Resolve Filter Flags
        // A. Retell and Fluency Quality (Checklist)
        $includeRetellFluency = $this->parseBooleanFilter(
            $filters,
            ['retell_and_fluency', 'retell_fluency', 'retell'],
            true
        );

        // B. Trend Analysis
        $includeTrendAnalysis = $this->parseBooleanFilter(
            $filters,
            ['trend_analysis', 'trend'],
            true
        );

        // C. Audio Link
        $includeAudioLink = $this->parseBooleanFilter(
            $filters,
            ['audio_link', 'audio'],
            true
        );

        // D. Graph Type: Progress Monitoring & Benchmarking
        $graphFilters = $this->resolveGraphTypeFilters($filters);
        $includeProgressMonitoring = $graphFilters['progress_monitoring'];
        $includeBenchmarking = $graphFilters['benchmarking'];

        // E. Filter assessments by assessment type (Progress Monitoring / Benchmarking)
        $displayAssessments = $filteredAssessments;
        if ($includeProgressMonitoring && !$includeBenchmarking) {
            $displayAssessments = $filteredAssessments->filter(fn($a) => (int)$a->type === 0)->values();
        } elseif (!$includeProgressMonitoring && $includeBenchmarking) {
            $displayAssessments = $filteredAssessments->filter(fn($a) => (int)$a->type === 1)->values();
        } elseif (!$includeProgressMonitoring && !$includeBenchmarking) {
            $displayAssessments = collect([]);
        }

        // 8. Assessment Data Summary (Accuracy, Errors/Min, Correct Words/Min, Benchmarking Grade)
        $assessmentDataSummary = $this->calculateAssessmentDataSummary(
            $displayAssessments,
            $student,
            $academicYearAssessments
        );

        // 9. Retell and Fluency Quality (Checklist)
        $retellFluencyQuality = null;
        if ($includeRetellFluency) {
            $retellFluencyQuality = $this->resolveRetellAndFluencyQuality(
                $student->id,
                $languageId,
                $academicYearAssessments
            );
        }

        // 10. Trend Analysis (Progress Monitoring + Benchmarking)
        $trendAnalysis = null;
        if ($includeTrendAnalysis && ($includeProgressMonitoring || $includeBenchmarking)) {
            $trendAnalysis = $this->buildTrendAnalysis(
                $filteredAssessments,
                $student,
                $languageId,
                $academicYear,
                $includeProgressMonitoring,
                $includeBenchmarking,
                $includeAudioLink
            );
        }

        // 11. Grade Display (e.g. G3 or Grade 3)
        $gradeShort = 'G' . ($student->grade->level ?? '3');

        $currentDate = Carbon::now('Asia/Kolkata');

        return [
            'student' => [
                'id' => $student->id,
                'name' => $studentFullName,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'grade' => $gradeShort,
                'group' => $groupTitle,
                'group_id' => $groupId,
                'academic_year' => $academicYear,
                'language' => $languageName,
                'language_id' => $languageId,
                'organisation_id' => $student->organisation_id,
                'organisation_name' => $student->organisation->title ?? '',
                'division' => $student->division,
            ],
            'report_metadata' => [
                'title' => 'Student Assessment Report',
                'report_date' => $currentDate->format('d M Y'),
                'academic_year' => $academicYear,
                'period_filter' => $periodFilterLabel,
                'effective_period' => $effectivePeriod,
                'generated_at' => $currentDate->format('M d, Y g:i A'),
                'filters' => [
                    'retell_and_fluency' => $includeRetellFluency,
                    'trend_analysis' => $includeTrendAnalysis,
                    'audio_link' => $includeAudioLink,
                    'assessment_period' => $periodFilterLabel,
                    'progress_monitoring' => $includeProgressMonitoring,
                    'benchmarking' => $includeBenchmarking,
                ],
            ],
            'assessment_data' => $assessmentDataSummary,
            'retell_and_fluency_quality' => $retellFluencyQuality,
            'trend_analysis' => $trendAnalysis,
        ];
    }

    /**
     * Calculate Academic Year according to exact business logic:
     * - Academic year runs from first BOY through last EOY.
     * - If new BOY starts in different calendar year, academic year changes and only new BOY data displays.
     * - Academic year format is static "YYYY-YYYY" (e.g. 2026-2027) regardless of assessment period.
     */
    public function calculateAcademicYearInfo(Collection $assessments): array
    {
        $currentYear = Carbon::now('Asia/Kolkata')->year;

        // Find all BOY assessments, sorted chronologically
        $boyAssessments = $assessments->filter(function ($a) {
            $p = $this->normalizePeriod($a->assessment_period);
            return $p === 'BOY';
        })->sortBy('created')->values();

        // If no BOY assessments exist, fall back to earliest assessment or current calendar year
        if ($boyAssessments->isEmpty()) {
            $earliest = $assessments->sortBy('created')->first();
            $startYear = $earliest ? Carbon::parse($earliest->created)->year : $currentYear;
            $academicYear = $startYear . '-' . ($startYear + 1);

            return [
                'academic_year' => $academicYear,
                'start_year' => $startYear,
                'assessments' => $assessments,
            ];
        }

        // Group BOY assessments by calendar year
        $boyByYear = $boyAssessments->groupBy(function ($a) {
            return Carbon::parse($a->created)->year;
        })->sortKeys();

        // Latest BOY calendar year represents the current active academic year
        $latestBoyYear = $boyByYear->keys()->last();
        $academicYear = $latestBoyYear . '-' . ($latestBoyYear + 1);

        // Find the timestamp of the first BOY in this latest BOY calendar year
        $firstBoyOfCurrentCycle = $boyByYear->get($latestBoyYear)->first();
        $cycleStartDate = Carbon::parse($firstBoyOfCurrentCycle->created);

        // Academic year data: all assessments created on or after the first BOY of this year
        // and before any subsequent BOY year (if future years exist)
        $cycleAssessments = $assessments->filter(function ($a) use ($cycleStartDate, $latestBoyYear) {
            $created = Carbon::parse($a->created);
            if ($created->lt($cycleStartDate)) {
                return false;
            }
            // If another BOY exists in a strictly later calendar year, stop before that
            $period = $this->normalizePeriod($a->assessment_period);
            if ($created->year > $latestBoyYear && $period === 'BOY') {
                return false;
            }
            return true;
        })->values();

        return [
            'academic_year' => $academicYear,
            'start_year' => $latestBoyYear,
            'assessments' => $cycleAssessments,
        ];
    }

    /**
     * Calculate Assessment Data Summary (Accuracy, Errors/Min, Correct Words/Min, Benchmarking Grade).
     */
    protected function calculateAssessmentDataSummary(
        Collection $assessments,
        Student $student,
        Collection $fullCycleAssessments
    ): array {
        $benchmarkingGrade = $student->grade->title ?? ('Grade ' . ($student->grade->level ?? '3'));

        // Prefer latest benchmarking assessment (type 1)
        $latestBenchmarking = $assessments->filter(fn($a) => (int)$a->type === 1)
            ->sortByDesc('created')
            ->first();

        // Fallback to latest benchmarking in full cycle, or latest overall
        if (!$latestBenchmarking) {
            $latestBenchmarking = $fullCycleAssessments->filter(fn($a) => (int)$a->type === 1)
                ->sortByDesc('created')
                ->first();
        }

        if (!$latestBenchmarking) {
            $latestBenchmarking = $assessments->sortByDesc('created')->first();
        }

        if ($latestBenchmarking) {
            $totalWords = (int)($latestBenchmarking->last_word_index + 1);
            $errors = (int)$latestBenchmarking->no_error_words;
            $correctWords = max(0, $totalWords - $errors);
            $accuracy = $totalWords > 0 ? round(($correctWords / $totalWords) * 100) : 0;

            return [
                'benchmarking_grade' => $benchmarkingGrade,
                'accuracy' => (int)$accuracy,
                'accuracy_formatted' => $accuracy . '%',
                'errors_per_minute' => $errors,
                'error_mean' => $errors,
                'correct_words_per_minute' => $correctWords,
                'total_assessments_count' => $assessments->count(),
            ];
        }

        // If no assessments at all, return zeros
        return [
            'benchmarking_grade' => $benchmarkingGrade,
            'accuracy' => 0,
            'accuracy_formatted' => '0%',
            'errors_per_minute' => 0,
            'error_mean' => 0,
            'correct_words_per_minute' => 0,
            'total_assessments_count' => 0,
        ];
    }

    /**
     * Resolve Retell & Fluency Quality Checklist criteria and status (Yes/No).
     */
    public function resolveRetellAndFluencyQuality(
        string $studentId,
        ?string $languageId,
        Collection $assessments
    ): array {
        // Query checklists for this language
        $checklistsQuery = Checklist::query();
        if ($languageId) {
            $checklistsQuery->where('language_id', $languageId);
        }
        $checklists = $checklistsQuery->orderBy('type', 'asc')->orderBy('title', 'asc')->get();

        // Get the last assessment of the student
        $lastAssessment = $assessments->sortByDesc('created')->first();
        $lastAssessmentId = $lastAssessment ? $lastAssessment->id : null;

        // Fallback to DB latest assessment if none found in cycle assessments
        if (!$lastAssessmentId) {
            $lastAssessmentId = DB::table('assessment_assessment')
                ->where('student_id', $studentId)
                ->orderBy('created', 'desc')
                ->value('id');
        }

        // Get all selected checklist IDs for this last assessment
        $selectedChecklistIds = [];
        $validAssessmentUuid = $this->sanitizeUuid($lastAssessmentId);
        if ($validAssessmentUuid) {
            $selectedChecklistIds = DB::table('assessment_assessment_checklists')
                ->where('assessment_id', $validAssessmentUuid)
                ->pluck('checklist_id')
                ->toArray();
        }

        $criteria = $checklists->map(function ($c) use ($selectedChecklistIds) {
            $isMet = in_array($c->id, $selectedChecklistIds, true);
            return [
                'id' => $c->id,
                'title' => $c->title,
                'type' => (int)$c->type, // 0 = Fluency, 1 = Retell
                'type_label' => (int)$c->type === 0 ? 'Fluency' : 'Retell',
                'status' => $isMet ? 'Yes' : 'No',
            ];
        })->values()->all();

        return [
            'subtitle' => 'Checklist Based on last assessment',
            'assessment_id' => $lastAssessmentId,
            'assessments_evaluated_count' => $lastAssessmentId ? 1 : 0,
            'criteria' => $criteria,
        ];
    }

    /**
     * Parse boolean filter value from Request or array with a default.
     *
     * @param Request|array $filters
     * @param array $keys
     * @param bool $default
     * @return bool
     */
    public function parseBooleanFilter($filters, array $keys, bool $default = true): bool
    {
        foreach ($keys as $key) {
            $val = null;
            if ($filters instanceof Request) {
                if ($filters->has($key)) {
                    $val = $filters->input($key);
                }
            } elseif (is_array($filters) && array_key_exists($key, $filters)) {
                $val = $filters[$key];
            }

            if ($val !== null) {
                if (is_bool($val)) {
                    return $val;
                }
                $str = strtolower(trim((string)$val));
                if (in_array($str, ['1', 'true', 'on', 'yes'], true)) {
                    return true;
                }
                if (in_array($str, ['0', 'false', 'off', 'no'], true)) {
                    return false;
                }
                if ($str === '') {
                    return true;
                }
            }
        }

        return $default;
    }

    /**
     * Resolve Graph Type filter options (Progress Monitoring and Benchmarking).
     *
     * Supports:
     * - progress_monitoring=true/false
     * - benchmarking=true/false
     * - graph_type='both'|'progress_monitoring'|'benchmarking'|'none'
     * - graph_type=['progress_monitoring', 'benchmarking']
     * - graph_type='progress_monitoring,benchmarking'
     *
     * @param Request|array $filters
     * @return array{progress_monitoring: bool, benchmarking: bool}
     */
    public function resolveGraphTypeFilters($filters): array
    {
        $hasExplicitPm = false;
        $hasExplicitBm = false;
        $includePm = true;
        $includeBm = true;

        foreach (['progress_monitoring', 'include_progress_monitoring', 'pm'] as $k) {
            if (($filters instanceof Request && $filters->has($k)) || (is_array($filters) && array_key_exists($k, $filters))) {
                $includePm = $this->parseBooleanFilter($filters, [$k], true);
                $hasExplicitPm = true;
                break;
            }
        }

        foreach (['benchmarking', 'include_benchmarking', 'bm'] as $k) {
            if (($filters instanceof Request && $filters->has($k)) || (is_array($filters) && array_key_exists($k, $filters))) {
                $includeBm = $this->parseBooleanFilter($filters, [$k], true);
                $hasExplicitBm = true;
                break;
            }
        }

        if (!$hasExplicitPm && !$hasExplicitBm) {
            $graphTypeRaw = null;
            if ($filters instanceof Request) {
                $graphTypeRaw = $filters->input('graph_type', $filters->input('graph_types', null));
            } elseif (is_array($filters)) {
                $graphTypeRaw = $filters['graph_type'] ?? $filters['graph_types'] ?? null;
            }

            if ($graphTypeRaw !== null) {
                if (is_array($graphTypeRaw)) {
                    $normalized = array_map(fn($v) => strtolower(trim((string)$v)), $graphTypeRaw);
                    $includePm = in_array('progress_monitoring', $normalized, true) || in_array('pm', $normalized, true) || in_array('both', $normalized, true);
                    $includeBm = in_array('benchmarking', $normalized, true) || in_array('bm', $normalized, true) || in_array('both', $normalized, true);
                } else {
                    $gt = strtolower(trim((string)$graphTypeRaw));
                    if (str_contains($gt, ',')) {
                        $parts = array_map('trim', explode(',', $gt));
                        $includePm = in_array('progress_monitoring', $parts, true) || in_array('pm', $parts, true) || in_array('both', $parts, true);
                        $includeBm = in_array('benchmarking', $parts, true) || in_array('bm', $parts, true) || in_array('both', $parts, true);
                    } elseif ($gt === 'progress_monitoring' || $gt === 'pm') {
                        $includePm = true;
                        $includeBm = false;
                    } elseif ($gt === 'benchmarking' || $gt === 'bm') {
                        $includePm = false;
                        $includeBm = true;
                    } elseif ($gt === 'both' || $gt === 'all') {
                        $includePm = true;
                        $includeBm = true;
                    } elseif ($gt === 'none') {
                        $includePm = false;
                        $includeBm = false;
                    }
                }
            }
        }

        return [
            'progress_monitoring' => $includePm,
            'benchmarking' => $includeBm,
        ];
    }

    /**
     * Build Trend Analysis section (Progress Monitoring + Benchmarking).
     *
     * @param Collection $assessments
     * @param Student $student
     * @param string|null $languageId
     * @param string $academicYear
     * @param bool|string $includePmOrGraphType
     * @param bool $includeBm
     * @param bool $includeAudioLink
     * @return array
     */
    public function buildTrendAnalysis(
        Collection $assessments,
        Student $student,
        ?string $languageId,
        string $academicYear,
        $includePmOrGraphType = true,
        bool $includeBm = true,
        bool $includeAudioLink = true
    ): array {
        $progressMonitoring = null;
        $benchmarking = null;

        // Academic Year short format (e.g. 2026-2027 -> 2026-27)
        $ayParts = explode('-', (string)$academicYear);
        $ayShort = count($ayParts) === 2 ? $ayParts[0] . '-' . substr($ayParts[1], -2) : $academicYear;

        if (is_string($includePmOrGraphType)) {
            $gt = strtolower($includePmOrGraphType);
            $includePm = in_array($gt, ['both', 'all', 'progress_monitoring', 'pm'], true);
            $includeBm = in_array($gt, ['both', 'all', 'benchmarking', 'bm'], true);
        } else {
            $includePm = (bool)$includePmOrGraphType;
        }

        // Progress Monitoring (type = 0)
        if ($includePm) {
            $pmAssessments = $assessments->filter(fn($a) => (int)$a->type === 0)->values();
            $progressMonitoring = $this->buildProgressMonitoringSection(
                $pmAssessments,
                $student,
                $languageId,
                $includeAudioLink
            );
        }

        // Benchmarking (type = 1)
        if ($includeBm) {
            $bmAssessments = $assessments->filter(fn($a) => (int)$a->type === 1)->values();
            $benchmarking = $this->buildBenchmarkingSection(
                $bmAssessments,
                $student,
                $languageId,
                $includeAudioLink
            );
        }

        return [
            'academic_year' => $ayShort,
            'title' => "Trend Analysis - {$ayShort}",
            'progress_monitoring' => $progressMonitoring,
            'benchmarking' => $benchmarking,
        ];
    }

    /**
     * Build Progress Monitoring subsection grouped by Grade (e.g. Grade 1, Grade 3).
     */
    protected function buildProgressMonitoringSection(
        Collection $pmAssessments,
        Student $student,
        ?string $languageId,
        bool $includeAudioLink
    ): array {
        // Group assessments by Grade Level
        $groupedByGrade = $pmAssessments->groupBy(function ($a) {
            return $a->passage_grade_level ?: ($a->assessment_grade_level ?: 1);
        })->sortKeys();

        $gradesData = [];

        foreach ($groupedByGrade as $gradeLevel => $items) {
            $sortedItems = $items->sortBy('created')->values();
            $chartData = [];
            $tableRows = [];

            foreach ($sortedItems as $index => $item) {
                $srNo = $index + 1;
                $formattedItem = $this->formatAssessmentItem($item, $student, $languageId, $includeAudioLink, $srNo);

                $chartData[] = [
                    'date' => Carbon::parse($item->created)->format('d/m/y'),
                    'date_full' => Carbon::parse($item->created)->format('M d, Y'),
                    'score' => $formattedItem['score'],
                    'goal' => $formattedItem['goal'],
                ];

                $tableRows[] = $formattedItem;
            }

            $gradeTitle = 'Grade ' . $gradeLevel;
            $gradesData[] = [
                'grade' => $gradeTitle,
                'grade_level' => (int)$gradeLevel,
                'chart_data' => $chartData,
                'assessments' => $tableRows,
            ];
        }

        return [
            'title' => 'Progress Monitoring',
            'grades' => $gradesData,
        ];
    }

    /**
     * Build Benchmarking subsection.
     */
    protected function buildBenchmarkingSection(
        Collection $bmAssessments,
        Student $student,
        ?string $languageId,
        bool $includeAudioLink
    ): array {
        $sortedItems = $bmAssessments->sortBy('created')->values();
        $chartData = [];
        $tableRows = [];

        foreach ($sortedItems as $index => $item) {
            $srNo = $index + 1;
            $formattedItem = $this->formatAssessmentItem($item, $student, $languageId, $includeAudioLink, $srNo);

            $chartData[] = [
                'date' => Carbon::parse($item->created)->format('d/m/y'),
                'date_full' => Carbon::parse($item->created)->format('M d, Y'),
                'score' => $formattedItem['score'],
                'goal' => $formattedItem['goal'],
            ];

            $tableRows[] = $formattedItem;
        }

        return [
            'title' => 'Benchmarking',
            'chart_data' => $chartData,
            'assessments' => $tableRows,
        ];
    }

    /**
     * Format a single assessment item.
     */
    public function formatAssessmentItem(
        object $item,
        Student $student,
        ?string $languageId,
        bool $includeAudioLink = true,
        int $srNo = 1
    ): array {
        $created = Carbon::parse($item->created)->setTimezone('Asia/Kolkata');
        $totalWords = (int)($item->last_word_index + 1);
        $errors = (int)$item->no_error_words;
        $score = max(0, $totalWords - $errors);

        // Benchmark goal resolution
        $goal = 0;
        if (!empty($item->benchmark_goal) && (int)$item->benchmark_goal > 0) {
            $goal = (int)$item->benchmark_goal;
        } else {
            $goal = (int)$this->resolveDynamicGoal(
                $student->organisation_id,
                $item->passage_language_id ?: $languageId,
                $item->grade_id ?: $student->grade_id,
                $item->assessment_group_level_id,
                $item->assessment_period
            );
        }

        // Qualitative Feedback (notes)
        $feedback = trim($item->notes ?? '');
        if ($feedback === '') {
            $feedback = 'None';
        }

        // Period label
        $period = $this->normalizePeriod($item->assessment_period) ?: 'BOY';

        // Passage Title
        $passageTitle = $item->passage_name ?: 'Passage';

        // Audio resolution
        $audioDownloadUrl = null;
        $hasAudio = false;

        if ($includeAudioLink && !empty($item->audio)) {
            $hasAudio = true;
            $audioRaw = $item->audio;
            if (str_starts_with($audioRaw, 'http://') || str_starts_with($audioRaw, 'https://')) {
                $audioUrl = $audioRaw;
                $audioDownloadUrl = $audioRaw;
            } else {
                try {
                    $audioUrl = Storage::disk('s3')->url($audioRaw);
                    $audioDownloadUrl = $audioUrl;
                } catch (\Exception $e) {
                    $audioUrl = $audioRaw;
                    $audioDownloadUrl = $audioRaw;
                }
            }
        }

        return [
            'sr_no' => $srNo,
            'id' => $item->id,
            'date_time' => $created->format('M d, Y g:i A'),
            'date' => $created->format('d/m/y'),
            'period' => $period,
            'passage_name' => $passageTitle,
            'score' => $score,
            'goal' => $goal,
            'accuracy' => $totalWords > 0 ? round(($score / $totalWords) * 100) : 0,
            'errors' => $errors,
            'teacher_notes' => $item->notes,
            'audio_download_url' => $audioDownloadUrl,
            'has_audio' => $hasAudio,
            'type' => (int)$item->type,
            'type_label' => (int)$item->type === 0 ? 'Progress Monitoring' : 'Benchmarking',
        ];
    }

    /**
     * Normalize period string to canonical format (BOY, MOY, EOY, ALL).
     * Only BOY, MOY, and EOY are supported.
     */
    public function normalizePeriod(?string $period): ?string
    {
        if (!$period) {
            return null;
        }
        $p = strtoupper(trim($period));
        if (in_array($p, ['BOY', 'MOY', 'EOY', 'ALL'], true)) {
            return $p;
        }
        return null;
    }

    /**
     * Resolve benchmark goal from GroupingParameter or BenchmarkTemplate.
     */
    protected function resolveDynamicGoal(
        ?string $organisationId,
        ?string $languageId,
        ?string $gradeId,
        ?string $levelId,
        ?string $assessmentPeriod
    ): int {
        if (!$languageId || !$gradeId) {
            return 0;
        }

        $template = BenchmarkTemplate::findForOrganisationLanguage($organisationId, $languageId);
        $templateId = $template ? $template->id : null;
        $resolvedPeriod = $this->normalizePeriod($assessmentPeriod) ?: ($template?->activeAssessmentPeriodForLanguage($languageId));

        $query = GroupingParameter::where('language_id', $languageId)
            ->where('grade_id', $gradeId);

        if ($levelId) {
            $query->where('level_id', $levelId);
        }

        // 1. Template + Period
        if ($templateId && $resolvedPeriod) {
            $g = (clone $query)->where('benchmark_template_id', $templateId)
                ->where('assessment_period', $resolvedPeriod)
                ->first();
            if ($g && $g->goal !== null) return (int)$g->goal;
        }

        // 2. Default + Period
        if ($resolvedPeriod) {
            $g = (clone $query)->whereNull('benchmark_template_id')
                ->where('assessment_period', $resolvedPeriod)
                ->first();
            if ($g && $g->goal !== null) return (int)$g->goal;
        }

        // 3. Template + Null period
        if ($templateId) {
            $g = (clone $query)->where('benchmark_template_id', $templateId)
                ->whereNull('assessment_period')
                ->first();
            if ($g && $g->goal !== null) return (int)$g->goal;
        }

        // 4. Fallback Default
        $g = (clone $query)->whereNull('benchmark_template_id')
            ->whereNull('assessment_period')
            ->first();

        return (int)($g?->goal ?? 0);
    }
}

