<?php

namespace App\Http\Controllers;

use App\Models\BenchmarkTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\BenchmarkTemplateService;
use App\Http\Requests\StoreBenchmarkTemplateRequest;
use App\Http\Requests\UpdateBenchmarkTemplateRequest;
use App\Http\Requests\UpdateOverallBenchmarkTemplateRequest;
use App\Http\Requests\ActivateBenchmarkTemplatePeriodRequest;

class BenchmarkTemplateController extends Controller
{
    protected BenchmarkTemplateService $benchmarkTemplateService;

    public function __construct(BenchmarkTemplateService $benchmarkTemplateService)
    {
        $this->benchmarkTemplateService = $benchmarkTemplateService;
    }

    /**
     * List benchmark templates.
     */
    public function index(Request $request)
    {
        return response()->json($this->benchmarkTemplateService->getBenchmarkTemplateList($request));
    }

    /**
     * Get a single benchmark template.
     */
    public function show(string $id, Request $request)
    {
        $template = $this->benchmarkTemplateService->getTemplateById($id, $request);

        if ($template === false) {
            return response()->json(['error' => 'Benchmark template not found.'], 404);
        }

        if ($template === null) {
            return response()->json(['error' => 'Invalid ID format.'], 400);
        }

        return response()->json($template);
    }

    /**
     * Create a benchmark template with its grouping parameters.
     */
    public function store(StoreBenchmarkTemplateRequest $request)
    {
        $organisationIds = array_values(array_unique($request->input('organisation_ids', [])));
        $languageIds = array_values(array_unique($request->input('language_ids', [])));
        $legacyPeriod = $request->input('assessment_period', 'BOY');

        $conflict = $this->benchmarkTemplateService->validateOrganisationLanguageUniqueness($organisationIds, $languageIds);
        if ($conflict) {
            return response()->json([
                'error' => "Organisation {$conflict['organisation_id']} already belongs to another template for language {$conflict['language_id']}.",
            ], 422);
        }

        $groupingParameters = $request->has('grouping_parameters')
            ? $this->benchmarkTemplateService->normalizeGroupingParametersPayload($request->input('grouping_parameters', []), $legacyPeriod)
            : null;

        $accuracies = $request->has('accuracies')
            ? $this->benchmarkTemplateService->normalizeAccuraciesPayload($request->input('accuracies', []), $legacyPeriod)
            : null;

        if (!is_null($groupingParameters)) {
            $groupingLanguageError = $this->benchmarkTemplateService->validateTemplateValueLanguages($groupingParameters, $languageIds);
            if ($groupingLanguageError) {
                return response()->json(['error' => $groupingLanguageError], 422);
            }
        }

        if (!is_null($accuracies)) {
            $accuracyLanguageError = $this->benchmarkTemplateService->validateTemplateValueLanguages($accuracies, $languageIds);
            if ($accuracyLanguageError) {
                return response()->json(['error' => $accuracyLanguageError], 422);
            }
        }

        return DB::transaction(function () use (
            $request,
            $organisationIds,
            $languageIds,
            $legacyPeriod,
            $groupingParameters,
            $accuracies
        ) {
            $benchmarkTemplate = BenchmarkTemplate::create([
                'name'                      => $request->name,
                'organisation_ids'          => $organisationIds,
                'language_ids'              => $languageIds,
                'assessment_period'         => $legacyPeriod,
            ]);

            if (!is_null($groupingParameters)) {
                $this->benchmarkTemplateService->saveGroupingParameters($benchmarkTemplate, $groupingParameters);
            }
            if (!is_null($accuracies)) {
                $this->benchmarkTemplateService->saveAccuracies($benchmarkTemplate, $accuracies);
            }

            // Sync organisation periods if provided
            if ($request->has('active_organisations_by_period')) {
                $this->benchmarkTemplateService->syncOrganisationPeriods($benchmarkTemplate, $request->input('active_organisations_by_period'));
            }

            return response()->json($this->benchmarkTemplateService->formatBenchmarkTemplate($benchmarkTemplate->fresh(), $request, null, true), 201);
        });
    }

