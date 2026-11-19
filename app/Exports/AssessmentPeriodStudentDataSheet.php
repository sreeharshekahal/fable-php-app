<?php

namespace App\Exports;

use App\Exports\Traits\HasAssessmentExportHelpers;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Worksheet containing granular, per-student assessment records.
 *
 * Implements chunked database streaming (FromQuery) to handle large datasets efficiently.
 * Resolves student/conductor names (with decryption), grade/language/group contexts,
 * calculated accuracy, dynamic benchmark goals, and checklist completion states.
 */
class AssessmentPeriodStudentDataSheet implements FromQuery, WithTitle, WithHeadings, WithMapping, WithColumnWidths, WithStyles, WithCustomChunkSize, WithStrictNullComparison
{
    use HasAssessmentExportHelpers;

    /**
     * Query filter criteria (e.g., organisation, language, type, date range).
     */
    protected array $filters;

    protected ?string $period;

    /**
     * Cache of checklists grouped by language_id.
     *
     * @var array<int|string, \Illuminate\Support\Collection>
     */
    protected array $allChecklistsByLanguage = [];

    /**
     * Cache of selected checklist IDs grouped by assessment_id.
     *
     * @var array<int|string, array<int>>
     */
    protected array $selectedChecklistsByAssessment = [];

    /**
     * Create a new AssessmentPeriodStudentDataSheet instance.
     *
     * @param array $filters
     */
    public function __construct(array $filters = [], ?string $period = null)
    {
        $this->filters = $filters;
        $this->period = $period === null ? null : strtoupper($period);
    }

    /**
     * Worksheet tab title.
     *
     * @return string
     */
    public function title(): string
    {
        return $this->period === null ? 'Raw Data' : $this->period . ' - Student Data';
    }

    /**
     * Chunk size for streaming database queries.
     *
     * @return int
     */
    public function chunkSize(): int
    {
        return 1000;
    }

    /**
     * Table column headers.
     *
     * @return array
     */
    public function headings(): array
    {
        return [
            'Organisation',
            'Student',
            'Division',
            'Language',
            'Grade',
            'From Group',
            'To Group',
            'Conducted By',
            'Gender',
            'Year',
            'Date',
            'Assessment Type',
            'Passage No',
            'Passage',
            'Correct Words',
            'No Error Words',
            'Accuracy (%)',
            'Benchmark Goal',
            'Checklist',
        ];
    }

    /**
     * Column widths mapping (columns A through S).
     *
     * @return array
     */
    public function columnWidths(): array
    {
        return [
            'A' => 24, // Organisation
            'B' => 22, // Student
            'C' => 12, // Division
            'D' => 14, // Language
            'E' => 14, // Grade
            'F' => 20, // From Group
            'G' => 16, // To Group
            'H' => 22, // Conducted By
            'I' => 12, // Gender
            'J' => 10, // Year
            'K' => 14, // Date
            'L' => 18, // Assessment Type
            'M' => 14, // Passage No
            'N' => 24, // Passage
            'O' => 16, // Correct Words
            'P' => 16, // No Error Words
            'Q' => 16, // Accuracy (%)
            'R' => 18, // Benchmark Goal
            'S' => 35, // Checklist
        ];
    }

    /**
     * Build the streaming query.
     *
     * @return Builder
     */
    public function query(): Builder
    {
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
                's.division as division',
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
                'a.passage_id'
            )
            ->orderBy('a.created', 'desc');

        // Apply filters
        if (!empty($this->filters['language'])) {
            $query->where('l.name', $this->filters['language']);
        }
        if (isset($this->filters['division']) && $this->filters['division'] !== '') {
            $query->where('s.division', $this->filters['division']);
        }
        if (!empty($this->filters['grade'])) {
            $gradeIds = self::parseUuidList($this->filters['grade']);
            if (!empty($gradeIds)) {
                $query->whereIn('a.grade_id', $gradeIds);
            }
        }
        if (!empty($this->filters['organisation'])) {
            $query->where('a.organisation_id', $this->filters['organisation']);
        }
        if (isset($this->filters['type']) && $this->filters['type'] !== null && $this->filters['type'] !== '') {
            $query->where('a.type', $this->filters['type']);
        }
        if (!empty($this->filters['from']) && !empty($this->filters['to'])) {
            $query->whereDate('a.created', '>=', $this->filters['from'])
                ->whereDate('a.created', '<=', $this->filters['to']);
        }

