<?php

namespace App\Http\Controllers;

use App\Models\Language;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LanguageController extends Controller
{
    /**
     * List all languages.
     * Matches Python DRF LanguageViewSet.list
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = Language::query()->select('id', 'name');

        $total = $query->count();
        $limit = (int) ($request->query('limit') ?? $request->query('page_size') ?? 50);
        $offset = (int) $request->query('offset', 0);

        if ($request->has('page') && !$request->has('offset')) {
            $offset = max(0, ((int) $request->query('page') - 1) * $limit);
        }

        $languages = $query->offset($offset)->limit($limit)->get();

        $queryParams = $request->query();
        unset($queryParams['authenticated_user']);

        $nextUrl = null;
        if ($offset + $limit < $total) {
            $nextUrl = $request->fullUrlWithQuery(array_merge($queryParams, [
                'limit' => $limit,
                'offset' => $offset + $limit,
            ]));
        }

        $prevUrl = null;
        if ($offset > 0) {
            $prevOffset = max(0, $offset - $limit);
            $prevUrl = $request->fullUrlWithQuery(array_merge($queryParams, [
                'limit' => $limit,
                'offset' => $prevOffset,
            ]));
        }

        return response()->json([
            'count' => $total,
            'next' => $nextUrl,
            'previous' => $prevUrl,
            'results' => $languages,
        ]);
    }

    /**
     * Retrieve a single language.
     *
     * @param string $id
     * @return JsonResponse
     */
    public function show(string $id): JsonResponse
    {
        $language = Language::select('id', 'name')->find($id);

        if (!$language) {
            return response()->json([
                'detail' => 'Not found.'
            ], 404);
        }

        return response()->json($language);
    }
}

