<?php

namespace App\Http\Controllers;

use App\Models\GroupingParameter;
use App\Models\Level;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GroupingParameterController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // $isAdmin = $request->has('is_admin') ? filter_var($request->get('is_admin'), FILTER_VALIDATE_BOOLEAN) : false;
        $isAdmin = false; // Make sure always excludes archived grades (level 6)
        $limit = $request->input('limit');
        $offset = $request->input('offset', 0);

        $query = GroupingParameter::query(); // Base query from GroupingParameter model

        // add filters for grade_id
        if ($request->has('grade_id') || $request->has('grade')) {
            $query->where('common_groupingparameter.grade_id', $request->input('grade', $request->input('grade_id')));
        }

        // add filters for language_id
        if ($request->has('language_id')) {
            $query->where('common_groupingparameter.language_id', $request->language_id);
        }

        // add filters for language__name
        if ($request->has('language__name')) {
            $query->whereHas('language', function ($q) use ($request) {
                $q->where('name', $request->language__name);
            });
        }

        // add filters for grade_level
        if ($request->has('grade_level') || $request->has('grade__level')) {
            $gradeLevel = $request->input('grade__level', $request->input('grade_level'));
            $query->whereHas('grade', function ($q) use ($gradeLevel) {
                $q->where('level', $gradeLevel);
            });
        }

        // If not admin, exclude archived grades (level 6)
        if (!$isAdmin) {
            $query->whereHas('grade', function ($q) {
                $q->where('level', '!=', 6);
            });
        }

        // left join with common_level and order by rank and id
        $query->leftJoin('common_level', 'common_groupingparameter.level_id', '=', 'common_level.id')
            ->select('common_groupingparameter.*')
            ->orderBy('common_level.rank', 'asc')
            ->orderBy('common_groupingparameter.id', 'asc');

        $totalCount = $query->count(); // Get total count

        // add limit and offset for pagination
        if ($limit) {
            $query->skip($offset)->take($limit);
        }

        // load the relations
        $parameters = $query->with(['level', 'grade', 'language'])->get();

        // format the response
        $formatted = $parameters->map(function ($gp) {
            $grade = $gp->grade;
            $level = $gp->level;

            return [
                'id' => $gp->id,
                'grade_details' => $grade ? [
                    'id' => $grade->id,
                    'created' => $this->formatDateTime($grade->created),
                    'updated' => $this->formatDateTime($grade->updated),
                    'title' => $grade->title,
                    'description' => $grade->description,
                    'level' => (int)$grade->level,
                ] : null,
                'level_details' => $level ? [
                    'id' => $level->id,
                    'created' => $this->formatDateTime($level->created),
                    'updated' => $this->formatDateTime($level->updated),
                    'title' => $level->title,
                    'description' => $level->description,
                    'rank' => (int)$level->rank,
                ] : null,
                'language' => $gp->language ? $gp->language->name : null,
                'name' => $gp->name,
                'upper_bound' => (int)$gp->upper_bound,
                'lower_bound' => (int)$gp->lower_bound,
                'goal' => (int)$gp->goal,
                'level' => $gp->level_id,
                'grade' => $gp->grade_id,
            ];
        });

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
            'results' => $formatted
        ]);
    }

    /**
     * Bulk update grouping parameters with validation.
     */
    public function bulkUpdate(Request $request)
    {
        $request->validate([
            'grade__level' => 'nullable',
            'grade_id' => 'nullable|exists:common_grade,id',
            'language_id' => 'nullable|string',
            'grouping_parameters' => 'required|array',
            'grouping_parameters.*.id' => 'required|exists:common_groupingparameter,id',
            'grouping_parameters.*.lower_bound' => 'required|integer|min:0',
            'grouping_parameters.*.upper_bound' => 'required|integer|min:0',
            'grouping_parameters.*.goal' => 'required|integer|min:0',
        ]);

        $gradeId = $request->grade_id;
        $gradeLevel = $request->grade__level;
        $languageId = $request->language_id;
        $params = collect($request->grouping_parameters);
        $paramIds = $params->pluck('id')->toArray();

        if ($gradeLevel) {
            $grade = DB::table('common_grade')
                ->where('level', $gradeLevel)
                ->first();

            if (!$grade) {
                return response()->json([
                    'error' => "Grade not found."
                ], 400);
            }

            $gradeId = $grade->id;
        }

        if ($languageId) {
            $language = DB::table('common_language')
                ->where('name', $languageId)
                ->first();

            if (!$language) {
                return response()->json([
                    'error' => "Language not found."
                ], 400);
            }

            $languageId = $language->id;
        }

        // Load all affected parameters with their levels in one go
        $existingParams = GroupingParameter::with('level')
            ->whereIn('id', $paramIds)
            ->get()
            ->keyBy('id');

        // If gradeId or languageId are missing, infer from the first existing parameter
        $firstParam = $existingParams->first();
        if (!$gradeId) $gradeId = $firstParam->grade_id;
        if (!$languageId) $languageId = $firstParam->language_id;

        // Verify all parameters belong to the same grade and language
        foreach ($existingParams as $gp) {
            if ($gp->grade_id !== $gradeId || $gp->language_id !== $languageId) {
                return response()->json([
                    'error' => "Parameter {$gp->id} does not belong to the specified grade and language."
                ], 400);
            }
        }

        // Map and sort by level rank
        $parameterEntries = $params->map(function ($p) use ($existingParams) {
            $p['level_rank'] = $existingParams[$p['id']]->level->rank;
            return $p;
        })->sortBy('level_rank')->values();

        // 1. Internal Bound Check & Range Overlap/Gap Check
        // Note: As rank increases (1 -> 2 -> 3), bounds DECREASE.
        // Rank 1: 17-5000, Rank 2: 11-16, Rank 3: 5-10
        for ($i = 0; $i < $parameterEntries->count(); $i++) {
            $current = $parameterEntries[$i];

            // Internal Bound Check
            if ($current['lower_bound'] > $current['upper_bound']) {
                return response()->json([
                    'error' => "Internal bound check failed: lower_bound must be <= upper_bound for ID {$current['id']}"
                ], 400);
            }

            // Range Overlap and Gap Check (compared to previous level)
            if ($i > 0) {
                $previous = $parameterEntries[$i - 1]; // Previous has LOWER rank (better level), so HIGHER bounds

                // Range Overlap: current upper_bound must be < previous lower_bound
                if ($current['upper_bound'] >= $previous['lower_bound']) {
                    return response()->json([
                        'error' => "Range overlap detected between level rank {$previous['level_rank']} and {$current['level_rank']}. Level {$current['level_rank']} upper_bound ({$current['upper_bound']}) must be less than level {$previous['level_rank']} lower_bound ({$previous['lower_bound']})"
                    ], 400);
                }

                // Range Gap: current upper_bound + 1 == previous lower_bound
                if ($current['upper_bound'] + 1 !== $previous['lower_bound']) {
                    return response()->json([
                        'error' => "Range gap detected: level {$current['level_rank']} upper_bound ({$current['upper_bound']}) must be exactly 1 less than level {$previous['level_rank']} lower_bound ({$previous['lower_bound']})"
                    ], 400);
                }
            }
        }

        // 2. Perform updates in a transaction
        DB::transaction(function () use ($parameterEntries) {
            foreach ($parameterEntries as $entry) {
                GroupingParameter::where('id', $entry['id'])->update([
                    'lower_bound' => $entry['lower_bound'],
                    'upper_bound' => $entry['upper_bound'],
                    'goal' => $entry['goal'],
                ]);
            }
        });

        // Fetch and return the updated parameters in the same format as index
        $updatedParams = GroupingParameter::leftJoin('common_level', 'common_groupingparameter.level_id', '=', 'common_level.id')
            ->select('common_groupingparameter.*')
            ->with(['level', 'grade', 'language'])
            ->whereIn('common_groupingparameter.id', $paramIds)
            ->orderBy('common_level.rank', 'asc')
            ->orderBy('common_groupingparameter.id', 'asc')
            ->get();

        $formatted = $updatedParams->map(function ($gp) {
            $grade = $gp->grade;
            $level = $gp->level;

            return [
                'id' => $gp->id,
                'grade_details' => $grade ? [
                    'id' => $grade->id,
                    'created' => $this->formatDateTime($grade->created),
                    'updated' => $this->formatDateTime($grade->updated),
                    'title' => $grade->title,
                    'description' => $grade->description,
                    'level' => (int)$grade->level,
                ] : null,
                'level_details' => $level ? [
                    'id' => $level->id,
                    'created' => $this->formatDateTime($level->created),
                    'updated' => $this->formatDateTime($level->updated),
                    'title' => $level->title,
                    'description' => $level->description,
                    'rank' => (int)$level->rank,
                ] : null,
                'language' => $gp->language ? $gp->language->name : null,
                'name' => $gp->name,
                'upper_bound' => (int)$gp->upper_bound,
                'lower_bound' => (int)$gp->lower_bound,
                'goal' => (int)$gp->goal,
                'level' => $gp->level_id,
                'grade' => $gp->grade_id,
            ];
        });

        return response()->json($formatted);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->all();
        if (!isset($data['id'])) {
            $data['id'] = (string) Str::uuid();
        }
        
        $groupingParameter = GroupingParameter::create($data);

        return response()->json([
            'message' => 'Grouping parameter created successfully',
            'data' => $groupingParameter
        ], 201);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $groupingParameter = GroupingParameter::find($id);

        if (!$groupingParameter) {
            return response()->json([
                'message' => 'Grouping parameter not found'
            ], 404);
        }

        $groupingParameter->update($request->all());

        return response()->json([
            'message' => 'Grouping parameter updated successfully',
            'data' => $groupingParameter
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $groupingParameter = GroupingParameter::find($id);

        if (!$groupingParameter) {
            return response()->json([
                'message' => 'Grouping parameter not found'
            ], 404);
        }

        $groupingParameter->delete();

        return response()->json([
            'message' => 'Grouping parameter deleted successfully'
        ], 200);
    }

    /**
     * Debug grouping parameters without formatting.
     */
    public function debug(Request $request)
    {
        $assessmentPeriod = $request->get('assessment_period');
        $languageId = $request->get('language_id');
        $gradeId = $request->get('grade_id');
        $levelId = $request->get('level_id');
        $benchmarkTemplateId = $request->get('benchmark_template_id');

        $groupingParameter = GroupingParameter::with('grade', 'language');

        if ($assessmentPeriod) {
            $groupingParameter->where('assessment_period', $assessmentPeriod);
        }

        if ($languageId) {
            $groupingParameter->where('language_id', $languageId);
        }

        if ($gradeId) {
            $groupingParameter->where('grade_id', $gradeId);
        }

        if ($levelId) {
            $groupingParameter->where('level_id', $levelId);
        }

        if ($benchmarkTemplateId) {
            $groupingParameter->where('benchmark_template_id', $benchmarkTemplateId);
        }

        $response = $groupingParameter->get();

        return response()->json($response);
    }
}