        if ($this->period !== null) {
            // Match the same resolved periods as the summaries, including records
            // whose period falls back to organisation/language configuration.
            $contexts = (clone $query)
                ->reorder()
                ->select('a.organisation_id', 'p.language_id', 'a.assessment_period')
                ->distinct()
                ->get()
                ->filter(function ($context) {
                    return $this->resolveBenchmarkPeriod(
                        $context->organisation_id,
                        $context->language_id,
                        $context->assessment_period
                    ) === $this->period;
                });

            $query->where(function (Builder $periodQuery) use ($contexts) {
                $periodQuery->whereRaw('1 = 0');
                foreach ($contexts as $context) {
                    $periodQuery->orWhere(function (Builder $match) use ($context) {
                        $match->where('a.organisation_id', $context->organisation_id)
                            ->where('p.language_id', $context->language_id)
                            ->where('a.assessment_period', $context->assessment_period);
                    });
                }
            });
        }

        return $query;
    }

    /**
     * Resolve the checklist status string for a given assessment and language.
     *
     * @param int|string|null $assessmentId
     * @param int|string|null $languageId
     * @return string
     */
    protected function resolveChecklistStatus($assessmentId, $languageId): string
    {
        if (!$assessmentId || !$languageId) {
            return '';
        }

        if (!isset($this->allChecklistsByLanguage[$languageId])) {
            $this->allChecklistsByLanguage[$languageId] = DB::table('assessment_checklist')
                ->where('language_id', $languageId)
                ->orderBy('id')
                ->get();
        }

        $checklistsForLang = $this->allChecklistsByLanguage[$languageId];
        if ($checklistsForLang->isEmpty()) {
            return '';
        }

        if (!isset($this->selectedChecklistsByAssessment[$assessmentId])) {
            $this->selectedChecklistsByAssessment[$assessmentId] = DB::table('assessment_assessment_checklists')
                ->where('assessment_id', $assessmentId)
                ->pluck('checklist_id')
                ->toArray();
        }

        $selectedForAssessment = $this->selectedChecklistsByAssessment[$assessmentId];

        $sentence = '';
        foreach ($checklistsForLang as $check) {
            $status = in_array($check->id, $selectedForAssessment) ? 'Done' : 'Not Done';
            $sentence .= $check->title . ' - ' . $status . " \n";
        }

        return trim($sentence);
    }

    /**
     * Map each database record on the fly into an exported worksheet row.
     *
     * @param \stdClass $row
     * @return array
     */
    public function map($row): array
    {
        // Calculate reading metrics: correct words and accuracy percentage
        $lastWordIndex = $row->last_word_index !== null ? (int) $row->last_word_index : -1;
        $total_words = max(0, $lastWordIndex + 1);
        $errorWords = (int) ($row->no_error_words ?? 0);
        $correct_words = max(0, $total_words - $errorWords);
        $accuracy = $total_words > 0 ? round(max(0.0, min(100.0, ($correct_words * 100.0) / $total_words)), 2) : 0.0;

        // Decrypt student name components and format conductor name
        $student_name = trim($this->tryDecrypt($row->student_first_name) . ' ' . $this->tryDecrypt($row->student_last_name));
        $conductor_name = trim($row->conductor_first_name . ' ' . $row->conductor_last_name);
        $created = Carbon::parse($row->created);

        // Resolve dynamic benchmark goal for the student's org, language, grade, and level
        $goal = $this->resolveBenchmarkGoal(
            $row->organisation_id,
            $row->language_id,
            $row->grade_id,
            $row->level_id,
            $row->assessment_period
        );

        $genderLabel = $this->genderLabel($row->student_gender) ?? 'Other';
        $assessmentType = $row->assessment_type_code == 0 ? 'Progress Monitoring' : 'Benchmark';
        $gradeTitle = $row->grade_title ?: 'Unknown Grade';

        return [
            $row->organisation_name,
            $student_name,
            $row->division ?? '',
            $row->language_name ?? '',
            $gradeTitle,
            $row->assessment_type_code == 1 ? 'G' . $row->grade_level . ' Benchmarking' : $row->group_title,
            $row->assessment_type_code == 1 ? $row->group_title : '-',
            $conductor_name,
            $genderLabel,
            $created->year,
            $created->format('Y-m-d'),
            $assessmentType,
            $row->grade_level . '-' . $row->passage_number,
            $row->passage_name,
            $correct_words,
            $errorWords,
            $accuracy,
            $goal,
            $this->resolveChecklistStatus($row->assessment_id, $row->language_id),
        ];
    }

    /**
     * Apply header styling to the student data table.
     *
     * @param Worksheet $sheet
     * @return array
     */
    public function styles(Worksheet $sheet)
    {
        // Solid red table header across columns A to S
        $sheet->getStyle('A1:S1')->applyFromArray([
            'font' => [
                'bold'  => true,
                'size'  => 11,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->getRowDimension(1)->setRowHeight(26);
        return [];
    }
}