    /**
     * Update a benchmark template and its parameters.
     */
    public function update(UpdateBenchmarkTemplateRequest $request, string $id)
    {
        if ($id === 'overall') {
            // If the ID is 'overall', instantiate and validate the specific FormRequest
            // for the overall template, then delegate to the updateOverall method.
            $overallRequest = UpdateOverallBenchmarkTemplateRequest::createFrom($request);
            $overallRequest->setContainer(app())->validateResolved();
            return $this->updateOverall($overallRequest);
        }

        $id = $this->benchmarkTemplateService->sanitizeUuid($id);
        if (!$id) {
            return response()->json(['error' => 'Invalid ID format.'], 400);
        }

        $benchmarkTemplate = BenchmarkTemplate::find($id);
        if (!$benchmarkTemplate) {
            return response()->json(['error' => 'Benchmark template not found.'], 404);
        }

        $organisationIds = array_values(array_unique($request->input('organisation_ids', $benchmarkTemplate->organisation_ids ?? [])));
        $languageIds = array_values(array_unique($request->input('language_ids', $benchmarkTemplate->language_ids ?? [])));
        $legacyPeriod = $request->input('assessment_period', $benchmarkTemplate->assessment_period);

        $conflict = $this->benchmarkTemplateService->validateOrganisationLanguageUniqueness($organisationIds, $languageIds, $benchmarkTemplate->id);
        if ($conflict) {
            return response()->json([
                'error' => "Organisation {$conflict['organisation_id']} already belongs to another template for language {$conflict['language_id']}.",
            ], 422);
        }

        if ($request->has('grouping_parameters')) {
            $groupingParameters = $this->benchmarkTemplateService->normalizeGroupingParametersPayload($request->input('grouping_parameters', []), $legacyPeriod);
            $groupingLanguageError = $this->benchmarkTemplateService->validateTemplateValueLanguages($groupingParameters, $languageIds);
            if ($groupingLanguageError) {
                return response()->json(['error' => $groupingLanguageError], 422);
            }
        } else {
            $groupingParameters = null;
        }

        if ($request->has('accuracies')) {
            $accuracies = $this->benchmarkTemplateService->normalizeAccuraciesPayload($request->input('accuracies', []), $legacyPeriod);
            $accuracyLanguageError = $this->benchmarkTemplateService->validateTemplateValueLanguages($accuracies, $languageIds);
            if ($accuracyLanguageError) {
                return response()->json(['error' => $accuracyLanguageError], 422);
            }
        } else {
            $accuracies = null;
        }

        $previousOrganisationIds = $benchmarkTemplate->organisation_ids ?? [];
        $previousLanguageIds = $benchmarkTemplate->language_ids ?? [];

        DB::transaction(function () use (
            $request,
            $benchmarkTemplate,
            $organisationIds,
            $languageIds,
            $legacyPeriod,
            $groupingParameters,
            $accuracies,
            $previousOrganisationIds,
            $previousLanguageIds
        ) {
            $benchmarkTemplate->update([
                'name'                      => $request->input('name', $benchmarkTemplate->name),
                'organisation_ids'          => $organisationIds,
                'language_ids'              => $languageIds,
                'assessment_period'         => $legacyPeriod,
            ]);

            // Updating grouping parameters
            if (!is_null($groupingParameters)) {
                // Removed destructive deletes to allow granular upserts.

                $this->benchmarkTemplateService->saveGroupingParameters($benchmarkTemplate, $groupingParameters);
            } else {
                $this->benchmarkTemplateService->syncTemplateLanguages($benchmarkTemplate, $previousLanguageIds, $languageIds, 'grouping_parameters');
            }

            // Updating accuracies
            if (!is_null($accuracies)) {
                // Removed destructive deletes to allow granular upserts.

                $this->benchmarkTemplateService->saveAccuracies($benchmarkTemplate, $accuracies);
            } else {
                $this->benchmarkTemplateService->syncTemplateLanguages($benchmarkTemplate, $previousLanguageIds, $languageIds, 'accuracies');
            }

            // Delete custom benchmarks/accuracies if no organisations are left in the template
            if (empty($organisationIds)) {
                $benchmarkTemplate->groupingParameters()->delete();
                $benchmarkTemplate->accuracies()->delete();
            }

            // Reset assessment_period to null for removed organisations
            $removedOrganisationIds = array_diff($previousOrganisationIds, $organisationIds);
            if (!empty($removedOrganisationIds) && !empty($previousLanguageIds)) {
                DB::table('organisation_organisation_languages')
                    ->whereIn('organisation_id', $removedOrganisationIds)
                    ->whereIn('language_id', $previousLanguageIds)
                    ->update(['assessment_period' => null]);
            }

            // Sync organisation periods if provided
            if ($request->has('active_organisations_by_period')) {
                $this->benchmarkTemplateService->syncOrganisationPeriods($benchmarkTemplate, $request->input('active_organisations_by_period'));
            }
        });

        return response()->json($this->benchmarkTemplateService->formatBenchmarkTemplate($benchmarkTemplate->fresh(), $request, null, true));
    }

