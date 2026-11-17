<?php

namespace App\Exports\Traits;

use App\Models\BenchmarkTemplate;
use App\Models\GroupingParameter;
use App\Models\OrganisationLanguage;
use Illuminate\Support\Facades\Crypt;

/**
 * Shared helper utilities and memoized lookups for assessment export sheets.
 *
 * Provides in-memory cached lookups for:
 *  - Benchmark templates and active assessment periods
 *  - Dynamic benchmark goals and thresholds
 *  - PII decryption for student names
 *  - Gender display formatting
 */
trait HasAssessmentExportHelpers
{
    /** @var array<string, BenchmarkTemplate|null> Memoized benchmark templates keyed by "{orgId}_{languageId}" */
    protected static array $templateCache = [];

    /** @var array<string, string|null> Memoized template IDs keyed by "{orgId}_{languageId}" */
    protected static array $templateIdCache = [];

    /** @var array<string, string|null> Memoized resolved assessment periods */
    protected static array $periodCache = [];

    /** @var array<string, int> Memoized benchmark goal targets */
    protected static array $goalCache = [];

    /**
     * Convert gender integer code to a human-readable display string.
     *
     * @param int|string|null $val 0 = Male, 1 = Female, 2 = Other
     * @return string|null
     */
    protected function genderLabel($val): ?string
    {
        if ($val === null) {
            return null;
        }
        $map = [0 => 'Male', 1 => 'Female', 2 => 'Other'];
        return $map[(int) $val] ?? (string) $val;
    }

    /**
     * Attempt to decrypt an encrypted string field, safely returning the original value on failure.
     *
     * @param string|null $value
     * @return string|null
     */
    protected function tryDecrypt(?string $value): ?string
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
     * Find a benchmark template for a specific organization and language pair with in-memory memoization.
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

        $key = "{$organisationId}_{$languageId}";
        if (array_key_exists($key, static::$templateCache)) {
            return static::$templateCache[$key];
        }

