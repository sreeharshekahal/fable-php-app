<?php

namespace App\Http\Controllers;

use App\Models\PassageWord;
use App\Models\Phonic;
use App\Services\WordCategorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WordController extends Controller
{
    const CONSONANT_DIGRAPH_TRIGRAPH_CODE = "consonant_digraphs_and_trigraphs";
    const DIPTHONG_TRIPTHONG_CODE = "dipthong_and_common_vowel_triphthongs";

    protected $categorizationService;

    public function __construct(WordCategorizationService $categorizationService)
    {
        $this->categorizationService = $categorizationService;
    }

    /**
     * Display a listing of words.
     * Matches Python Django implementation: WordListViewSet
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $search = $request->query('search');

        $query = PassageWord::query()->select('id', 'text');

        if ($search) {
            $query->where('text', 'like', '%' . $search . '%');
        }


        // Return paginated results (10 per page by default)
        $words = $query->paginate($request->query('limit', 10));

        return response()->json($words, 200);
    }

    /**
     * Display word linguistic details.
     * Matches Python Django implementation: fable/passage/views.py:word_dict_view
     *
     * @param  string  $id
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($wordId, Request $request)
    {
        $languageName = $request->query('language__name');

        $query = PassageWord::query();


        $word = $query->find($wordId);

        if (!$word) {
            return response()->json(['details' => 'word not found'], 404);
        }

        $category = $this->categorizationService->categorize($word);

        $result = [
            "id" => $word->id,
            "text" => $word->text,
            "sight_words" => $category["sight_words"],
            "morphemes" => [
                "prefix" => null,
                "suffix" => null
            ],
            "phonics" => []
        ];

        if (count($category["morphemes"]) > 0) {
            $result["morphemes"] = $category["morphemes"][1];
        }

        if (count($category["phonics"]) > 0) {
            // Handle Type 1 (CVC) which might return ['cvc', 'CVC'] as the first element of phonics
            // In Python: if type(category["phonics"][0]) == str
            if (is_string($category["phonics"][0])) {
                $result["phonics"][] = [
                    "subcategory" => $category["phonics"][0],
                    "substring" => null,
                    "type" => null
                ];
            } else {
                foreach ($category["phonics"] as $obj) {
                    $subcategory_obj = Phonic::where('subcategory_code', $obj[0])->first();
                    if (!$subcategory_obj) continue;

                    $subcategory = $subcategory_obj->subcategory;
                    $subcategory_code = $subcategory_obj->subcategory_code;
                    $obj_dict = [
                        "subcategory" => $subcategory,
                        "substring" => $obj[1],
                        "type" => null
                    ];

                    if ($subcategory_code == self::CONSONANT_DIGRAPH_TRIGRAPH_CODE) {
                        if (strlen($obj[1]) == 2) {
                            $obj_dict["type"] = "Digraph";
                        } else {
                            $obj_dict["type"] = "Trigraph";
                        }
                    } elseif ($subcategory_code == self::DIPTHONG_TRIPTHONG_CODE) {
                        if (strlen($obj[1]) == 2) {
                            $obj_dict["type"] = "Dipthong";
                        } else {
                            $obj_dict["type"] = "Triphthong";
                        }
                    }
                    $result["phonics"][] = $obj_dict;
                }
            }
        }

        return response()->json($result, 200);
    }
}
