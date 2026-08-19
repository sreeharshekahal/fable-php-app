<?php

namespace App\Http\Controllers;

use App\Models\Passage;
use App\Models\PassageWord;
use App\Models\WordMorphemeMap;
use App\Models\WordPhonicMap;
use App\Models\WordSightMap;
use App\Services\WordCategorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PassageController extends Controller
{
    protected $categorizationService;

    public function __construct(WordCategorizationService $categorizationService)
    {
        $this->categorizationService = $categorizationService;
    }

    /**
     * List passages with filtering, search, ordering, and pagination.
     * Matches Python Django implementation: PassageViewSet.list
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = Passage::query()
            ->with(['grade', 'language'])
            ->leftJoin('common_grade', 'passage_passage.grade_id', '=', 'common_grade.id')
            ->select('passage_passage.*');

        // Search by passage name
        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where('passage_passage.passage_name', 'ILIKE', "%{$search}%");
        }

        // Filter by grade id
        if ($request->filled('grade__id')) {
            $query->where('passage_passage.grade_id', $request->query('grade__id'));
        } elseif ($request->filled('grade_id')) {
            $query->where('passage_passage.grade_id', $request->query('grade_id'));
        } elseif ($request->filled('grade')) {
            $query->where('passage_passage.grade_id', $request->query('grade'));
        }

        // Filter by grade level
        if ($request->filled('grade__level')) {
            $query->where('common_grade.level', (int) $request->query('grade__level'));
        } elseif ($request->filled('grade_level')) {
            $query->where('common_grade.level', (int) $request->query('grade_level'));
        }

        // Filter by language name
        if ($request->filled('language__name')) {
            $langName = $request->query('language__name');
            $query->whereHas('language', function ($q) use ($langName) {
                $q->where('name', 'ILIKE', $langName);
            });
        } elseif ($request->filled('language_name')) {
            $langName = $request->query('language_name');
            $query->whereHas('language', function ($q) use ($langName) {
                $q->where('name', 'ILIKE', $langName);
            });
        } elseif ($request->filled('language')) {
            $lang = $request->query('language');
            if (preg_match('/^[0-9a-fA-F-]{36}$/', $lang)) {
                $query->where('passage_passage.language_id', $lang);
            } else {
                $query->whereHas('language', function ($q) use ($lang) {
                    $q->where('name', 'ILIKE', $lang);
                });
            }
        }

        // Filter by exact passage name
        if ($request->filled('passage_name')) {
            $query->where('passage_passage.passage_name', $request->query('passage_name'));
        }

        // Filter by status if explicitly passed
        if ($request->has('status') && $request->query('status') !== '' && $request->query('status') !== null) {
            $statusVal = filter_var($request->query('status'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($statusVal !== null) {
                $query->where('passage_passage.status', $statusVal);
            }
        }

        // Ordering logic
        $reverseOrder = $request->query('reverse_order');
        $isReverse = ($reverseOrder === 'true' || $reverseOrder === '1' || $reverseOrder === true || $reverseOrder === 1);

        if ($isReverse) {
            $query->orderBy('common_grade.level', 'desc')
                  ->orderBy('passage_passage.number', 'desc');
        } elseif ($request->filled('ordering')) {
            $orderingParts = explode(',', $request->query('ordering'));
            $hasOrdered = false;
            foreach ($orderingParts as $part) {
                $part = trim($part);
                if (empty($part)) {
                    continue;
                }

                $direction = str_starts_with($part, '-') ? 'desc' : 'asc';
                $field = ltrim($part, '-');

                if ($field === 'grade__level' || $field === 'grade_level') {
                    $query->orderBy('common_grade.level', $direction);
                    $hasOrdered = true;
                } elseif ($field === 'number') {
                    $query->orderBy('passage_passage.number', $direction);
                    $hasOrdered = true;
                } elseif ($field === 'passage_name') {
                    $query->orderBy('passage_passage.passage_name', $direction);
                    $hasOrdered = true;
                } elseif ($field === 'created') {
                    $query->orderBy('passage_passage.created', $direction);
                    $hasOrdered = true;
                } elseif ($field === 'updated') {
                    $query->orderBy('passage_passage.updated', $direction);
                    $hasOrdered = true;
                } elseif ($field === 'readability_score') {
                    $query->orderBy('passage_passage.readability_score', $direction);
                    $hasOrdered = true;
                }
            }
            if (!$hasOrdered) {
                $query->orderBy('common_grade.level', 'asc')
                      ->orderBy('passage_passage.number', 'asc');
            }
        } else {
            $query->orderBy('common_grade.level', 'asc')
                  ->orderBy('passage_passage.number', 'asc');
        }

        $total = $query->count('passage_passage.id');
        $limit = (int) $request->query('limit', 10);
        $offset = (int) $request->query('offset', 0);

        $passages = $query->offset($offset)->limit($limit)->get();

        $passageIds = $passages->pluck('id')->toArray();
        $assessedMap = [];
        if (!empty($passageIds)) {
            $assessedMap = DB::table('assessment_assessment')
                ->whereIn('passage_id', $passageIds)
                ->pluck('passage_id')
                ->flip()
                ->toArray();
        }

        $studentId = $request->query('student');
        $studentAssessedMap = [];
        if (!empty($passageIds) && $studentId) {
            $studentAssessedMap = DB::table('assessment_assessment')
                ->where('student_id', $studentId)
                ->whereIn('passage_id', $passageIds)
                ->pluck('passage_id')
                ->flip()
                ->toArray();
        }

        $results = [];
        foreach ($passages as $passage) {
            $langName = $passage->language ? $passage->language->name : 'English';
            $words = $this->categorizationService->getWordsFromPassage($passage->content ?? $passage->raw_content ?? '', $langName);
            $uniqueWords = array_unique($words);

            $results[] = [
                'id' => $passage->id,
                'word_count' => count($words),
                'grade_no' => $passage->grade ? (int) $passage->grade->level : 0,
                'is_assessed' => isset($assessedMap[$passage->id]),
                'no_unique_words' => count($uniqueWords),
                'passage_no' => ($passage->grade ? (string) $passage->grade->level : '0') . '-' . $passage->number,
                'is_current_student_assessed' => $studentId ? isset($studentAssessedMap[$passage->id]) : null,
                'raw_content' => $passage->raw_content,
                'language' => $passage->language ? $passage->language->name : null,
                'created' => $this->formatDateTime($passage->created),
                'updated' => $this->formatDateTime($passage->updated),
                'passage_name' => $passage->passage_name,
                'content' => $passage->content,
                'number' => (int) $passage->number,
                'readability_score' => (float) ($passage->readability_score ?? 0.0),
                'grade' => $passage->grade_id,
                'created_by' => $passage->created_by_id,
                'status' => (bool) $passage->status,
            ];
        }

        $queryParams = $request->query();

        $nextUrl = null;
        if ($offset + $limit < $total) {
            $nextQuery = array_merge($queryParams, [
                'limit' => $limit,
                'offset' => $offset + $limit,
            ]);
            $nextUrl = $request->url() . '?' . http_build_query($nextQuery);
        }

        $previousUrl = null;
        if ($offset > 0) {
            $prevOffset = $offset - $limit;
            $prevQuery = array_merge($queryParams, [
                'limit' => $limit,
            ]);
            if ($prevOffset <= 0) {
                unset($prevQuery['offset']);
            } else {
                $prevQuery['offset'] = $prevOffset;
            }
            $previousUrl = $request->url() . '?' . http_build_query($prevQuery);
        }

        return response()->json([
            'count' => $total,
            'next' => $nextUrl,
            'previous' => $previousUrl,
            'results' => $results,
        ], 200);
    }

    /**
     * Display the specified passage.
     * Matches Python Django implementation: PassageViewSet.retrieve
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, $id)
    {
        $sanitizedId = $this->sanitizeUuid($id);

        if (!$sanitizedId) {
            return response()->json([
                'detail' => 'Not found.'
            ], 404);
        }

        $passage = Passage::with(['grade', 'language'])->find($sanitizedId);

        if (!$passage) {
            return response()->json([
                'detail' => 'Not found.'
            ], 404);
        }

        $langName = $passage->language ? $passage->language->name : 'English';
        $words = $this->categorizationService->getWordsFromPassage($passage->content ?? $passage->raw_content ?? '', $langName);
        $uniqueWords = array_unique($words);

        $studentId = $request->query('student');
        $isCurrentStudentAssessed = null;
        if ($studentId) {
            $isCurrentStudentAssessed = DB::table('assessment_assessment')
                ->where('passage_id', $passage->id)
                ->where('student_id', $studentId)
                ->exists();
        }

        $isAssessed = DB::table('assessment_assessment')
            ->where('passage_id', $passage->id)
            ->exists();

        // Optimized batch calculation of word types
        $wordRecords = PassageWord::whereIn('text', $uniqueWords)->get()->keyBy('text');
        $wordIds = $wordRecords->pluck('id')->toArray();

        $sightCounts = DB::table('passage_wordsightmap')
            ->whereIn('word_id', $wordIds)
            ->select('word_id', DB::raw('count(*) as count'))
            ->groupBy('word_id')
            ->pluck('count', 'word_id')
            ->toArray();

        $phonicCounts = DB::table('passage_wordphonicmap')
            ->whereIn('word_id', $wordIds)
            ->select('word_id', DB::raw('count(*) as count'))
            ->groupBy('word_id')
            ->pluck('count', 'word_id')
            ->toArray();

        $morphemeCounts = DB::table('passage_wordmorphememap')
            ->whereIn('word_id', $wordIds)
            ->select('word_id', DB::raw('count(*) as count'))
            ->groupBy('word_id')
            ->pluck('count', 'word_id')
            ->toArray();

        $sightWords = 0;
        $phonics = 0;
        $morphemes = 0;
        foreach ($words as $word) {
            if (isset($wordRecords[$word])) {
                $wId = $wordRecords[$word]->id;
                $sightWords += $sightCounts[$wId] ?? 0;
                $phonics += $phonicCounts[$wId] ?? 0;
                $morphemes += $morphemeCounts[$wId] ?? 0;
            }
        }

        $data = [
            'id' => $passage->id,
            'word_type_count' => [
                'sight_words' => $sightWords,
                'phonics' => $phonics,
                'morphemes' => $morphemes,
            ],
            'word_count' => count($words),
            'is_assessed' => $isAssessed,
            'no_unique_words' => count($uniqueWords),
            'grade_no' => $passage->grade ? (int) $passage->grade->level : 0,
            'passage_no' => ($passage->grade ? (string) $passage->grade->level : '0') . '-' . $passage->number,
            'is_current_student_assessed' => $isCurrentStudentAssessed,
            'language' => $passage->language ? $passage->language->name : null,
            'created' => $this->formatDateTime($passage->created),
            'updated' => $this->formatDateTime($passage->updated),
            'passage_name' => $passage->passage_name,
            'content' => $passage->content,
            'raw_content' => $passage->raw_content,
            'number' => (int) $passage->number,
            'readability_score' => (float) ($passage->readability_score ?? 0.0),
            'grade' => $passage->grade_id,
            'created_by' => $passage->created_by_id,
            'status' => (bool) $passage->status,
        ];

        return response()->json($data, 200);
    }

    /**
     * Update status for a passage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStatus(Request $request, $id)
    {
        $sanitizedId = $this->sanitizeUuid($id);

        if (!$sanitizedId) {
            return response()->json([
                'message' => 'Passage not found'
            ], 404);
        }

        $data = $request->all();

        if (isset($data['status'])) {
            if ($data['status'] === 'true' || $data['status'] === 1 || $data['status'] === '1') {
                $data['status'] = true;
            } elseif ($data['status'] === 'false' || $data['status'] === 0 || $data['status'] === '0') {
                $data['status'] = false;
            }
        }

        $validator = Validator::make($data, [
            'status' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $passage = Passage::find($sanitizedId);

        if (!$passage) {
            return response()->json([
                'message' => 'Passage not found'
            ], 404);
        }

        $passage->status = (bool) $data['status'];
        $passage->save();

        return response()->json([
            'message' => 'Passage status updated successfully',
            'data' => $passage
        ], 200);
    }
}