    /**
     * Activate a specific assessment period for a language and optionally update schools.
     */
    public function activate(ActivateBenchmarkTemplatePeriodRequest $request, string $id)
    {
        if ($id === 'overall') {
            return response()->json(['error' => 'Overall template cannot be modified via this API.'], 400);
        }

        $id = $this->benchmarkTemplateService->sanitizeUuid($id);
        if (!$id) {
            return response()->json(['error' => 'Invalid ID format.'], 400);
        }

        $benchmarkTemplate = BenchmarkTemplate::find($id);
        if (!$benchmarkTemplate) {
            return response()->json(['error' => 'Benchmark template not found.'], 404);
        }

        $period = $request->input('assessment_period');
        $providedOrgs = $request->input('organisation_ids') ?? [];
        $levelId = $request->input('level_id');
        $gradeId = $request->input('grade_id');
        $languageId = $request->input('language_id');

        if (!$languageId) {
            return response()->json(['error' => 'Language ID is required.'], 422);
        }

        // Merge global ids so the template remains aware of them
        $existingOrgs = $benchmarkTemplate->organisation_ids ?? [];
        $organisationIds = array_values(array_unique(array_merge($existingOrgs, $providedOrgs)));

        $existingLanguages = $benchmarkTemplate->language_ids ?? [];
        $languageIds = array_values(array_unique(array_merge($existingLanguages, [$languageId])));

        $conflict = $this->benchmarkTemplateService->validateOrganisationLanguageUniqueness($organisationIds, $languageIds, $benchmarkTemplate->id);
        if ($conflict) {
            return response()->json([
                'error' => "Organisation {$conflict['organisation_id']} already belongs to another template for language {$conflict['language_id']}.",
            ], 422);
        }

        DB::transaction(function () use ($benchmarkTemplate, $period, $organisationIds, $languageIds, $levelId, $gradeId, $languageId, $providedOrgs) {
            $benchmarkTemplate->update([
                'organisation_ids'  => $organisationIds,
                'language_ids'      => $languageIds,
            ]);

            // 1. Manage organisation_organisation_languages table
            if ($languageId) {
                // If there were previously organisations with this language and period, but they are not in the providedOrgs list, set their period to null
                if ($period) {
                    DB::table('organisation_organisation_languages')
                        ->whereNotIn('organisation_id', $providedOrgs)
                        ->where('language_id', $languageId)
                        ->where('assessment_period', $period)
                        ->update(['assessment_period' => null]);
                }

                // Activate the selected organisations for the selected assessment_period
                if (!empty($providedOrgs)) {
                    foreach ($providedOrgs as $orgId) {
                        DB::table('organisation_organisation_languages')
                            ->updateOrInsert(
                                ['organisation_id' => $orgId, 'language_id' => $languageId],
                                ['assessment_period' => $period]
                            );
                    }
                }
            }

            // 2. Targeted update of specific parameters (level-bound)
            if ($levelId && $gradeId && $languageId) {
                if (is_null($period)) {
                    $benchmarkTemplate->groupingParameters()
                        ->where('level_id', $levelId)
                        ->where('grade_id', $gradeId)
                        ->where('language_id', $languageId)
                        ->update(['assessment_period' => null]);
                } else {
                    // Deduplicate existing grouping parameters: keep only one
                    $gpRecords = $benchmarkTemplate->groupingParameters()
                        ->where('level_id', $levelId)
                        ->where('grade_id', $gradeId)
                        ->where('language_id', $languageId)
                        ->get();

                    if ($gpRecords->count() > 1) {
                        $keepId = $gpRecords->first()->id;
                        $benchmarkTemplate->groupingParameters()
                            ->where('level_id', $levelId)
                            ->where('grade_id', $gradeId)
                            ->where('language_id', $languageId)
                            ->where('id', '!=', $keepId)
                            ->delete();
                    }

                    $benchmarkTemplate->groupingParameters()
                        ->where('level_id', $levelId)
                        ->where('grade_id', $gradeId)
                        ->where('language_id', $languageId)
                        ->update(['assessment_period' => $period]);
                }
            }

            // 3. Targeted update of accuracies (not level-bound)
            if ($gradeId && $languageId) {
                if (is_null($period)) {
                    $benchmarkTemplate->accuracies()
                        ->where('grade_id', $gradeId)
                        ->where('language_id', $languageId)
                        ->update(['assessment_period' => null]);
                } else {
                    // Deduplicate existing accuracies: keep only one record for this combination
                    $accRecords = $benchmarkTemplate->accuracies()
                        ->where('grade_id', $gradeId)
                        ->where('language_id', $languageId)
                        ->get();

                    if ($accRecords->count() > 1) {
                        $keepId = $accRecords->first()->id;
                        $benchmarkTemplate->accuracies()
                            ->where('grade_id', $gradeId)
                            ->where('language_id', $languageId)
                            ->where('id', '!=', $keepId)
                            ->delete();
                    }

                    $benchmarkTemplate->accuracies()
                        ->where('grade_id', $gradeId)
                        ->where('language_id', $languageId)
                        ->update(['assessment_period' => $period]);
                }
            }
        });

        return response()->json($this->benchmarkTemplateService->formatBenchmarkTemplate($benchmarkTemplate->fresh(), $request));
    }

