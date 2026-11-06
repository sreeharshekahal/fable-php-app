<?php

namespace App\Http\Controllers;

use App\Models\Grade;
use App\Models\Language;
use App\Models\Passage;
use App\Models\PassageWord;
use App\Models\WordMorphemeMap;
use App\Models\WordPhonicMap;
use App\Models\WordSightMap;
use App\Services\WordCategorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

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
        $limit = (int) $request->query('limit', 50);
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
            $isAssessed = isset($assessedMap[$passage->id]);
            $isCurrentStudent = $studentId ? isset($studentAssessedMap[$passage->id]) : null;
            $results[] = $this->formatPassageResponse($passage, $studentId, $isAssessed, $isCurrentStudent);
        }

        $queryParams = $request->query();
        unset($queryParams['authenticated_user']);

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
     * Create a new passage.
     * Matches Python Django implementation: PassageViewSet.create
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'passage_name' => 'required|string|max:30',
            'grade' => 'required_without:grade_id',
            'grade_id' => 'required_without:grade',
            'content' => 'sometimes|nullable|string',
            'raw_content' => 'sometimes|nullable|string',
            'readability_score' => 'sometimes|nullable|numeric',
            'language' => 'sometimes|nullable',
            'language_id' => 'sometimes|nullable',
            'status' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        // Handle grade resolution
        $gradeInput = $request->input('grade', $request->input('grade_id'));
        $grade = null;
        if (is_string($gradeInput) && Str::isUuid($gradeInput)) {
            $grade = Grade::find($gradeInput);
        } elseif (is_numeric($gradeInput)) {
            $grade = Grade::where('level', (int) $gradeInput)->first();
        }
        if (!$grade) {
            return response()->json([
                'grade' => ['The selected grade is invalid.']
            ], 400);
        }

        // Handle language resolution
        $language = null;
        if ($request->filled('language') || $request->filled('language_id')) {
            $langInput = $request->input('language', $request->input('language_id'));
            if (is_string($langInput) && Str::isUuid($langInput)) {
                $language = Language::find($langInput);
            } elseif (is_string($langInput)) {
                $language = Language::where('name', 'ILIKE', $langInput)->first();
            }
            if (!$language) {
                return response()->json([
                    'language' => ['The selected language is invalid.']
                ], 400);
            }
        } else {
            $language = Language::where('name', 'ILIKE', 'English')->first();
        }

        // Resolve created_by_id (Admin ID)
        $user = $request->user() ?? $request->attributes->get('authenticated_user');
        $createdById = null;
        if ($user) {
            $admin = DB::table('access_admin')->where('user_id', $user->id)->first();
            if ($admin) {
                $createdById = $admin->id;
            }
        }
        if (!$createdById) {
            $requestedCreatedBy = $request->input('created_by', $request->input('created_by_id'));
            if ($requestedCreatedBy && DB::table('access_admin')->where('id', $requestedCreatedBy)->exists()) {
                $createdById = $requestedCreatedBy;
            } else {
                $createdById = DB::table('access_admin')->value('id');
            }
        }

        // Auto-increment passage number per grade (matching Django Passage.save)
        $lastNumber = Passage::where('grade_id', $grade->id)->max('number');
        $number = $lastNumber ? ((int) $lastNumber + 1) : 1;

        $content = $request->input('content', $request->input('raw_content', ''));
        $rawContent = $request->input('raw_content', $content);
        $readabilityScore = (float) $request->input('readability_score', 0);
        $status = $request->has('status') ? filter_var($request->input('status'), FILTER_VALIDATE_BOOLEAN) : true;

        $passageData = [
            'id' => (string) Str::uuid(),
            'passage_name' => $request->input('passage_name'),
            'content' => $content,
            'raw_content' => $rawContent,
            'readability_score' => $readabilityScore,
            'grade_id' => $grade->id,
            'language_id' => $language ? $language->id : null,
            'number' => $number,
            'created_by_id' => $createdById,
            'created' => now(),
            'updated' => now(),
        ];

        if (\Illuminate\Support\Facades\Schema::hasColumn('passage_passage', 'status')) {
            $passageData['status'] = $status;
        }

        $passage = Passage::create($passageData);

        // Run word categorization (matching Django post_save signal)
        $langName = $language ? $language->name : 'English';
        $words = $this->categorizationService->getWordsFromPassage($content ?: $rawContent, $langName);
        $uniqueWords = array_unique($words);
        foreach ($uniqueWords as $text) {
            $trimmed = trim($text);
            if (empty($trimmed)) {
                continue;
            }
            $wordRecord = PassageWord::where('text', $trimmed)->first();
            if (!$wordRecord) {
                $wordRecord = PassageWord::create([
                    'id' => (string) Str::uuid(),
                    'text' => $trimmed,
                    'created' => now(),
                    'updated' => now(),
                ]);
                $this->categorizationService->categorize($wordRecord);
            }
        }

        $passage->load(['grade', 'language']);

        return response()->json($this->formatPassageResponse($passage), 201);
    }

    /**
     * Update a passage.
     * Matches Python Django implementation: PassageViewSet.update
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
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

        $data = $request->all();

        if (isset($data['status'])) {
            if ($data['status'] === 'true' || $data['status'] === 1 || $data['status'] === '1') {
                $data['status'] = true;
            } elseif ($data['status'] === 'false' || $data['status'] === 0 || $data['status'] === '0') {
                $data['status'] = false;
            }
        }

        $validator = Validator::make($data, [
            'passage_name' => 'sometimes|required|string|max:30',
            'content' => 'sometimes|nullable|string',
            'raw_content' => 'sometimes|nullable|string',
            'readability_score' => 'sometimes|nullable|numeric',
            'grade' => 'sometimes',
            'grade_id' => 'sometimes',
            'language' => 'sometimes|nullable',
            'language_id' => 'sometimes|nullable',
            'status' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors()
            ], 422);
        }

        // Handle grade update
        if ($request->has('grade') || $request->has('grade_id')) {
            $gradeInput = $request->input('grade', $request->input('grade_id'));
            $grade = null;
            if (is_string($gradeInput) && Str::isUuid($gradeInput)) {
                $grade = Grade::find($gradeInput);
            } elseif (is_numeric($gradeInput)) {
                $grade = Grade::where('level', (int) $gradeInput)->first();
            }
            if (!$grade) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => [
                        'grade' => ['The selected grade is invalid.']
                    ]
                ], 422);
            }
            $passage->grade_id = $grade->id;
        }

        // Handle language update
        if ($request->has('language') || $request->has('language_id')) {
            $langInput = $request->input('language', $request->input('language_id'));
            $language = null;
            if (is_string($langInput) && Str::isUuid($langInput)) {
                $language = Language::find($langInput);
            } elseif (is_string($langInput)) {
                $language = Language::where('name', 'ILIKE', $langInput)->first();
            }
            if (!$language) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => [
                        'language' => ['The selected language is invalid.']
                    ]
                ], 422);
            }
            $passage->language_id = $language->id;
        }

        if ($request->has('passage_name')) {
            $passage->passage_name = $request->input('passage_name');
        }

        if ($request->has('content')) {
            $passage->content = $request->input('content');
        }

        if ($request->has('raw_content')) {
            $passage->raw_content = $request->input('raw_content');
        }

        if ($request->has('readability_score')) {
            $passage->readability_score = $request->input('readability_score');
        }

        if (isset($data['status'])) {
            $passage->status = (bool) $data['status'];
        }

        $passage->updated = now();
        $passage->save();

        $passage->load(['grade', 'language']);

        return response()->json($this->formatPassageResponse($passage, $request->query('student')), 200);
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

    /**
     * Bulk delete passages.
     * Matches Python Django implementation: bulk_delete_passage
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function bulkDeletePassages(Request $request)
    {
        if (!$request->has('passages') || $request->input('passages') === null || !is_array($request->input('passages'))) {
            return response()->json([
                'details' => 'passage not found'
            ], 400);
        }

        $passages = $request->input('passages');

        if (empty($passages)) {
            return response()->noContent(204);
        }

        DB::transaction(function () use ($passages) {
            // Find all assessments associated with these passages
            $assessmentIds = DB::table('assessment_assessment')
                ->whereIn('passage_id', $passages)
                ->pluck('id');

            if ($assessmentIds->isNotEmpty()) {
                // Delete cascading dependent tables for these assessments
                DB::table('assessment_errorwords')->whereIn('assessment_id', $assessmentIds)->delete();
                DB::table('assessment_assessment_checklists')->whereIn('assessment_id', $assessmentIds)->delete();
                DB::table('assessment_assessmentstudentgrouphistory')->whereIn('assessment_id', $assessmentIds)->delete();

                // Delete assessments
                DB::table('assessment_assessment')->whereIn('id', $assessmentIds)->delete();
            }

            // Delete passages
            DB::table('passage_passage')->whereIn('id', $passages)->delete();
        });

        return response()->noContent(204);
    }

    /**
     * Delete a single passage.
     * Matches Python Django implementation: PassageViewSet.destroy
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\Response
     */
    public function destroy(Request $request, $id)
    {
        $sanitizedId = $this->sanitizeUuid($id);

        if (!$sanitizedId) {
            return response()->json([
                'detail' => 'Not found.'
            ], 404);
        }

        $passage = Passage::find($sanitizedId);

        if (!$passage) {
            return response()->json([
                'detail' => 'Not found.'
            ], 404);
        }

        DB::transaction(function () use ($passage) {
            // Find all assessments associated with this passage
            $assessmentIds = DB::table('assessment_assessment')
                ->where('passage_id', $passage->id)
                ->pluck('id');

            if ($assessmentIds->isNotEmpty()) {
                // Delete cascading dependent tables for these assessments
                DB::table('assessment_errorwords')->whereIn('assessment_id', $assessmentIds)->delete();
                DB::table('assessment_assessment_checklists')->whereIn('assessment_id', $assessmentIds)->delete();
                DB::table('assessment_assessmentstudentgrouphistory')->whereIn('assessment_id', $assessmentIds)->delete();

                // Delete assessments
                DB::table('assessment_assessment')->whereIn('id', $assessmentIds)->delete();
            }

            // Delete passage
            $passage->delete();
        });

        return response()->noContent(204);
    }

    /**
     * Format a single passage matching Django's PassageSerializer.
     *
     * @param  \App\Models\Passage  $passage
     * @param  string|null  $studentId
     * @param  bool|null  $isAssessed
     * @param  bool|null  $isCurrentStudentAssessed
     * @return array
     */
    protected function formatPassageResponse(Passage $passage, $studentId = null, $isAssessed = null, $isCurrentStudentAssessed = null): array
    {
        $langName = $passage->language ? $passage->language->name : 'English';
        $words = $this->categorizationService->getWordsFromPassage($passage->content ?? $passage->raw_content ?? '', $langName);
        $uniqueWords = array_unique($words);

        if ($isAssessed === null) {
            $isAssessed = DB::table('assessment_assessment')
                ->where('passage_id', $passage->id)
                ->exists();
        }

        if ($isCurrentStudentAssessed === null && $studentId) {
            $isCurrentStudentAssessed = DB::table('assessment_assessment')
                ->where('passage_id', $passage->id)
                ->where('student_id', $studentId)
                ->exists();
        }

        return [
            'id' => $passage->id,
            'word_count' => count($words),
            'grade_no' => $passage->grade ? (int) $passage->grade->level : 0,
            'is_assessed' => (bool) $isAssessed,
            'no_unique_words' => count($uniqueWords),
            'passage_no' => ($passage->grade ? (string) $passage->grade->level : '0') . '-' . $passage->number,
            'is_current_student_assessed' => $studentId ? (bool) $isCurrentStudentAssessed : null,
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
}
