<?php

namespace App\Http\Controllers;

use App\Models\Accuracy;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AccuracyController extends Controller
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

        $query = Accuracy::query();

        if ($request->has('grade_id') || $request->has('grade')) {
            $query->where('common_accuracy.grade_id', $request->input('grade', $request->input('grade_id')));
        }

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

        $query->leftJoin('common_grade', 'common_accuracy.grade_id', '=', 'common_grade.id')
            ->select('common_accuracy.*')
            ->orderBy('common_grade.level', 'asc')
            ->orderBy('common_accuracy.id', 'asc');

        $totalCount = $query->count();

        if ($limit) {
            $query->skip($offset)->take($limit);
        }

        $accuracies = $query->with('grade')->get();

        $formatted = $accuracies->map(function ($acc) {
            $grade = $acc->grade;
            return [
                'id' => $acc->id,
                'grade_details' => $grade ? [
                    'id' => $grade->id,
                    'created' => $this->formatDateTime($grade->created),
                    'updated' => $this->formatDateTime($grade->updated),
                    'title' => $grade->title,
                    'description' => $grade->description,
                    'level' => (int)$grade->level,
                ] : null,
                'percentage' => (int)$acc->percentage,
                'grade' => $acc->grade_id,
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
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->all();
        if (!isset($data['id'])) {
            $data['id'] = (string) Str::uuid();
        }
        
        $accuracy = Accuracy::create($data);

        return response()->json([
            'message' => 'Accuracy created successfully',
            'data' => $accuracy
        ], 201);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $accuracy = Accuracy::find($id);

        if (!$accuracy) {
            return response()->json([
                'message' => 'Accuracy not found'
            ], 404);
        }

        $accuracy->update($request->all());

        return response()->json([
            'message' => 'Accuracy updated successfully',
            'data' => $accuracy
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $accuracy = Accuracy::find($id);

        if (!$accuracy) {
            return response()->json([
                'message' => 'Accuracy not found'
            ], 404);
        }

        $accuracy->delete();

        return response()->json([
            'message' => 'Accuracy deleted successfully'
        ], 200);
    }

    /**
     * Debug accuracies without formatting.
     */
    public function debug(Request $request)
    {
        $assessmentPeriod = $request->get('assessment_period');
        $languageId = $request->get('language_id');
        $gradeId = $request->get('grade_id');
        $levelId = $request->get('level_id');
        $benchmarkTemplateId = $request->get('benchmark_template_id');

        $accuracy = Accuracy::with('grade', 'language');

        if ($assessmentPeriod) {
            $accuracy->where('assessment_period', $assessmentPeriod);
        }

        if ($languageId) {
            $accuracy->where('language_id', $languageId);
        }

        if ($gradeId) {
            $accuracy->where('grade_id', $gradeId);
        }

        if ($levelId) {
            $accuracy->where('level_id', $levelId);
        }

        if ($benchmarkTemplateId) {
            $accuracy->where('benchmark_template_id', $benchmarkTemplateId);
        }

        $response = $accuracy->get();

        return response()->json($response);
    }
}
