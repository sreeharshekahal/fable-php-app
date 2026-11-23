<?php

namespace App\Exports;

use App\Constants\TranslationConstants;
use App\Exports\Traits\HasAssessmentExportHelpers;
use App\Models\BenchmarkTemplate;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Shared population, benchmark resolution and metrics for both summary sheets. */
class AssessmentSummaryData
{
    use HasAssessmentExportHelpers;

    public const COUNTING_NOTE = 'All assessments matching the selected filters are included in the summary and charts.';

    private array $filters;

    public function __construct(array $filters)
    {
        $this->filters = $filters;
    }

    /**
     * All assessments matching the requested filters.
     * Aligns 1:1 with the records listed in the Student Data sheet.
     */
    public function query(array $periods = []): Builder
    {
        // Use the recorded destination, including accuracy-based reassignments.
        // Older assessments without history retain their stored assessment group.
        $destination = DB::table('assessment_assessmentstudentgrouphistory as history')
            ->whereColumn('history.assessment_id', 'a.id')
            ->whereNotNull('history.to_group_id')
            ->orderByDesc('history.created')->orderByDesc('history.id')
            ->select('history.to_group_id')->limit(1);

        $query = DB::table('assessment_assessment as a')
            ->leftJoin('passage_passage as p', 'a.passage_id', '=', 'p.id')
            ->leftJoin('common_language as l', 'p.language_id', '=', 'l.id')
            ->leftJoin('common_grade as g', 'a.grade_id', '=', 'g.id')
            ->leftJoin('access_student as s', 'a.student_id', '=', 's.id')
            ->leftJoin('organisation_group as destination_group', function ($join) use ($destination) {
                $join->on('destination_group.id', '=', DB::raw('COALESCE((' . $destination->toSql() . '), a.group_id)'));
            })
            ->leftJoin('common_level as destination_level', 'destination_group.level_id', '=', 'destination_level.id')
            ->select(
                'a.id',
                'a.student_id',
                'destination_level.rank as to_group_rank',
                's.division',
                'a.organisation_id',
                'a.grade_id',
                'a.assessment_period',
                'a.last_word_index',
                'a.no_error_words',
                'p.language_id',
                'l.name as language_name',
                'g.title as grade_title',
                'g.level as grade_level'
            );

        // Match the student sheets: apply the same organisation, language, division,
        // assessment type and date scope before discovering periods or calculating metrics.
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

        return $query;
    }

    /**
     * Retrieve the active canonical assessment periods present in the filtered dataset,
     * resolving null assessment periods using the benchmark period fallback hierarchy.
     *
     * @return array<string>
     */
    public function availablePeriods(): array
    {
        $tuples = $this->query()
            ->select('a.organisation_id', 'p.language_id', 'a.assessment_period')
            ->distinct()
            ->get();

        $periods = [];
        foreach ($tuples as $tuple) {
            $p = $this->resolveBenchmarkPeriod(
                $tuple->organisation_id,
                $tuple->language_id,
                $tuple->assessment_period
            );
            if ($p && in_array($p, BenchmarkTemplate::PERIODS, true)) {
                $periods[$p] = true;
            }
        }

        return array_values(array_filter(BenchmarkTemplate::PERIODS, fn ($p) => isset($periods[$p])));
    }

    /** @var Collection|null Memoized collection of records */
    protected ?Collection $cachedRecords = null;

    /**
     * Retrieve the flat collection of all assessment records matching the filters and period.
     *
     * @param array $periods
     * @return Collection
     */
    public function records(array $periods = []): Collection
    {
        if ($this->cachedRecords === null) {
            $retell = DB::table('assessment_assessment_checklists as aac')
                ->join('assessment_checklist as ac', 'aac.checklist_id', '=', 'ac.id')
                ->whereColumn('aac.assessment_id', 'a.id')
                ->whereColumn('ac.language_id', 'p.language_id')
                ->where('ac.type', 1)
                ->selectRaw('COUNT(DISTINCT ac.id)');

            $records = $this->query()
                ->selectSub($retell, 'retell_count')
                ->orderByRaw('COALESCE(g.level, 999) ASC')
                ->orderBy('g.title')->orderBy('l.name')->orderBy('a.organisation_id')
                ->get();

            foreach ($records as $record) {
                $record->assessment_period = $this->resolveBenchmarkPeriod(
                    $record->organisation_id,
                    $record->language_id,
                    $record->assessment_period
                );
            }

            $this->cachedRecords = $records;
        }

        if (!empty($periods)) {
            return $this->cachedRecords->filter(fn ($record) => in_array($record->assessment_period, $periods, true));
        }

        return $this->cachedRecords;
    }

    /** Keep languages separate even when the export has no language filter. */
    public function groups(array $periods = []): Collection
    {
        $records = $this->records($periods);

        return $records->groupBy(fn ($record) => json_encode([
            $record->organisation_id, $record->grade_id, $record->language_id,
        ]));
    }

    /** Report layout is explicitly selected, independently of population completeness. */
    public function isDivisionReport(): bool
    {
        return ($this->filters['report_type'] ?? 'overall') === 'division';
    }

    public function gradeDisplay(object $record): string
    {
        $title = $record->grade_title ?: 'Grade';
        $display = preg_match('/^G(\d+)$/i', $title, $matches) ? "Grade {$matches[1]}" : $title;
        if (empty($this->filters['language'])) {
            $display .= " ({$record->language_name})";
        }
        if (empty($this->filters['organisation'])) {
            $display .= " [{$record->organisation_id}]";
        }

        return $display;
    }

