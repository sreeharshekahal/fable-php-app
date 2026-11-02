<?php

namespace App\Http\Controllers;

use App\Models\Grade;
use Illuminate\Http\Request;
use Carbon\Carbon;

class GradeController extends Controller
{
    /**
     * Display a listing of grades.
     * Matches Python Django: GradeViewSet.list
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = Grade::query();

        // Admin flag: if true, include level 6, else exclude level 6
        $isAdmin = false;
        if ($request->has('is_admin')) {
            $isAdminParam = $request->query('is_admin');
            $isAdmin = filter_var($isAdminParam, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ?? ($isAdminParam === 'True' || $isAdminParam === '1' || $isAdminParam === 1);
        }

        if (!$isAdmin) {
            $query->where('level', '!=', 6);
        }

        // Language filter: filter grades that have grouping parameters or passages in that language
        $languageName = $request->query('language__name') ?? $request->query('language_name') ?? $request->query('language');
        if ($languageName) {
            $query->where(function ($sub) use ($languageName) {
                $sub->whereHas('groupingParameters.language', function ($q) use ($languageName) {
                    $q->where('name', 'ILIKE', $languageName);
                })->orWhereHas('passages.language', function ($q) use ($languageName) {
                    $q->where('name', 'ILIKE', $languageName);
                });
            });
        }

        // Ordering: default by level asc
        if ($request->filled('ordering')) {
            $orderingParts = explode(',', $request->query('ordering'));
            $hasOrdered = false;
            foreach ($orderingParts as $part) {
                $part = trim($part);
                if (empty($part)) {
                    continue;
                }
                $direction = str_starts_with($part, '-') ? 'desc' : 'asc';
                $field = ltrim($part, '-');
                if (in_array($field, ['level', 'title', 'created', 'updated'])) {
                    $query->orderBy($field, $direction);
                    $hasOrdered = true;
                }
            }
            if (!$hasOrdered) {
                $query->orderBy('level', 'asc');
            }
        } else {
            $query->orderBy('level', 'asc');
        }

        $total = $query->count();
        $limit = (int) $request->query('limit', 50);
        $offset = (int) $request->query('offset', 0);

        $grades = $query->offset($offset)->limit($limit)->get();

        $formatted = $grades->map(function ($grade) {
            return [
                'id' => $grade->id,
                'created' => $this->formatDateTime($grade->created),
                'updated' => $this->formatDateTime($grade->updated),
                'title' => $grade->title,
                'description' => $grade->description,
                'level' => (int) $grade->level,
            ];
        });

        $nextUrl = null;
        if ($offset + $limit < $total) {
            $nextUrl = $request->fullUrlWithQuery(['offset' => $offset + $limit, 'limit' => $limit]);
        }
        $prevUrl = null;
        if ($offset > 0) {
            $prevOffset = max(0, $offset - $limit);
            $prevUrl = $request->fullUrlWithQuery(['offset' => $prevOffset, 'limit' => $limit]);
        }

        return response()->json([
            'count' => $total,
            'next' => $nextUrl,
            'previous' => $prevUrl,
            'results' => $formatted->values()->all(),
        ], 200);
    }

    /**
     * Display the specified grade.
     * Matches Python Django: GradeViewSet.retrieve
     *
     * @param Request $request
     * @param string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, $id)
    {
        $grade = Grade::find($id);

        if (!$grade) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        return response()->json([
            'id' => $grade->id,
            'created' => $this->formatDateTime($grade->created),
            'updated' => $this->formatDateTime($grade->updated),
            'title' => $grade->title,
            'description' => $grade->description,
            'level' => (int) $grade->level,
        ], 200);
    }
}