        static::$templateCache[$key] = BenchmarkTemplate::findForOrganisationLanguage($organisationId, $languageId);
        return static::$templateCache[$key];
    }

    /**
     * Resolve the template ID for an organization and language pair with in-memory memoization.
     *
     * @param string|null $organisationId
     * @param string|null $languageId
     * @return string|null
     */
    protected function resolveBenchmarkTemplateId(?string $organisationId, ?string $languageId): ?string
    {
        if (!$organisationId || !$languageId) {
            return null;
        }

        $key = "{$organisationId}_{$languageId}";
        if (array_key_exists($key, static::$templateIdCache)) {
            return static::$templateIdCache[$key];
        }

        static::$templateIdCache[$key] = $this->findBenchmarkTemplate($organisationId, $languageId)?->id;
        return static::$templateIdCache[$key];
    }

    /**
     * Resolve the active assessment period (BOY, MOY, EOY) using cascading fallbacks with memoization.
     *
     * Fallback hierarchy:
     *   1. Explicit assessment period parameter (if canonical)
     *   2. OrganisationLanguage configuration
     *   3. Matched BenchmarkTemplate configuration
     *   4. GroupingParameter configured for the language
     *   5. Default GroupingParameter
     *
     * @param string|null $organisationId
     * @param string|null $languageId
     * @param string|null $assessmentPeriod
     * @return string|null Resolved period ('BOY', 'MOY', 'EOY') or null
     */
    protected function resolveBenchmarkPeriod(?string $organisationId, ?string $languageId, ?string $assessmentPeriod = null): ?string
    {
        if ($assessmentPeriod && in_array($assessmentPeriod, BenchmarkTemplate::PERIODS, true)) {
            return $assessmentPeriod;
        }

        $key = "{$organisationId}_{$languageId}_" . ($assessmentPeriod ?? 'null');
        if (array_key_exists($key, static::$periodCache)) {
            return static::$periodCache[$key];
        }

        // 1. Check OrganisationLanguage setting
        if ($organisationId && $languageId) {
            $orgLangPeriod = OrganisationLanguage::where('organisation_id', $organisationId)
                ->where('language_id', $languageId)
                ->value('assessment_period');

            if ($orgLangPeriod && in_array($orgLangPeriod, BenchmarkTemplate::PERIODS, true)) {
                static::$periodCache[$key] = $orgLangPeriod;
                return $orgLangPeriod;
            }
        }

        // 2. Check BenchmarkTemplate setting
        $templatePeriod = $this->findBenchmarkTemplate($organisationId, $languageId)?->assessment_period;
        if ($templatePeriod && in_array($templatePeriod, BenchmarkTemplate::PERIODS, true)) {
            static::$periodCache[$key] = $templatePeriod;
            return $templatePeriod;
        }

        // 3. Check language-specific GroupingParameter
        if ($languageId) {
            $period = GroupingParameter::whereNull('benchmark_template_id')
                ->where('language_id', $languageId)
                ->whereIn('assessment_period', BenchmarkTemplate::PERIODS)
                ->orderByRaw("CASE assessment_period WHEN 'BOY' THEN 1 WHEN 'MOY' THEN 2 WHEN 'EOY' THEN 3 END")
                ->value('assessment_period');

            if ($period) {
                static::$periodCache[$key] = $period;
                return $period;
            }
        }

        // 4. Default global GroupingParameter
        $period = GroupingParameter::whereNull('benchmark_template_id')
            ->whereIn('assessment_period', BenchmarkTemplate::PERIODS)
            ->orderByRaw("CASE assessment_period WHEN 'BOY' THEN 1 WHEN 'MOY' THEN 2 WHEN 'EOY' THEN 3 END")
            ->value('assessment_period');

        static::$periodCache[$key] = $period ?: null;
        return static::$periodCache[$key];
    }

    /**
     * Resolve the target benchmark goal value using cascading fallbacks with in-memory memoization.
     *
     * Fallback hierarchy:
     *   1. Template + Specific Period
     *   2. Default (Template NULL) + Specific Period
     *   3. Template + Period NULL
     *   4. Default (Template NULL) + Any Canonical Period (BOY -> MOY -> EOY order)
     *   5. Default (Template NULL) + Period NULL
     *
     * @param string|null $organisationId
     * @param string|null $languageId
     * @param string|null $gradeId
     * @param string|null $levelId
     * @param string|null $assessmentPeriod
     * @return int Target goal number (defaults to 0 if not found)
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

        $cacheKey = "{$organisationId}_{$languageId}_{$gradeId}_{$levelId}_" . ($assessmentPeriod ?? 'null');
        if (array_key_exists($cacheKey, static::$goalCache)) {
            return static::$goalCache[$cacheKey];
        }

        $templateId = $this->resolveBenchmarkTemplateId($organisationId, $languageId);
        $resolvedPeriod = $this->resolveBenchmarkPeriod($organisationId, $languageId, $assessmentPeriod);

        $baseQuery = GroupingParameter::query()
            ->where('grade_id', $gradeId)
            ->where('level_id', $levelId)
            ->where('language_id', $languageId);

        // 1. Template + specific period
        if ($templateId && $resolvedPeriod) {
            $goal = (clone $baseQuery)
                ->where('benchmark_template_id', $templateId)
                ->where('assessment_period', $resolvedPeriod)
                ->value('goal');
            if (!is_null($goal)) {
                static::$goalCache[$cacheKey] = (int) $goal;
                return static::$goalCache[$cacheKey];
            }
        }

        // 2. Default template + specific period
        if ($resolvedPeriod) {
            $goal = (clone $baseQuery)
                ->whereNull('benchmark_template_id')
                ->where('assessment_period', $resolvedPeriod)
                ->value('goal');
            if (!is_null($goal)) {
                static::$goalCache[$cacheKey] = (int) $goal;
                return static::$goalCache[$cacheKey];
            }
        }

        // 3. Template + null period
        if ($templateId) {
            $goal = (clone $baseQuery)
                ->where('benchmark_template_id', $templateId)
                ->whereNull('assessment_period')
                ->value('goal');
            if (!is_null($goal)) {
                static::$goalCache[$cacheKey] = (int) $goal;
                return static::$goalCache[$cacheKey];
            }
        }

        // 4. Default template + earliest canonical period
        $goal = (clone $baseQuery)
            ->whereNull('benchmark_template_id')
            ->whereIn('assessment_period', BenchmarkTemplate::PERIODS)
            ->orderByRaw("CASE assessment_period WHEN 'BOY' THEN 1 WHEN 'MOY' THEN 2 WHEN 'EOY' THEN 3 END")
            ->value('goal');
        if (!is_null($goal)) {
            static::$goalCache[$cacheKey] = (int) $goal;
            return static::$goalCache[$cacheKey];
        }

        // 5. Default template + null period
        $goal = (clone $baseQuery)
            ->whereNull('benchmark_template_id')
            ->whereNull('assessment_period')
            ->value('goal');

        static::$goalCache[$cacheKey] = (int) ($goal ?? 0);
        return static::$goalCache[$cacheKey];
    }
}