    /** Summarize assessment metrics across configured benchmark levels. */
    public function summarize(Collection $records): array
    {
        if ($records->isEmpty()) {
            return ['totalStudents' => 0, 'levelMetrics' => []];
        }

        $context = $records->first();
        // Benchmark parameters supply the displayed score ranges; they do not
        // reclassify records, whose intervention level comes from To Group.
        $params = $this->parameters($context);
        $metrics = [];
        foreach ($params as $key => $parameter) {
            $metrics[$key] = ['count' => 0, 'acc_sum' => 0.0, 'retell_ge2' => 0, 'param' => $parameter];
        }

        foreach ($records as $record) {
            // Convert the zero-based word index into words attempted, then subtract
            // errors to calculate accuracy independently of the assigned group.
            $lastWordIndex = $record->last_word_index !== null ? (int) $record->last_word_index : -1;
            $attempted = max(0, $lastWordIndex + 1);
            $errors = (int) ($record->no_error_words ?? 0);
            $score = max(0, $attempted - $errors);

            // Classify by the destination level rank, preserving recorded reassignments
            // even when the reading score alone would suggest another intervention.
            $levelsByRank = [1 => 'Benchmarking', 2 => 'Fluency Intervention', 3 => 'Intensive Intervention', 4 => 'High Priority'];
            $level = $levelsByRank[(int) ($record->to_group_rank ?? 0)] ?? null;
            // Unrecognised destinations contribute neither metrics nor the denominator.
            // Keep their raw assessment records available in the student sheets.
            if ($level === null) {
                continue;
            }

            $metrics[$level]['count']++;
            // Accumulate accuracy and qualifying retells within this destination group;
            // the sheets divide these totals by the group count for the displayed metrics.
            $metrics[$level]['acc_sum'] += $attempted > 0 ? ($score * 100.0) / $attempted : 0.0;
            $metrics[$level]['retell_ge2'] += (int) ($record->retell_count ?? 0) >= 2 ? 1 : 0;
        }

        // Use only classified records so intervention percentages add up consistently.
        // This total counts assessment records, including repeat assessments per student.
        return ['totalStudents' => array_sum(array_column($metrics, 'count')), 'levelMetrics' => $metrics];
    }

    protected function parameters(object $context): array
    {
        $templateId = $this->resolveBenchmarkTemplateId($context->organisation_id, $context->language_id);
        $levels = DB::table('common_level')->whereIn('rank', [1, 2, 3, 4])->orderBy('rank')->get();
        $keys = [1 => 'Benchmarking', 2 => 'Fluency Intervention', 3 => 'Intensive Intervention', 4 => 'High Priority'];
        if ($levels->count() !== 4 || $levels->pluck('rank')->map(fn ($rank) => (int) $rank)->all() !== [1, 2, 3, 4]) {
            $this->invalid($context, 'The four benchmark levels must be configured with unique ranks 1–4.');
        }

        $result = [];
        foreach ($levels as $level) {
            $rank = (int) $level->rank;
            $base = DB::table('common_groupingparameter')
                ->where('language_id', $context->language_id)
                ->where('grade_id', $context->grade_id)
                ->where('level_id', $level->id);

            // Preserve precedence: template/period, default/period,
            // template/no period, default/no period. Every tier is language-scoped.
            $tiers = $templateId
                ? [[$templateId, $context->assessment_period], [null, $context->assessment_period], [$templateId, null], [null, null]]
                : [[null, $context->assessment_period], [null, null]];
            $parameter = null;
            foreach ($tiers as [$template, $period]) {
                $matches = (clone $base)->where('benchmark_template_id', $template)
                    ->where('assessment_period', $period)->get();
                if ($matches->count() > 1) {
                    $this->invalid($context, "Duplicate benchmark boundaries for {$keys[$rank]}.");
                }
                if ($matches->isNotEmpty()) {
                    $parameter = $matches->first();
                    break;
                }
            }

            if (!$parameter || $parameter->lower_bound === null || $parameter->upper_bound === null) {
                $this->invalid($context, "Missing benchmark boundaries for {$keys[$rank]}.");
            }
            $lower = filter_var($parameter->lower_bound, FILTER_VALIDATE_INT);
            $upper = filter_var($parameter->upper_bound, FILTER_VALIDATE_INT);
            if ($lower === false || $upper === false || $lower < 0 || $upper < $lower) {
                $this->invalid($context, "Invalid benchmark boundaries for {$keys[$rank]}.");
            }
            if ($rank === 4 && $lower !== 0) {
                $this->invalid($context, 'High Priority benchmark boundaries must start at zero.');
            }

            $title = TranslationConstants::GROUP_TRANSLATIONS[$level->title][$context->language_name] ?? $keys[$rank];
            $result[$keys[$rank]] = [
                'lower' => $lower,
                'upper' => $upper,
                'descriptor' => $rank === 1 ? "{$title} ({$lower} & above)" : "{$title} ({$lower} to {$upper})",
            ];
        }

        return $result;
    }

    private function invalid(object $context, string $message): void
    {
        throw ValidationException::withMessages([
            'benchmarks' => "{$context->language_name}, {$context->grade_title}, {$context->assessment_period} "
                . "(organisation {$context->organisation_id}): {$message}",
        ]);
    }
}
