<?php

namespace App\Http\Controllers;

use App\Models\Accuracy;
use App\Models\BenchmarkTemplate;
use App\Models\GroupingParameter;
use App\Models\OrganisationLanguage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    /**
     * Format a timestamp into a standardized ISO 8601 UTC string.
     *
     * @param mixed $val The value to format.
     * @return string|null The formatted date string or null if input is empty.
     */
    protected function formatAssessmentTime($val): ?string
    {
        if (!$val) return null;
        $carbon = \Carbon\Carbon::parse($val)->utc();
        return $carbon->micro === 0
            ? $carbon->format('Y-m-d\TH:i:s\Z')
            : $carbon->format('Y-m-d\TH:i:s.u\Z');
    }
    /**
     * Format a datetime value into Asia/Kolkata timezone string.
     *
     * @param mixed $val The value to format.
     * @return string|null The formatted date string or null if input is empty.
     */
    protected function formatDateTime($val): ?string
    {
        if (!$val) return null;
        return \Carbon\Carbon::parse($val)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i:s.uP');
    }
    /**
     * Sanitize and validate a UUID string.
     *
     * @param string|null $val The UUID string to sanitize.
     * @return string|null The sanitized UUID or null if invalid.
     */
    protected function sanitizeUuid(?string $val): ?string
    {
        if (!$val) return null;
        // Truncate if too long to prevent excessive processing
        $val = substr($val, 0, 64);
        // Remove whitespace, quotes, and encoded quotes common in malformed requests
        $val = trim(str_replace(['"', "'", ' ', '%22', '\n', '\r', '\t'], '', $val));
        // Validate it's a UUID
        if (!\Illuminate\Support\Str::isUuid($val)) {
            return null;
        }
        return $val;
    }
    /**
     * Find a benchmark template for a specific organisation and language pair.
     *
     * @param string|null $organisationId
     * @param string|null $languageId
     * @return BenchmarkTemplate|null
     */
    protected function findBenchmarkTemplate(?string $organisationId, ?string $languageId): ?BenchmarkTemplate
    {
        if (!$organisationId || !$languageId) {
            return null;
        }

        return BenchmarkTemplate::findForOrganisationLanguage($organisationId, $languageId);
    }
    /**
     * Resolve the template ID for an organisation and language pair.
     *
     * @param string|null $organisationId
     * @param string|null $languageId
     * @return string|null
     */
    protected function resolveBenchmarkTemplateId(?string $organisationId, ?string $languageId): ?string
    {
        return $this->findBenchmarkTemplate($organisationId, $languageId)?->id;
    }
    /**
     * Resolve the active assessment period for a template.
     *
     * @param string|null $organisationId
     * @param string|null $languageId
     * @param string|null $assessmentPeriod Optional override period.
     * @return string|null
     */
    protected function resolveBenchmarkPeriod(?string $organisationId, ?string $languageId, ?string $assessmentPeriod = null): ?string
    {
        if ($assessmentPeriod && in_array($assessmentPeriod, BenchmarkTemplate::PERIODS, true)) {
            return $assessmentPeriod;
        }

        if ($organisationId && $languageId) {
            $orgLangPeriod = OrganisationLanguage::where('organisation_id', $organisationId)
                ->where('language_id', $languageId)
                ->value('assessment_period');

            if ($orgLangPeriod && in_array($orgLangPeriod, BenchmarkTemplate::PERIODS, true)) {
                return $orgLangPeriod;
            }
        }

        $templatePeriod = $this->findBenchmarkTemplate($organisationId, $languageId)?->assessment_period;
        if ($templatePeriod && in_array($templatePeriod, BenchmarkTemplate::PERIODS, true)) {
            return $templatePeriod;
        }

        // If no period is resolved, check if there is any Default Benchmark (GroupingParameter with null template id)
        // set with assessment_period BOY/MOY/EOY.
        if ($languageId) {
            $period = GroupingParameter::whereNull('benchmark_template_id')
                ->where('language_id', $languageId)
                ->whereIn('assessment_period', BenchmarkTemplate::PERIODS)
                ->orderByRaw("CASE assessment_period WHEN 'BOY' THEN 1 WHEN 'MOY' THEN 2 WHEN 'EOY' THEN 3 END")
                ->value('assessment_period');

            if ($period) {
                return $period;
            }
        }

        $period = GroupingParameter::whereNull('benchmark_template_id')
            ->whereIn('assessment_period', BenchmarkTemplate::PERIODS)
            ->orderByRaw("CASE assessment_period WHEN 'BOY' THEN 1 WHEN 'MOY' THEN 2 WHEN 'EOY' THEN 3 END")
            ->value('assessment_period');

        if ($period) {
            return $period;
        }

        return null;
    }

    /**
     * Resolve the target benchmark goal value.
     *
     * @param string|null $organisationId
     * @param string|null $languageId
     * @param string|null $gradeId
     * @param string|null $levelId
     * @param string|null $assessmentPeriod
     * @return int
     */
    protected function resolveBenchmarkGoal(
        ?string $organisationId,
        ?string $languageId,
        ?string $gradeId,
        ?string $levelId,
        ?string $assessmentPeriod = null
    ): int {
        if (!$languageId || !$gradeId || !$levelId) {
            return 0;
        }

        $templateId = $this->resolveBenchmarkTemplateId($organisationId, $languageId);
        $resolvedPeriod = $this->resolveBenchmarkPeriod($organisationId, $languageId, $assessmentPeriod);

        // Get the base query for GroupingParameters
        $baseQuery = GroupingParameter::query()
            ->where('grade_id', $gradeId)
            ->where('level_id', $levelId)
            ->where('language_id', $languageId);

        // 1. Check for benchmark_template_id not null and assessment_period not null (if both are available)
        if ($templateId && $resolvedPeriod) {
            $goal = (clone $baseQuery)
                ->where('benchmark_template_id', $templateId)
                ->where('assessment_period', $resolvedPeriod)
                ->value('goal');
            if (!is_null($goal)) {
                return (int) $goal;
            }
        }

        // 2. Check for benchmark_template_id null and assessment_period not null (if period is available)
        if ($resolvedPeriod) {
            $goal = (clone $baseQuery)
                ->whereNull('benchmark_template_id')
                ->where('assessment_period', $resolvedPeriod)
                ->value('goal');
            if (!is_null($goal)) {
                return (int) $goal;
            }
        }

        // 3. Fallback: check for benchmark_template_id not null and assessment_period null (if template is available)
        if ($templateId) {
            $goal = (clone $baseQuery)
                ->where('benchmark_template_id', $templateId)
                ->whereNull('assessment_period')
                ->value('goal');
            if (!is_null($goal)) {
                return (int) $goal;
            }
        }

        // 4. Check for default benchmark with assessment_period BOY/MOY/EOY
        $goal = (clone $baseQuery)
            ->whereNull('benchmark_template_id')
            ->whereIn('assessment_period', BenchmarkTemplate::PERIODS)
            ->orderByRaw("CASE assessment_period WHEN 'BOY' THEN 1 WHEN 'MOY' THEN 2 WHEN 'EOY' THEN 3 END")
            ->value('goal');
        if (!is_null($goal)) {
            return (int) $goal;
        }

        // 5. Check for both benchmark_template_id null and assessment_period null
        $goal = (clone $baseQuery)
            ->whereNull('benchmark_template_id')
            ->whereNull('assessment_period')
            ->value('goal');

        return (int) ($goal ?? 0);
    }

    /**
     * Resolve the appropriate grouping parameter (benchmark) for a score.
     *
     * @param string|null $organisationId
     * @param string|null $languageId
     * @param string|null $gradeId
     * @param int $correctWords
     * @param string|null $assessmentPeriod
     * @return GroupingParameter|null
     */
    protected function resolveBenchmarkGroupingParameter(
        ?string $organisationId,
        ?string $languageId,
        ?string $gradeId,
        int $correctWords,
        ?string $assessmentPeriod = null
    ): ?GroupingParameter {
        if (!$languageId || !$gradeId) {
            return null;
        }

        $templateId = $this->resolveBenchmarkTemplateId($organisationId, $languageId);
        $resolvedPeriod = $this->resolveBenchmarkPeriod($organisationId, $languageId, $assessmentPeriod);

        $baseQuery = GroupingParameter::query()
            ->where('grade_id', $gradeId)
            ->where('upper_bound', '>=', $correctWords)
            ->where('lower_bound', '<=', $correctWords)
            ->where('language_id', $languageId);

        // 1. Check for benchmark_template_id not null and assessment_period not null
        if ($templateId && $resolvedPeriod) {
            $gp = (clone $baseQuery)
                ->where('benchmark_template_id', $templateId)
                ->where('assessment_period', $resolvedPeriod)
                ->first();
            if ($gp) {
                return $gp;
            }
        }

        // 2. Check for benchmark_template_id null and assessment_period not null
        if ($resolvedPeriod) {
            $gp = (clone $baseQuery)
                ->whereNull('benchmark_template_id')
                ->where('assessment_period', $resolvedPeriod)
                ->first();
            if ($gp) {
                return $gp;
            }
        }

        // 3. Fallback: check for benchmark_template_id not null and assessment_period null
        if ($templateId) {
            $gp = (clone $baseQuery)
                ->where('benchmark_template_id', $templateId)
                ->whereNull('assessment_period')
                ->first();
            if ($gp) {
                return $gp;
            }
        }

        // 4. Check for default benchmark with assessment_period BOY/MOY/EOY
        $gp = (clone $baseQuery)
            ->whereNull('benchmark_template_id')
            ->whereIn('assessment_period', BenchmarkTemplate::PERIODS)
            ->orderByRaw("CASE assessment_period WHEN 'BOY' THEN 1 WHEN 'MOY' THEN 2 WHEN 'EOY' THEN 3 END")
            ->first();
        if ($gp) {
            return $gp;
        }

        // 5. Check for both benchmark_template_id null and assessment_period null
        return (clone $baseQuery)
            ->whereNull('benchmark_template_id')
            ->whereNull('assessment_period')
            ->first();
    }

    /**
     * Resolve the benchmark accuracy requirement.
     *
     * @param string|null $organisationId
     * @param string|null $languageId
     * @param string|null $gradeId
     * @param string|null $levelId
     * @param string|null $assessmentPeriod
     * @return Accuracy|null
     */
    protected function resolveBenchmarkAccuracy(
        ?string $organisationId,
        ?string $languageId,
        ?string $gradeId,
        ?string $levelId = null,
        ?string $assessmentPeriod = null
    ): ?Accuracy {
        if (!$languageId || !$gradeId) {
            return null;
        }

        $templateId = $this->resolveBenchmarkTemplateId($organisationId, $languageId);
        $resolvedPeriod = $this->resolveBenchmarkPeriod($organisationId, $languageId, $assessmentPeriod);

        $baseQuery = Accuracy::query()
            ->where('grade_id', $gradeId)
            ->where('language_id', $languageId);

        // 1. Check for benchmark_template_id not null and assessment_period not null
        if ($templateId && $resolvedPeriod) {
            $acc = (clone $baseQuery)
                ->where('benchmark_template_id', $templateId)
                ->where('assessment_period', $resolvedPeriod)
                ->first();
            if ($acc) {
                return $acc;
            }
        }

        // 2. Check for benchmark_template_id null and assessment_period not null
        if ($resolvedPeriod) {
            $acc = (clone $baseQuery)
                ->whereNull('benchmark_template_id')
                ->where('assessment_period', $resolvedPeriod)
                ->first();
            if ($acc) {
                return $acc;
            }
        }

        // 3. Fallback: check for benchmark_template_id not null and assessment_period null
        if ($templateId) {
            $acc = (clone $baseQuery)
                ->where('benchmark_template_id', $templateId)
                ->whereNull('assessment_period')
                ->first();
            if ($acc) {
                return $acc;
            }
        }

        // 4. Check for default accuracy with assessment_period BOY/MOY/EOY
        $acc = (clone $baseQuery)
            ->whereNull('benchmark_template_id')
            ->whereIn('assessment_period', BenchmarkTemplate::PERIODS)
            ->orderByRaw("CASE assessment_period WHEN 'BOY' THEN 1 WHEN 'MOY' THEN 2 WHEN 'EOY' THEN 3 END")
            ->first();
        if ($acc) {
            return $acc;
        }

        // 5. Check for both benchmark_template_id null and assessment_period null
        return (clone $baseQuery)
            ->whereNull('benchmark_template_id')
            ->whereNull('assessment_period')
            ->first();
    }
}
