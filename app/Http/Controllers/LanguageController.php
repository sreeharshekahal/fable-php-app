<?php

namespace App\Http\Controllers;

use App\Models\Language;
use Illuminate\Http\JsonResponse;

class LanguageController extends Controller
{
    /**
     * List all languages.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $languages = Language::all(['id', 'name']);
        return response()->json($languages);
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
