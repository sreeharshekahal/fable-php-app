<?php

namespace App\Services;

use App\Models\Accuracy;
use App\Models\BenchmarkTemplate;
use App\Models\GroupingParameter;
use App\Models\Language;
use App\Models\Grade;
use App\Models\Level;
use App\Models\Organisation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class BenchmarkTemplateService
{
    /**
     * Format a GroupingParameter (benchmark) for API response.
     */
    public function formatBenchmarkParameter(GroupingParameter $gp): array
    {
        $grade = $gp->grade;
        $level = $gp->level;

        return [
            'id'                => $gp->id,
            'name'              => $gp->name,
            'lower_bound'       => (int) $gp->lower_bound,
            'upper_bound'       => (int) $gp->upper_bound,
            'goal'              => (int) $gp->goal,
            'level'             => $gp->level_id,
            'grade'             => $gp->grade_id,
            'language_id'       => $gp->language_id,
            'assessment_period' => $gp->assessment_period,
            'grade_details'     => $grade ? [
                'id'    => $grade->id,
                'title' => $grade->title,
                'level' => (int) $grade->level,
            ] : null,
            'level_details'     => $level ? [
                'id'    => $level->id,
                'title' => $level->title,
                'rank'  => (int) $level->rank,
            ] : null,
        ];
    }

    /**
     * Format an Accuracy for API response.
     */
    public function formatAccuracy(Accuracy $acc): array
    {
        $grade = $acc->grade;

        return [
            'id'                => $acc->id,
            'grade_id'          => $acc->grade_id,
            'language_id'       => $acc->language_id,
            'percentage'        => (int) $acc->percentage,
            'assessment_period' => $acc->assessment_period,
            'grade_details'     => $grade ? [
                'id'    => $grade->id,
                'title' => $grade->title,
                'level' => (int) $grade->level,
            ] : null
        ];
    }

    /**
     * Format template for API response.
     */
    public function formatBenchmarkTemplate(BenchmarkTemplate $benchmarkTemplate, Request $request, ?string $period = null, bool $ignoreRequestPeriod = false): array
    {
        $activePeriods = $benchmarkTemplate->normalizedActiveAssessmentPeriods();

        $selectedLanguageId = null;
        $selectedGradeId = null;

        // 1. Resolve period: priority order is:
        //    - Explicit $period argument (if passed from other methods)
        //    - Request input 'assessment_period' (unless ignored)
        //    - Template's global 'assessment_period'
        //    - First available active period from normalized list

        $filters = $this->extractRequestFilters($request, $period);
        // $selectedLanguageId = $filters['selectedLanguageId'];
        // $selectedGradeId = $filters['selectedGradeId'];

        $resolvedPeriod = $period ?: (!$ignoreRequestPeriod ? $filters['targetPeriod'] : null);

        if (!$resolvedPeriod) {
            if ($selectedLanguageId) {
                $resolvedPeriod = $benchmarkTemplate->activeAssessmentPeriodForLanguage($selectedLanguageId);
            }
            if (!$resolvedPeriod) {
                $resolvedPeriod = $benchmarkTemplate->assessment_period;
            }
            if (!$resolvedPeriod && !empty($activePeriods)) {
                $resolvedPeriod = reset($activePeriods);
            }
        }

        // Only filter by period if specifically requested in the URL or passed to this method.
        // Otherwise, return all parameters (BOY, MOY, EOY) so they are all visible in the JSON.
        $filterPeriod = $period ?: (!$ignoreRequestPeriod ? $filters['targetPeriod'] : null);

        // Get grouping parameters
        $templateGps = $this->templateGroupingParameters($benchmarkTemplate, $request, $filterPeriod, $selectedLanguageId);

        // Get overall grouping parameters
        $overallGps = $this->overallGroupingParameters($request, $filterPeriod, $selectedLanguageId);

        // Merge template and overall grouping parameters to fallback for missing parameters
        $groupingParameters = $this->mergeWithOverallDefaults($templateGps, $overallGps, $benchmarkTemplate->language_ids ?? [], $selectedGradeId, $benchmarkTemplate->id, $selectedLanguageId, GroupingParameter::class)
            ->map(fn($gp) => $this->formatBenchmarkParameter($gp))
            ->values();

        // Get accuracies
        $templateAccs = $this->templateAccuracies($benchmarkTemplate, $request, $filterPeriod, $selectedLanguageId);

        // Get overall accuracies
        $overallAccs = $this->overallAccuracies($request, $filterPeriod, $selectedLanguageId);

        // Merge template and overall accuracies to fallback for missing accuracies
        $mergedAccs = $this->mergeWithOverallDefaults($templateAccs, $overallAccs, $benchmarkTemplate->language_ids ?? [], $selectedGradeId, $benchmarkTemplate->id, $selectedLanguageId, Accuracy::class);

        $accuracies = $mergedAccs
            ->sortByDesc(function ($acc) {
                return is_null($acc->benchmark_template_id) ? 0 : 1;
            })
            ->map(function ($acc) {
                return $this->formatAccuracy($acc);
            })
            ->values();

        return [
            'id'                        => $benchmarkTemplate->id,
            'name'                      => $benchmarkTemplate->name,
            'organisation_ids'          => $benchmarkTemplate->organisation_ids,
            'language_ids'              => $benchmarkTemplate->language_ids,
            'assessment_period'         => $filterPeriod ?: $benchmarkTemplate->assessment_period,
            'active_organisations_by_period' => $this->getActiveOrganisationsByPeriod($benchmarkTemplate),
            'available_assessment_periods' => BenchmarkTemplate::PERIODS,
            'created_at'                => $this->formatDateTime($benchmarkTemplate->created_at),
            'updated_at'                => $this->formatDateTime($benchmarkTemplate->updated_at),
            'grouping_parameters'       => $groupingParameters,
            'accuracies'                => $accuracies,
        ];
    }

    /**
     * Get list of benchmark templates.
     */
    public function getBenchmarkTemplateList(Request $request): array
    {
        $limit  = $request->input('limit');
        $offset = $request->input('offset', 0);

        $query = BenchmarkTemplate::query();

        // Organisation Filter
        if ($request->has('organisation_id')) {
            $orgId = $this->sanitizeUuid($request->input('organisation_id'));
            if ($orgId) {
                $query->whereJsonContains('organisation_ids', $orgId);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // Language filter
        if ($request->has('language_id') || $request->has('language')) {
            $query->whereJsonContains('language_ids', $request->input('language', $request->input('language_id')));
        }

        $totalCount = $query->count();

        if ($limit) {
            $query->skip($offset)->take($limit);
        }

        // Ordered by creation time (newest first)
        $templates = $query->orderBy('created_at', 'desc')->get();

        // Formatting templates for API response
        $formatted = $templates
            ->map(fn($benchmarkTemplate) => $this->formatBenchmarkTemplate($benchmarkTemplate, $request))
            ->toArray();

        $nextUrl = null;
        if ($limit && ($offset + $limit) < $totalCount) {
            $nextUrl = $request->fullUrlWithQuery(['offset' => $offset + $limit]);
        }

        $prevUrl = null;
        if ($offset > 0) {
            $prevUrl = $request->fullUrlWithQuery(['offset' => max(0, $offset - ($limit ?? 10))]);
        }

        // Add overall template at the beginning if offset is 0
        if ((int) $offset === 0) {
            array_unshift($formatted, $this->getOverallTemplate($request));
            $totalCount++;
        }

        return [
            'count'    => $totalCount,
            'next'     => $nextUrl,
            'previous' => $prevUrl,
            'results'  => $formatted,
        ];
    }

    /**
     * Get a single benchmark template by ID.
     */
    public function getTemplateById(string $id, Request $request)
    {
        if ($id === 'overall') {
            return $this->getOverallTemplate($request);
        }

        $id = $this->sanitizeUuid($id);
        if (!$id) {
            return null; // Handle error in controller
        }

        $benchmarkTemplate = BenchmarkTemplate::find($id);

        if (!$benchmarkTemplate) {
            return false; // Handle not found in controller
        }

        return $this->formatBenchmarkTemplate($benchmarkTemplate, $request);
    }

    /**
     * Helper to fetch and format overall template.
     *
     * When assessment_period is specified (BOY/MOY/EOY):
     *   - Loads base Overall records (assessment_period = NULL)
     *   - Loads period-specific override records
     *   - Merges: period overrides win, base values fill gaps
     *   - Returns a fully resolved dataset (no frontend fallback needed)
     *
     * When assessment_period is not specified:
     *   - Returns base Overall records only
     */
    public function getOverallTemplate(Request $request): array
    {
        $resolvedPeriod = $request->query('assessment_period') ?: $request->query('period') ?: null;

        // Load grouping parameters
        $formattedOverallGp = $this->resolveOverallGroupingParameters($request, $resolvedPeriod)
            ->map(fn($gp) => $this->formatBenchmarkParameter($gp))
            ->values();

        // Load accuracies
        $formattedOverallAcc = $this->resolveOverallAccuracies($request, $resolvedPeriod)
            ->unique(function ($acc) {
                return $acc->grade_id . '-' . $acc->language_id;
            })
            ->map(function ($acc) {
                $acc->level_id = null;
                $acc->setRelation('level', null);
                return $this->formatAccuracy($acc);
            })
            ->values();

        // Get all organization ids assigned to any benchmark template
        $assignedOrganisationIds = BenchmarkTemplate::all()
            ->pluck('organisation_ids')
            ->flatten()
            ->unique()
            ->toArray();

        // Get all organization ids
        $allOrganisationIds = Organisation::pluck('id')->toArray();

        // Get organization ids that are not assigned to any benchmark template
        $overallOrganisationIds = array_values(array_diff($allOrganisationIds, $assignedOrganisationIds));

        // Automatically clean up any legacy overall active organisations by period in the DB
        // Moved to updateOverallTemplate to avoid DB writes during GET requests.

        $languages = Language::pluck('id')->toArray();

        return [
            'id'                           => 'overall',
            'name'                         => 'Overall',
            'organisation_ids'             => $overallOrganisationIds,
            'language_ids'                 => $languages,
            'assessment_period'            => $resolvedPeriod,
            'active_organisations_by_period' => (object) [],
            'available_assessment_periods' => ['BOY', 'MOY', 'EOY'],
            'created_at'                   => null,
            'updated_at'                   => null,
            'grouping_parameters'          => $formattedOverallGp,
            'accuracies'                   => $formattedOverallAcc,
        ];
    }

    /**
     * Resolve overall grouping parameters with period-aware merging.
     *
     * When a period is specified, loads both base (NULL) and period-specific records,
     * then merges so period overrides win. Returns a fully resolved collection.
     */
    private function resolveOverallGroupingParameters(Request $request, ?string $period = null, ?string $selectedLanguageId = null)
    {
        if (!$period) {
            // No period specified: return base records only (existing behavior)
            return $this->overallGroupingParameters($request, null, $selectedLanguageId);
        }

        // Load base Overall records (assessment_period = NULL)
        $baseRecords = GroupingParameter::whereNull('benchmark_template_id')
            ->whereNull('assessment_period')
            ->where('common_groupingparameter.name', '!=', 'Archived')
            ->when($selectedLanguageId, function ($query, $l) {
                $query->where(function ($q) use ($l) {
                    $q->where('language_id', $l)->orWhereNull('language_id');
                });
            })
            ->leftJoin('common_level', 'common_groupingparameter.level_id', '=', 'common_level.id')
            ->select('common_groupingparameter.*')
            ->with(['level', 'grade', 'language'])
            ->orderBy('language_id')
            ->orderBy('common_level.rank', 'asc')
            ->get();

        // Load period-specific override records
        $periodRecords = GroupingParameter::whereNull('benchmark_template_id')
            ->where('assessment_period', $period)
            ->where('common_groupingparameter.name', '!=', 'Archived')
            ->when($selectedLanguageId, function ($query, $l) {
                $query->where(function ($q) use ($l) {
                    $q->where('language_id', $l)->orWhereNull('language_id');
                });
            })
            ->leftJoin('common_level', 'common_groupingparameter.level_id', '=', 'common_level.id')
            ->select('common_groupingparameter.*')
            ->with(['level', 'grade', 'language'])
            ->orderBy('language_id')
            ->orderBy('common_level.rank', 'asc')
            ->get();

        // Build lookup of period overrides keyed by (grade_id, level_id, language_id)
        $overrideMap = $periodRecords->keyBy(function ($gp) {
            return $gp->grade_id . '|' . $gp->level_id . '|' . $gp->language_id;
        });

        // Merge: period overrides win, base values fill gaps
        $merged = $baseRecords->map(function ($base) use ($overrideMap, $period) {
            $key = $base->grade_id . '|' . $base->level_id . '|' . $base->language_id;

            if ($overrideMap->has($key)) {
                $override = $overrideMap->get($key);
                // Return the period-specific override with its actual assessment_period
                return $override;
            }

            // No override exists: return base record but tag it with the requested period
            // so the response shows the resolved period context
            $clone = $base->replicate();
            $clone->id = $base->id;
            $clone->assessment_period = $period;
            $clone->setRelation('grade', $base->grade);
            $clone->setRelation('level', $base->level);
            $clone->setRelation('language', $base->language);
            return $clone;
        });

        // Add any period records that don't have a base match (shouldn't normally happen, but be safe)
        foreach ($overrideMap as $key => $override) {
            $hasBase = $baseRecords->contains(function ($base) use ($key) {
                return ($base->grade_id . '|' . $base->level_id . '|' . $base->language_id) === $key;
            });
            if (!$hasBase) {
                $merged->push($override);
            }
        }

        return $merged->values();
    }

    /**
     * Resolve overall accuracies with period-aware merging.
     *
     * When a period is specified, loads both base (NULL) and period-specific records,
     * then merges so period overrides win. Returns a fully resolved collection.
     */
    private function resolveOverallAccuracies(Request $request, ?string $period = null, ?string $selectedLanguageId = null)
    {
        if (!$period) {
            // No period specified: return base records only (existing behavior)
            return $this->overallAccuracies($request, null, $selectedLanguageId);
        }

        // Load base Overall records (assessment_period = NULL)
        $baseRecords = Accuracy::whereNull('benchmark_template_id')
            ->whereNull('assessment_period')
            ->when($selectedLanguageId, function ($query, $l) {
                $query->where(function ($q) use ($l) {
                    $q->where('language_id', $l)->orWhereNull('language_id');
                });
            })
            ->leftJoin('common_grade', 'common_accuracy.grade_id', '=', 'common_grade.id')
            ->select('common_accuracy.*')
            ->with(['grade', 'level', 'language'])
            ->orderBy('language_id')
            ->orderBy('common_grade.level', 'asc')
            ->get();

        // Load period-specific override records
        $periodRecords = Accuracy::whereNull('benchmark_template_id')
            ->where('assessment_period', $period)
            ->when($selectedLanguageId, function ($query, $l) {
                $query->where(function ($q) use ($l) {
                    $q->where('language_id', $l)->orWhereNull('language_id');
                });
            })
            ->leftJoin('common_grade', 'common_accuracy.grade_id', '=', 'common_grade.id')
            ->select('common_accuracy.*')
            ->with(['grade', 'level', 'language'])
            ->orderBy('language_id')
            ->orderBy('common_grade.level', 'asc')
            ->get();

        // Build lookup of period overrides keyed by (grade_id, language_id)
        // Note: accuracies use grade_id + language_id (level_id is null for overall)
        $overrideMap = $periodRecords->keyBy(function ($acc) {
            return $acc->grade_id . '|' . $acc->language_id;
        });

        // Merge: period overrides win, base values fill gaps
        $merged = $baseRecords->map(function ($base) use ($overrideMap, $period) {
            $key = $base->grade_id . '|' . $base->language_id;

            if ($overrideMap->has($key)) {
                return $overrideMap->get($key);
            }

            // No override: return base tagged with requested period
            $clone = $base->replicate();
            $clone->id = $base->id;
            $clone->assessment_period = $period;
            $clone->setRelation('grade', $base->grade);
            $clone->setRelation('level', $base->level);
            $clone->setRelation('language', $base->language);
            return $clone;
        });

        // Add any period records without a base match
        foreach ($overrideMap as $key => $override) {
            $hasBase = $baseRecords->contains(function ($base) use ($key) {
                return ($base->grade_id . '|' . $base->language_id) === $key;
            });
            if (!$hasBase) {
                $merged->push($override);
            }
        }

        return $merged->values();
    }

    /**
     * Update overall benchmark template.
     *
     * When assessment_period is null/omitted: updates base Overall records (assessment_period = NULL).
     * When assessment_period is BOY/MOY/EOY: creates/updates period-specific override records.
     * Auto-removes override records whose values match the base Overall record.
     */
    public function updateOverallTemplate(Request $request): array
    {
        $targetPeriod = $request->input('assessment_period');
        $languageId = $request->input('language_id');

        DB::transaction(function () use ($request, $targetPeriod, $languageId) {
            if ($request->has('grouping_parameters')) {
                $groupingParameters = $request->input('grouping_parameters', []);

                $uniqueGp = collect($groupingParameters)
                    ->unique(function ($param) {
                        return ($param['grade_id'] ?? '')
                            . ($param['level_id'] ?? '')
                            . ($param['language_id'] ?? '');
                    });

                foreach ($uniqueGp as $param) {
                    $period = $param['assessment_period'] ?? $targetPeriod ?? null;

                    $gp = GroupingParameter::firstOrNew([
                        'benchmark_template_id' => null,
                        'language_id'           => $param['language_id'] ?? null,
                        'grade_id'              => $param['grade_id'] ?? null,
                        'level_id'              => $param['level_id'] ?? null,
                        'assessment_period'     => $period,
                    ]);

                    if (!$gp->exists) {
                        $gp->id = (string) Str::uuid();
                    }

                    $gp->fill([
                        'name'        => $param['name'] ?? '',
                        'lower_bound' => $param['lower_bound'] ?? 0,
                        'upper_bound' => $param['upper_bound'] ?? 0,
                        'goal'        => $param['goal'] ?? 0,
                    ]);
                    $gp->save();
                }
            }

            if ($request->has('accuracies')) {
                $accuracies = $request->input('accuracies', []);

                // Unique accuracies - to avoid duplicate entries
                $uniqueAccuracies = collect($accuracies)
                    ->unique(function ($acc) {
                        return ($acc['grade_id'] ?? '')
                            . '|' . ($acc['language_id'] ?? '');
                    });

                foreach ($uniqueAccuracies as $param) {
                    $period = $param['assessment_period'] ?? $targetPeriod ?? null;

                    $acc = Accuracy::firstOrNew([
                        'benchmark_template_id' => null,
                        'language_id'           => $param['language_id'] ?? null,
                        'grade_id'              => $param['grade_id'] ?? null,
                        'assessment_period'     => $period,
                    ]);

                    // if (isset($param['level_id'])) {
                    //     $acc->level_id = $param['level_id'];
                    // }
                    $acc->level_id = null;

                    if (!$acc->exists) {
                        $acc->id = (string) Str::uuid();
                    }

                    $acc->fill([
                        'percentage' => isset($param['percentage']) ? (int) round($param['percentage']) : 0,
                    ]);
                    $acc->save();
                }
            }

            // Cleanup overall organisations' assessment_periods to ensure none are activated for overall template
            $allOrganisationIds = Organisation::pluck('id')->toArray();
            $assignedOrganisationIds = BenchmarkTemplate::all()
                ->pluck('organisation_ids')
                ->flatten()
                ->unique()
                ->toArray();
            $overallOrganisationIds = array_values(array_diff($allOrganisationIds, $assignedOrganisationIds));

            if (!empty($overallOrganisationIds)) {
                DB::table('organisation_organisation_languages')
                    ->whereIn('organisation_id', $overallOrganisationIds)
                    ->whereNotNull('assessment_period')
                    ->update(['assessment_period' => null]);
            }
        });

        return $this->getOverallTemplate($request);
    }

    /**
     * Remove a grouping parameter period override if its values are identical to the base Overall record.
     */
    private function cleanupGroupingParameterOverride(GroupingParameter $override): void
    {
        if (!$override->assessment_period) {
            return;
        }

        $base = GroupingParameter::where([
            'benchmark_template_id' => null,
            'language_id'           => $override->language_id,
            'grade_id'              => $override->grade_id,
            'level_id'              => $override->level_id,
            'assessment_period'     => null,
        ])->first();

        if (!$base) {
            return;
        }

        if (
            (string) $override->name === (string) $base->name &&
            (int) $override->lower_bound === (int) $base->lower_bound &&
            (int) $override->upper_bound === (int) $base->upper_bound &&
            (int) $override->goal === (int) $base->goal
        ) {
            $override->delete();
        }
    }

    /**
     * Remove an accuracy period override if its value is identical to the base Overall record.
     */
    private function cleanupAccuracyOverride(Accuracy $override): void
    {
        if (!$override->assessment_period) {
            return;
        }

        $base = Accuracy::where([
            'benchmark_template_id' => null,
            'language_id'           => $override->language_id,
            'grade_id'              => $override->grade_id,
            'assessment_period'     => null,
        ])->first();

        if (!$base) {
            return;
        }

        if ((int) $override->percentage === (int) $base->percentage) {
            $override->delete();
        }
    }

    /**
     * Merge template-specific GroupingParameters or Accuracies with system-wide defaults.
     *
     * Delegates to specific handlers based on the model type (Accuracy vs GroupingParameter)
     * to keep cognitive load low and maintainability high.
     */
    public function mergeWithOverallDefaults($templateValues, $overallValues, array $templateLanguageIds = [], ?string $gradeId = null, ?string $benchmarkTemplateId = null, ?string $selectedLanguageId = null, ?string $modelClass = null)
    {
        // 1. Remove archived/deleted templates or grade level 6 items from both sets
        $templates = $this->filterArchived($templateValues);
        $defaults  = $this->filterArchived($overallValues);

        // 2. Identify the type of models we are merging based on the first available item or passed class
        $sampleItem = $templates->first() ?? $defaults->first();
        $isAccuracy = $modelClass === Accuracy::class || ($sampleItem instanceof Accuracy);

        // 3. Delegate to the appropriate typed merger method to ensure clean separation of concerns
        if ($isAccuracy) {
            return $this->mergeAccuracies($templates, $defaults, $templateLanguageIds, $benchmarkTemplateId, $sampleItem, $selectedLanguageId, $gradeId);
        }

        return $this->mergeGroupingParameters($templates, $defaults, $templateLanguageIds, $gradeId, $benchmarkTemplateId, $sampleItem, $selectedLanguageId);
    }

    /**
     * Filters out archived records (e.g., items with "archived" in the name or belonging to grade level 6).
     */
    private function filterArchived($items)
    {
        return collect($items)->filter(function ($item) {
            // Exclude items with "archived" in their name
            if (isset($item->name) && stripos($item->name, 'archived') !== false) {
                return false;
            }
            // Exclude items under grade level 6 (typically placeholder or archived grade)
            if (isset($item->grade->level) && (int)$item->grade->level === 6) {
                return false;
            }
            return true;
        });
    }

    /**
     * Merge logic specifically for Accuracy models.
     */
    private function mergeAccuracies($templates, $defaults, array $templateLanguageIds, ?string $benchmarkTemplateId, $sampleItem, ?string $selectedLanguageId = null, ?string $gradeId = null)
    {
        // Pre-fetch all grades, levels, languages to avoid N+1 DB queries inside loops
        $allGrades = $gradeId
            ? Grade::where('id', $gradeId)->where('level', '!=', 6)->get()->keyBy('id')
            : Grade::where('level', '!=', 6)->orderBy('level', 'asc')->get()->keyBy('id');

        $allLevels = Level::orderBy('rank', 'asc')->get()->keyBy('id');
        $allLanguages = Language::all()->keyBy('id');

        // Determine target languages (template-configured, dynamically found, or all system languages)
        $targetLanguages = !empty($templateLanguageIds)
            ? $templateLanguageIds
            : (!empty($templates->pluck('language_id')->filter()->toArray())
                ? $templates->pluck('language_id')->filter()->unique()->toArray()
                : $allLanguages->keys()->toArray());

        if ($selectedLanguageId) {
            $targetLanguages = array_intersect($targetLanguages, [$selectedLanguageId]);
        }

        $selectedLevelId = request()->query('level') ?: request()->query('level_id') ?: null;
        $firstLevelId = $selectedLevelId;
        $targetPeriod = $sampleItem?->assessment_period ?? request()->query('assessment_period') ?? request()->query('period') ?? null;

        $result = collect();

        foreach ($targetLanguages as $langId) {
            foreach ($allGrades as $grade) {
                // Priority 1: Match and use template-specific accuracy for this language
                $activePeriodForLang = $templates
                    ->where('language_id', $langId)
                    ->whereNotNull('assessment_period')
                    ->pluck('assessment_period')
                    ->first();

                $acc = null;
                if ($activePeriodForLang) {
                    $acc = $templates->first(function ($item) use ($grade, $langId, $activePeriodForLang) {
                        return $item->language_id === $langId
                            && $item->grade_id === $grade->id
                            && !is_null($item->benchmark_template_id)
                            && $item->assessment_period === $activePeriodForLang;
                    });
                }

                if (!$acc) {
                    $acc = $templates->first(function ($item) use ($grade, $langId) {
                        return $item->language_id === $langId
                            && $item->grade_id === $grade->id
                            && !is_null($item->benchmark_template_id)
                            && !is_null($item->assessment_period);
                    });
                }

                if (!$acc) {
                    $acc = $templates->first(function ($item) use ($grade, $langId) {
                        return $item->language_id === $langId
                            && $item->grade_id === $grade->id;
                    });
                }
                if ($acc) {
                    $this->fillAccuracyAttributes($acc, $benchmarkTemplateId, $langId, $firstLevelId, $targetPeriod);
                    $result->push($acc);
                    continue;
                }

                $defaultAcc = null;
                if ($targetPeriod) {
                    $defaultAcc = $defaults->first(function ($item) use ($grade, $langId, $targetPeriod) {
                        return ($item->language_id === $langId || is_null($item->language_id))
                            && $item->grade_id === $grade->id
                            && $item->assessment_period === $targetPeriod;
                    });
                }
                if (!$defaultAcc) {
                    $defaultAcc = $defaults->first(function ($item) use ($grade, $langId) {
                        return ($item->language_id === $langId || is_null($item->language_id))
                            && $item->grade_id === $grade->id
                            && in_array($item->assessment_period, BenchmarkTemplate::PERIODS, true);
                    });
                }
                if (!$defaultAcc) {
                    $defaultAcc = $defaults->first(function ($item) use ($grade, $langId) {
                        return ($item->language_id === $langId || is_null($item->language_id))
                            && $item->grade_id === $grade->id;
                    });
                }
                if ($defaultAcc) {
                    $cloned = $defaultAcc->replicate();
                    $cloned->id = (string) Str::uuid();
                    $this->fillAccuracyAttributes($cloned, $benchmarkTemplateId, $langId, null, $targetPeriod);
                    $cloned->assessment_period = $defaultAcc->assessment_period;

                    // Set relations from pre-fetched collections to avoid N+1 DB lookups
                    $cloned->setRelation('grade', $defaultAcc->grade ?? $grade);
                    $cloned->setRelation('level', null);
                    $cloned->setRelation('language', $defaultAcc->language ?? $allLanguages->get($cloned->language_id));

                    $result->push($cloned);
                    continue;
                }

                // Priority 3: Fill any remaining gaps with a virtual zero-valued accuracy record
                $virtual = new Accuracy();
                $virtual->id = (string) Str::uuid();
                $virtual->grade_id = $grade->id;
                $virtual->percentage = 0;
                $this->fillAccuracyAttributes($virtual, $benchmarkTemplateId, $langId, null, $targetPeriod);
                $virtual->assessment_period = null;

                // Set relations from pre-fetched collections to avoid N+1 DB lookups
                $virtual->setRelation('grade', $grade);
                $virtual->setRelation('level', null);
                $virtual->setRelation('language', $allLanguages->get($langId));

                $result->push($virtual);
            }
        }

        return $this->sortMergedResults($result, $templates, $defaults, $targetLanguages);
    }

    /**
     * Fill common attributes for an Accuracy model if they are missing.
     */
    private function fillAccuracyAttributes($accuracy, ?string $benchmarkTemplateId, ?string $languageId, ?string $levelId, ?string $period)
    {
        if (empty($accuracy->benchmark_template_id)) {
            $accuracy->benchmark_template_id = $benchmarkTemplateId;
        }
        if (empty($accuracy->language_id)) {
            $accuracy->language_id = $languageId;
        }
        if ($levelId && empty($accuracy->level_id)) {
            $accuracy->level_id = $levelId;
        }
        if (empty($accuracy->assessment_period)) {
            $accuracy->assessment_period = $period;
        }
    }

    /**
     * Merge logic specifically for GroupingParameter models.
     */
    private function mergeGroupingParameters($templates, $defaults, array $templateLanguageIds, ?string $gradeId, ?string $benchmarkTemplateId, $sampleItem, ?string $selectedLanguageId = null)
    {
        // Pre-fetch all grades, levels, languages to avoid N+1 DB queries inside loops
        $allGrades = $gradeId
            ? Grade::where('id', $gradeId)->where('level', '!=', 6)->get()->keyBy('id')
            : Grade::where('level', '!=', 6)->orderBy('level', 'asc')->get()->keyBy('id');

        $allLevels = Level::orderBy('rank', 'asc')->get()->keyBy('id');
        $allLanguages = Language::all()->keyBy('id');

        // Determine target languages (template-configured, dynamically found, or all system languages)
        $targetLanguages = !empty($templateLanguageIds)
            ? $templateLanguageIds
            : (!empty($templates->pluck('language_id')->filter()->toArray())
                ? $templates->pluck('language_id')->filter()->unique()->toArray()
                : $allLanguages->keys()->toArray());

        if ($selectedLanguageId) {
            $targetLanguages = array_intersect($targetLanguages, [$selectedLanguageId]);
        }

        $targetPeriod = $sampleItem?->assessment_period ?? request()->query('assessment_period') ?? request()->query('period') ?? null;

        // Pre-build level ID to standard name map to avoid DB queries inside loops
        $levelIdToNameMap = $this->buildLevelIdToNameMap($allLevels, $templates, $defaults);

        $result = collect();

        foreach ($targetLanguages as $langId) {
            foreach ($allGrades as $grade) {
                foreach ($allLevels as $lvl) {
                    $levelId = $lvl->id;

                    // Priority 1: Match and use template-specific grouping parameter
                    $activePeriodForLang = $templates
                        ->where('language_id', $langId)
                        ->whereNotNull('assessment_period')
                        ->pluck('assessment_period')
                        ->first();

                    $gp = null;
                    if ($activePeriodForLang) {
                        $gp = $templates->first(function ($item) use ($langId, $grade, $levelId, $activePeriodForLang) {
                            return $item->language_id === $langId
                                && $item->grade_id === $grade->id
                                && $item->level_id === $levelId
                                && !is_null($item->benchmark_template_id)
                                && $item->assessment_period === $activePeriodForLang;
                        });
                    }

                    if (!$gp) {
                        $gp = $templates->first(function ($item) use ($langId, $grade, $levelId) {
                            return $item->language_id === $langId
                                && $item->grade_id === $grade->id
                                && $item->level_id === $levelId
                                && !is_null($item->benchmark_template_id)
                                && !is_null($item->assessment_period);
                        });
                    }

                    if (!$gp) {
                        $gp = $templates->first(function ($item) use ($langId, $grade, $levelId) {
                            return $item->language_id === $langId
                                && $item->grade_id === $grade->id
                                && $item->level_id === $levelId;
                        });
                    }

                    if ($gp) {
                        if (empty($gp->benchmark_template_id)) {
                            $gp->benchmark_template_id = $benchmarkTemplateId;
                        }
                        if (empty($gp->assessment_period)) {
                            $gp->assessment_period = $targetPeriod;
                        }
                        $gp->name = $levelIdToNameMap[$levelId] ?? $gp->name;

                        $result->push($gp);
                        continue;
                    }

                    $overallGp = null;
                    if ($targetPeriod) {
                        $overallGp = $defaults->first(function ($item) use ($langId, $grade, $levelId, $targetPeriod) {
                            return ($item->language_id === $langId || is_null($item->language_id))
                                && $item->grade_id === $grade->id
                                && ($item->level_id === $levelId || is_null($item->level_id))
                                && $item->assessment_period === $targetPeriod;
                        });
                    }
                    if (!$overallGp) {
                        $overallGp = $defaults->first(function ($item) use ($langId, $grade, $levelId) {
                            return ($item->language_id === $langId || is_null($item->language_id))
                                && $item->grade_id === $grade->id
                                && ($item->level_id === $levelId || is_null($item->level_id))
                                && in_array($item->assessment_period, BenchmarkTemplate::PERIODS, true);
                        });
                    }
                    if (!$overallGp) {
                        $overallGp = $defaults->first(function ($item) use ($langId, $grade, $levelId) {
                            return ($item->language_id === $langId || is_null($item->language_id))
                                && $item->grade_id === $grade->id
                                && ($item->level_id === $levelId || is_null($item->level_id));
                        });
                    }

                    if ($overallGp) {
                        $cloned = $overallGp->replicate();
                        $cloned->id = (string) Str::uuid();
                        $cloned->benchmark_template_id = $benchmarkTemplateId;
                        $cloned->language_id = $langId;
                        $cloned->level_id = $levelId;
                        $cloned->name = $levelIdToNameMap[$levelId] ?? $overallGp->name;
                        $cloned->assessment_period = $overallGp->assessment_period;

                        // Set relations from pre-fetched collections to avoid N+1 DB lookups
                        $cloned->setRelation('grade', $overallGp->grade ?? $grade);
                        $cloned->setRelation('level', $overallGp->level ?? $lvl);
                        $cloned->setRelation('language', $overallGp->language ?? $allLanguages->get($langId));

                        $result->push($cloned);
                        continue;
                    }

                    // Priority 3: Create virtual zero-valued grouping parameter record to avoid gaps
                    $virtual = new GroupingParameter();
                    $virtual->id = (string) Str::uuid();
                    $virtual->benchmark_template_id = $benchmarkTemplateId;
                    $virtual->language_id = $langId;
                    $virtual->grade_id = $grade->id;
                    $virtual->level_id = $levelId;
                    $virtual->name = $levelIdToNameMap[$levelId] ?? 'BM';
                    $virtual->lower_bound = 0;
                    $virtual->upper_bound = 0;
                    $virtual->goal = 0;
                    $virtual->assessment_period = null;

                    // Carry over relations from pre-fetched collections
                    $virtual->setRelation('grade', $grade);
                    $virtual->setRelation('level', $lvl);
                    $virtual->setRelation('language', $allLanguages->get($langId));

                    $result->push($virtual);
                }
            }
        }

        return $this->sortMergedResults($result, $templates, $defaults, $targetLanguages);
    }

    /**
     * Builds mapping of Level ID to Name, dynamically learning from existing data.
     */
    private function buildLevelIdToNameMap($levels, $templates, $defaults)
    {
        $levelIdToNameMap = [];
        $standardNames = ['BM', 'FI', 'II', 'HPI'];
        foreach ($levels as $idx => $lvl) {
            $levelIdToNameMap[$lvl->id] = $standardNames[$idx] ?? 'BM';
        }

        // Dynamically learn level name mapping from existing templates if possible
        foreach ($templates as $item) {
            if (!empty($item->level_id) && !empty($item->name)) {
                $levelIdToNameMap[$item->level_id] = $item->name;
            }
        }
        foreach ($defaults as $item) {
            if (!empty($item->level_id) && !empty($item->name)) {
                $levelIdToNameMap[$item->level_id] = $item->name;
            }
        }

        return $levelIdToNameMap;
    }

    /**
     * Sorts and returns the merged results collection, with a fallback if empty.
     */
    private function sortMergedResults($result, $templates, $defaults, array $targetLanguages)
    {
        // Return empty collection fallback if both are empty
        if ($result->isEmpty() && $templates->isEmpty()) {
            return $defaults
                ->filter(fn($item) => empty($targetLanguages) || in_array($item->language_id, $targetLanguages, true))
                ->values();
        }

        // Sort priority: language → assessment period → grade school-year level → level rank.
        return $result->sort(function ($a, $b) {
            $cmp = strcmp($a->language_id ?? '', $b->language_id ?? '');
            if ($cmp !== 0) return $cmp;

            $cmp = strcmp($a->assessment_period ?? '', $b->assessment_period ?? '');
            if ($cmp !== 0) return $cmp;

            $aGradeLevel = (int) ($a->grade->level ?? 0);
            $bGradeLevel = (int) ($b->grade->level ?? 0);
            if ($aGradeLevel !== $bGradeLevel) return $aGradeLevel <=> $bGradeLevel;

            return (int) ($a->level->rank ?? 0) <=> (int) ($b->level->rank ?? 0);
        })->values();
    }

    /**
     * Get grouping parameters for a template.
     */
    public function templateGroupingParameters(BenchmarkTemplate $benchmarkTemplate, Request $request, ?string $period = null, ?string $selectedLanguageId = null)
    {
        $filters = $this->extractRequestFilters($request, $period);
        $lang = $selectedLanguageId ?: $filters['selectedLanguageId'];

        return $benchmarkTemplate->groupingParameters()
            ->when($lang, function ($query, $l) {
                $query->where('language_id', $l);
            })
            ->when($filters['selectedGradeId'], function ($query, $grade) {
                $query->where('grade_id', $grade);
            })
            ->when($filters['targetPeriod'], function ($query, $p) {
                $query->where(function ($q) use ($p) {
                    $q->where('assessment_period', $p)
                        ->orWhereNull('assessment_period');
                });
            })
            ->leftJoin('common_level', 'common_groupingparameter.level_id', '=', 'common_level.id')
            ->select('common_groupingparameter.*')
            ->with(['grade', 'level', 'language'])
            ->orderBy('language_id')
            ->orderBy('assessment_period')
            ->orderBy('common_level.rank', 'asc')
            ->get();
    }

    /**
     * Fetch accuracy records for a specific template, optionally filtered by period and language.
     */
    public function templateAccuracies(BenchmarkTemplate $benchmarkTemplate, Request $request, ?string $period = null, ?string $selectedLanguageId = null)
    {
        $filters = $this->extractRequestFilters($request, $period);
        $lang = $selectedLanguageId ?: $filters['selectedLanguageId'];

        return $benchmarkTemplate->accuracies()
            ->when($lang, function ($query, $l) {
                $query->where('language_id', $l);
            })
            ->when($filters['selectedGradeId'], function ($query, $grade) {
                $query->where('grade_id', $grade);
            })
            ->when($filters['targetPeriod'], function ($query, $p) {
                $query->where(function ($q) use ($p) {
                    $q->where('assessment_period', $p)
                        ->orWhereNull('assessment_period');
                });
            })
            ->leftJoin('common_grade', 'common_accuracy.grade_id', '=', 'common_grade.id')
            ->select('common_accuracy.*')
            ->with(['grade', 'level', 'language'])
            ->orderBy('language_id')
            ->orderBy('assessment_period')
            ->orderBy('common_grade.level', 'asc')
            ->get();
    }

    /**
     * Get overall grouping parameters.
     */
    public function overallGroupingParameters(Request $request, ?string $period = null, ?string $selectedLanguageId = null)
    {
        $filters = $this->extractRequestFilters($request, $period);
        $lang = $selectedLanguageId ?: $filters['selectedLanguageId'];

        return GroupingParameter::whereNull('benchmark_template_id')
            ->where('common_groupingparameter.name', '!=', 'Archived')
            ->when($filters['targetPeriod'], function ($query, $p) {
                $query->where(function ($q) use ($p) {
                    $q->where('assessment_period', $p)
                        ->orWhereNull('assessment_period');
                });
            })
            ->when($filters['selectedGradeId'], function ($query, $grade) {
                $query->where('grade_id', $grade);
            })
            ->when($lang, function ($query, $l) {
                $query->where(function ($q) use ($l) {
                    $q->where('language_id', $l)
                        ->orWhereNull('language_id');
                });
            })
            ->leftJoin('common_level', 'common_groupingparameter.level_id', '=', 'common_level.id')
            ->select('common_groupingparameter.*')
            ->with(['level', 'grade', 'language'])
            ->orderBy('language_id')
            ->orderBy('common_level.rank', 'asc')
            ->get();
    }

    /**
     * Sync organisation periods for a template.
     */
    public function syncOrganisationPeriods(BenchmarkTemplate $template, array $organisationPeriods, ?string $languageId = null)
    {
        $templateOrganisations = $template->organisation_ids ?? [];
        $templateLanguages = $template->language_ids ?? [];

        // Use provided languageId or fallback to all template languages
        $languagesToUpdate = $languageId ? [$languageId] : $templateLanguages;

        $this->syncOrganisationPeriodsForIds($templateOrganisations, $languagesToUpdate, $organisationPeriods);
    }

    /**
     * Sync assessment periods for a specific set of organisations and languages based on the relation format.
     */
    public function syncOrganisationPeriodsForIds(array $organisationIds, array $languageIds, array $organisationPeriods)
    {
        foreach (BenchmarkTemplate::PERIODS as $period) {
            // Only process if the period is present in the input array
            if (!array_key_exists($period, $organisationPeriods)) {
                continue;
            }

            $selectedPairs = $organisationPeriods[$period];

            // 1. Reset assessment_period to null for all organisations in this template
            // who are currently in the target period.
            DB::table('organisation_organisation_languages')
                ->whereIn('organisation_id', $organisationIds)
                ->whereIn('language_id', $languageIds)
                ->where('assessment_period', $period)
                ->update(['assessment_period' => null]);

            // 2. Set the requested organisations and languages to the requested period (ensure rows exist)
            if (is_array($selectedPairs) && !empty($selectedPairs)) {
                foreach ($selectedPairs as $pair) {
                    $orgId = $pair['organisation_id'] ?? null;
                    $langId = $pair['language_id'] ?? null;

                    if ($orgId && $langId && in_array($orgId, $organisationIds) && in_array($langId, $languageIds)) {
                        DB::table('organisation_organisation_languages')
                            ->updateOrInsert(
                                ['organisation_id' => $orgId, 'language_id' => $langId],
                                ['assessment_period' => $period]
                            );
                    }
                }
            }
        }
    }

    /**
     * Get active organisations grouped by period for a template.
     *
     * @param BenchmarkTemplate $template The benchmark template instance.
     * @return array Associative array of active organisations grouped by assessment period.
     */
    public function getActiveOrganisationsByPeriod(BenchmarkTemplate $template, ?string $selectedLanguageId = null): array
    {
        $orgIds = $template->organisation_ids ?? [];
        $langIds = $template->language_ids ?? [];

        if ($selectedLanguageId) {
            $langIds = array_values(array_intersect($langIds, [$selectedLanguageId]));
        }

        if (empty($orgIds) || empty($langIds)) {
            return [];
        }

        $activeByPeriod = [];
        foreach (BenchmarkTemplate::PERIODS as $period) {
            $activeByPeriod[$period] = DB::table('organisation_organisation_languages')
                ->whereIn('organisation_id', $orgIds)
                ->whereIn('language_id', $langIds)
                ->where('assessment_period', $period)
                ->select('organisation_id', 'language_id')
                ->get()
                ->map(fn($row) => [
                    'organisation_id' => $row->organisation_id,
                    'language_id'     => $row->language_id,
                ])
                ->unique(fn($item) => $item['organisation_id'] . '-' . $item['language_id'])
                ->values()
                ->toArray();
        }

        return $activeByPeriod;
    }

    /**
     * Get overall accuracies.
     */
    public function overallAccuracies(Request $request, ?string $period = null, ?string $selectedLanguageId = null)
    {
        $filters = $this->extractRequestFilters($request, $period);
        $lang = $selectedLanguageId ?: $filters['selectedLanguageId'];

        return Accuracy::whereNull('benchmark_template_id')
            ->when($filters['targetPeriod'], function ($query, $p) {
                $query->where(function ($q) use ($p) {
                    $q->where('assessment_period', $p)
                        ->orWhereNull('assessment_period');
                });
            })
            ->when($filters['selectedGradeId'], function ($query, $grade) {
                $query->where('grade_id', $grade);
            })
            ->when($lang, function ($query, $l) {
                $query->where(function ($q) use ($l) {
                    $q->where('language_id', $l)
                        ->orWhereNull('language_id');
                });
            })
            ->leftJoin('common_grade', 'common_accuracy.grade_id', '=', 'common_grade.id')
            ->select('common_accuracy.*')
            ->with(['grade', 'level', 'language'])
            ->orderBy('language_id')
            ->orderBy('common_grade.level', 'asc')
            ->get();
    }

    /**
     * Validate that template values only use selected languages.
     */
    public function validateTemplateValueLanguages(array $values, array $languageIds): ?string
    {
        foreach ($values as $value) {
            $languageId = $value['language_id'] ?? null;
            if ($languageId && !in_array($languageId, $languageIds, true)) {
                return "Language {$languageId} is not selected for this template.";
            }
        }

        return null;
    }

    /**
     * Ensure every grouping parameter has an assessment_period, falling back to the legacy period.
     */
    public function normalizeGroupingParametersPayload(array $gps, ?string $legacyPeriod = null): array
    {
        return collect($gps)->map(fn($p) => array_merge($p, ['assessment_period' => $p['assessment_period'] ?? $legacyPeriod]))->all();
    }

    /**
     * Ensure every accuracy has an assessment_period, falling back to the legacy period.
     */
    public function normalizeAccuraciesPayload(array $accuracies, ?string $legacyPeriod = null): array
    {
        return collect($accuracies)->map(fn($a) => array_merge($a, [
            'assessment_period' => $a['assessment_period'] ?? $legacyPeriod,
            'percentage' => isset($a['percentage']) ? (int) round($a['percentage']) : 0,
        ]))->all();
    }

    /**
     * Persist an array of grouping parameter data rows associated with a template.
     */
    public function saveGroupingParameters(BenchmarkTemplate $benchmarkTemplate, array $groupingParameters, ?array $targetLanguageIds = null): void
    {
        if (empty($groupingParameters)) {
            return;
        }

        $existingGps = $benchmarkTemplate->groupingParameters()->get();
        $upsertData = [];
        $now = now();

        foreach ($groupingParameters as $param) {
            $langId = $param['language_id'] ?? null;
            $gradeId = $param['grade_id'] ?? null;
            $levelId = $param['level_id'] ?? null;
            $period = $param['assessment_period'] ?? null;

            $existing = $existingGps->first(function ($gp) use ($langId, $gradeId, $levelId, $period) {
                return $gp->language_id == $langId &&
                    $gp->grade_id == $gradeId &&
                    $gp->level_id == $levelId &&
                    ($gp->assessment_period == $period || is_null($gp->assessment_period) || is_null($period));
            });

            $id = $existing ? $existing->id : (string) Str::uuid();

            $upsertData[] = [
                'id'                    => $id,
                'benchmark_template_id' => $benchmarkTemplate->id,
                'language_id'           => $langId,
                'grade_id'              => $gradeId,
                'level_id'              => $levelId,
                'assessment_period'     => $period,
                'name'                  => $param['name'] ?? ($existing->name ?? ''),
                'lower_bound'           => $param['lower_bound'] ?? ($existing->lower_bound ?? 0),
                'upper_bound'           => $param['upper_bound'] ?? ($existing->upper_bound ?? 0),
                'goal'                  => $param['goal'] ?? ($existing->goal ?? 0),
            ];
        }

        // Chunking the upsert is good practice if payloads are large
        foreach (array_chunk($upsertData, 500) as $chunk) {
            GroupingParameter::upsert($chunk, ['id'], [
                'name',
                'lower_bound',
                'upper_bound',
                'goal',
                'assessment_period'
            ]);
        }
    }

    /**
     * Persist an array of accuracy data rows associated with a template, deduplicating by grade/level/language/period.
     */
    public function saveAccuracies(BenchmarkTemplate $benchmarkTemplate, array $accuracies, ?array $targetLanguageIds = null): void
    {
        if (empty($accuracies)) {
            return;
        }

        $existingAccs = $benchmarkTemplate->accuracies()->get();
        $upsertData = [];
        $now = now();

        // Unique accuracies to prevent duplicate key matches in memory
        $uniqueAccuracies = collect($accuracies)
            ->unique(function ($acc) {
                return ($acc['grade_id'] ?? '')
                    . '|' . ($acc['language_id'] ?? '')
                    . '|' . ($acc['assessment_period'] ?? '');
            });

        foreach ($uniqueAccuracies as $param) {
            $langId = $param['language_id'] ?? null;
            $gradeId = $param['grade_id'] ?? null;
            $levelId = $param['level_id'] ?? null;
            $period = $param['assessment_period'] ?? null;

            $existing = $existingAccs->first(function ($acc) use ($langId, $gradeId, $period) {
                return $acc->language_id == $langId &&
                    $acc->grade_id == $gradeId &&
                    ($acc->assessment_period == $period || is_null($acc->assessment_period) || is_null($period));
            });

            $id = $existing ? $existing->id : (string) Str::uuid();

            $upsertData[] = [
                'id'                    => $id,
                'benchmark_template_id' => $benchmarkTemplate->id,
                'language_id'           => $langId,
                'grade_id'              => $gradeId,
                'level_id'              => null,
                'assessment_period'     => $period,
                'percentage'            => isset($param['percentage']) ? (int) round($param['percentage']) : ($existing->percentage ?? 0),
            ];
        }

        foreach (array_chunk($upsertData, 500) as $chunk) {
            Accuracy::upsert($chunk, ['id'], [
                'percentage',
                'assessment_period'
            ]);
        }
    }

    public function syncTemplateLanguages(BenchmarkTemplate $benchmarkTemplate, array $prevIds, array $currentIds, string $valueType): void
    {
        $removed = array_diff($prevIds, $currentIds);
        if (!empty($removed)) {
            $relation = $valueType === 'grouping_parameters' ? 'groupingParameters' : 'accuracies';
            $benchmarkTemplate->$relation()->whereIn('language_id', $removed)->delete();
        }
    }

    /**
     * Ensure that an organisation-language pair is unique across all templates - excluding selected template values.
     * @param array $organisationIds
     * @param array $languageIds
     * @param string|null $excludeTemplateId
     * @return array|null
     */
    public function validateOrganisationLanguageUniqueness(
        array $organisationIds,
        array $languageIds,
        ?string $excludeTemplateId = null
    ): ?array {
        if (empty($organisationIds) || empty($languageIds)) {
            return null;
        }

        // Retrieve other benchmark templates to check for overlapping organization and language assignments
        $templates = BenchmarkTemplate::query()
            ->when($excludeTemplateId, function ($query) use ($excludeTemplateId) {
                $query->where('id', '!=', $excludeTemplateId);
            })
            ->get();

        foreach ($templates as $template) {
            $existingOrgs = $template->organisation_ids ?? [];
            $existingLangs = $template->language_ids ?? [];

            $intersectOrgs = array_intersect($organisationIds, $existingOrgs);
            $intersectLangs = array_intersect($languageIds, $existingLangs);

            if (!empty($intersectOrgs) && !empty($intersectLangs)) {
                return [
                    'template_id'      => $template->id,
                    'organisation_id'  => array_values($intersectOrgs)[0],
                    'language_id'      => array_values($intersectLangs)[0],
                ];
            }
        }

        return null;
    }

    public function sanitizeUuid($id)
    {
        return Str::isUuid($id) ? $id : null;
    }
    public function formatDateTime($date)
    {
        return $date ? ($date instanceof Carbon ? $date->toIso8601String() : Carbon::parse($date)->toIso8601String()) : null;
    }

    private function extractRequestFilters(Request $request, ?string $period = null): array
    {
        return [
            'targetPeriod'       => $period ?: ($request->query('assessment_period') ?: ($request->query('assessement_period') ?: $request->query('period'))),
            'selectedLanguageId' => $request->query('language_id') ?: $request->query('language'),
            'selectedGradeId'    => $request->query('grade_id') ?: $request->query('grade'),
        ];
    }
}