    /**
     * Update overall benchmark template.
     */
    private function updateOverall(UpdateOverallBenchmarkTemplateRequest $request)
    {
        return response()->json($this->benchmarkTemplateService->updateOverallTemplate($request));
    }

    /**
     * Delete a benchmark template.
     */
    public function destroy(string $id)
    {
        $id = $this->benchmarkTemplateService->sanitizeUuid($id);
        if (!$id) {
            return response()->json(['error' => 'Invalid ID format.'], 400);
        }

        $benchmarkTemplate = BenchmarkTemplate::find($id);
        if (!$benchmarkTemplate) {
            return response()->json(['error' => 'Benchmark template not found.'], 404);
        }

        DB::transaction(function () use ($benchmarkTemplate) {
            $organisationIds = $benchmarkTemplate->organisation_ids ?? [];
            $languageIds = $benchmarkTemplate->language_ids ?? [];

            if (!empty($organisationIds) && !empty($languageIds)) {
                DB::table('organisation_organisation_languages')
                    ->whereIn('organisation_id', $organisationIds)
                    ->whereIn('language_id', $languageIds)
                    ->update(['assessment_period' => null]);
            }

            $benchmarkTemplate->delete();
        });

        return response()->json(['message' => 'Benchmark template deleted successfully.'], 200);
    }
}
