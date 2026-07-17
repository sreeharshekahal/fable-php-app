<?php

namespace App\Services;

use App\Models\PassageWord;
use App\Models\Phonic;
use App\Models\Morpheme;
use App\Models\Sight;
use App\Models\Fry;
use App\Models\WordPhonicMap;
use App\Models\WordMorphemeMap;
use App\Models\WordSightMap;
use Illuminate\Support\Str;

class WordCategorizationService
{
    protected $vowels = ['a', 'e', 'i', 'o', 'u'];
    protected $adjacent_letters = ['a', 'e', 'i', 'o', 'u', 'y'];

    public function categorize(PassageWord $word)
    {
        $sightWords = $this->sightWordCategorization($word);
        $phonics = $this->phonicsWordCategorization($word);

        $morphemes = [];
        if (count($phonics) === 1 && isset($phonics[0][1]) && $phonics[0][0] === 'cvc') {
            $morphemes = [];
        } else {
            $morphemes = $this->morphemeWordCategorization($word);
        }

        return [
            'sight_words' => $sightWords,
            'phonics' => $phonics,
            'morphemes' => $morphemes
        ];
    }

    protected function sightWordCategorization(PassageWord $word)
    {
        $fry = Fry::where('word', $word->text)->first();
        if ($fry) {
            WordSightMap::updateOrCreate(
                ['word_id' => $word->id, 'sight_word_id' => $fry->sight_id],
                ['id' => (string) Str::uuid()]
            );
            return $fry->sight->subcategory;
        }
        return null;
    }

    protected function isPrefix(PassageWord $word)
    {
        $morphemePrefix = Morpheme::where('subcategory_code', 'prefix')->first();
        if (!$morphemePrefix) return [false, null];

        $substrings = $morphemePrefix->subcategory_substrings;
        usort($substrings, function($a, $b) {
            return mb_strlen($b) - mb_strlen($a);
        });

        foreach ($substrings as $st) {
            if (str_starts_with($word->text, $st)) {
                WordMorphemeMap::updateOrCreate(
                    ['word_id' => $word->id, 'morpheme_id' => $morphemePrefix->id],
                    ['id' => (string) Str::uuid()]
                );
                return [$morphemePrefix->subcategory_code, $st];
            }
        }
        return [false, null];
    }

    protected function isSuffix(PassageWord $word)
    {
        $morphemeSuffix = Morpheme::where('subcategory_code', 'suffix')->first();
        if (!$morphemeSuffix) return [false, null];

        $substrings = $morphemeSuffix->subcategory_substrings;
        usort($substrings, function($a, $b) {
            return mb_strlen($b) - mb_strlen($a);
        });

        foreach ($substrings as $st) {
            if (str_ends_with($word->text, $st)) {
                WordMorphemeMap::updateOrCreate(
                    ['word_id' => $word->id, 'morpheme_id' => $morphemeSuffix->id],
                    ['id' => (string) Str::uuid()]
                );
                return [$morphemeSuffix->subcategory_code, $st];
            }
        }
        return [false, null];
    }

    protected function morphemeWordCategorization(PassageWord $word)
    {
        $morphemeCodes = [];
        [$prefixCode, $sub1] = $this->isPrefix($word);
        [$suffixCode, $sub2] = $this->isSuffix($word);

        if ($prefixCode) $morphemeCodes[] = $prefixCode;
        if ($suffixCode) $morphemeCodes[] = $suffixCode;

        return [$morphemeCodes, ['prefix' => $sub1, 'suffix' => $sub2]];
    }

    protected function phonicAdjacentCheck(PassageWord $word, Phonic $phonic)
    {
        $substrings = $phonic->subcategory_substrings;
        usort($substrings, function($a, $b) {
            return mb_strlen($b) - mb_strlen($a);
        });

        foreach ($substrings as $st) {
            $lastPos = 0;
            while (($lastPos = mb_strpos($word->text, $st, $lastPos)) !== false) {
                $flag1 = false;
                $flag2 = false;
                $start = $lastPos;
                $end = $lastPos + mb_strlen($st);

                if ($start === 0) {
                    $flag1 = true;
                } else {
                    $prevChar = mb_substr($word->text, $start - 1, 1);
                    if (in_array($prevChar, $this->adjacent_letters)) {
                        $flag1 = true;
                    }
                }

                if ($end === mb_strlen($word->text)) {
                    $flag2 = true;
                } else {
                    $nextChar = mb_substr($word->text, $end, 1);
                    if (in_array($nextChar, $this->adjacent_letters)) {
                        $flag2 = true;
                    }
                }

                if ($flag1 && $flag2) {
                    WordPhonicMap::updateOrCreate(
                        ['word_id' => $word->id, 'phonic_id' => $phonic->id],
                        ['id' => (string) Str::uuid()]
                    );
                    return [true, $st];
                }
                $lastPos += mb_strlen($st);
            }
        }
        return [false, null];
    }

    protected function phonicType1(PassageWord $word)
    {
        if (mb_strlen($word->text) === 3) {
            $char0 = mb_substr($word->text, 0, 1);
            $char1 = mb_substr($word->text, 1, 1);
            $char2 = mb_substr($word->text, 2, 1);
            if (in_array($char1, $this->vowels)) {
                if (!in_array($char0, $this->vowels) && !in_array($char2, $this->vowels)) {
                    $cvc = Phonic::where('subcategory_code', 'cvc')->first();
                    if ($cvc) {
                        WordPhonicMap::updateOrCreate(
                            ['word_id' => $word->id, 'phonic_id' => $cvc->id],
                            ['id' => (string) Str::uuid()]
                        );
                        return [$cvc->subcategory_code, 'CVC'];
                    }
                }
            }
        }
        return [false, null];
    }

    protected function phonicTypeGeneric($word, $code)
    {
        $phonic = Phonic::where('subcategory_code', $code)->first();
        if (!$phonic) return [false, null];
        [$result, $st] = $this->phonicAdjacentCheck($word, $phonic);
        if ($result) {
            return [$phonic->subcategory_code, $st];
        }
        return [false, null];
    }

    protected function phonicType5(PassageWord $word)
    {
        $phonic = Phonic::where('subcategory_code', 'vowel_digraphs')->first();
        if (!$phonic) return [false, null];

        $substrings = $phonic->subcategory_substrings;
        usort($substrings, function($a, $b) {
            return mb_strlen($b) - mb_strlen($a);
        });

        foreach ($substrings as $st) {
            $lastPos = 0;
            while (($lastPos = mb_strpos($word->text, $st, $lastPos)) !== false) {
                $flag1 = false;
                $flag2 = false;
                $start = $lastPos;
                $end = $lastPos + mb_strlen($st);

                if ($start === 0) {
                    $flag1 = true;
                } else {
                    $prevChar = mb_substr($word->text, $start - 1, 1);
                    if (!in_array($prevChar, $this->vowels)) {
                        $flag1 = true;
                    }
                }

                if ($end === mb_strlen($word->text)) {
                    $flag2 = true;
                } else {
                    $nextChar = mb_substr($word->text, $end, 1);
                    if (!in_array($nextChar, $this->vowels)) {
                        $flag2 = true;
                    }
                }

                if ($flag1 && $flag2) {
                    WordPhonicMap::updateOrCreate(
                        ['word_id' => $word->id, 'phonic_id' => $phonic->id],
                        ['id' => (string) Str::uuid()]
                    );
                    return [$phonic->subcategory_code, $st];
                }
                $lastPos += mb_strlen($st);
            }
        }
        return [false, null];
    }

    protected function phonicType6(PassageWord $word)
    {
        $phonic = Phonic::where('subcategory_code', 'dipthong_and_common_vowel_triphthongs')->first();
        if (!$phonic) return [false, null];

        $substrings = $phonic->subcategory_substrings;
        usort($substrings, function($a, $b) {
            return mb_strlen($b) - mb_strlen($a);
        });

        foreach ($substrings as $st) {
            $lastPos = 0;
            while (($lastPos = mb_strpos($word->text, $st, $lastPos)) !== false) {
                $flag1 = false;
                $flag2 = false;
                $start = $lastPos;
                $end = $lastPos + mb_strlen($st);

                if ($start === 0) {
                    $flag1 = true;
                } else {
                    $prevChar = mb_substr($word->text, $start - 1, 1);
                    if (!in_array($prevChar, $this->vowels)) {
                        $flag1 = true;
                    }
                }

                if ($end === mb_strlen($word->text)) {
                    $flag2 = true;
                } else {
                    $nextChar = mb_substr($word->text, $end, 1);
                    if (!in_array($nextChar, $this->vowels)) {
                        $flag2 = true;
                    }
                }

                if ($flag1 && $flag2) {
                    WordPhonicMap::updateOrCreate(
                        ['word_id' => $word->id, 'phonic_id' => $phonic->id],
                        ['id' => (string) Str::uuid()]
                    );
                    return [$phonic->subcategory_code, $st];
                }
                $lastPos += mb_strlen($st);
            }
        }
        return [false, null];
    }

    protected function phonicType7(PassageWord $word)
    {
        $phonic = Phonic::where('subcategory_code', 'silent_magic_e')->first();
        if (!$phonic) return [false, null];
        $len = mb_strlen($word->text);
        if ($len >= 3) {
            if (mb_substr($word->text, $len - 1, 1) === 'e' &&
                !in_array(mb_substr($word->text, $len - 2, 1), $this->vowels) &&
                in_array(mb_substr($word->text, $len - 3, 1), $this->vowels)) {
                WordPhonicMap::updateOrCreate(
                    ['word_id' => $word->id, 'phonic_id' => $phonic->id],
                    ['id' => (string) Str::uuid()]
                );
                return [$phonic->subcategory_code, 'e'];
            }
        }
        return [false, null];
    }

    protected function phonicType8(PassageWord $word)
    {
        $phonic = Phonic::where('subcategory_code', 'consonant_les_le')->first();
        if (!$phonic) return [false, null];
        $len = mb_strlen($word->text);
        if ($len >= 4) {
            if (str_ends_with($word->text, 'les') || str_ends_with($word->text, 'le')) {
                $offset = str_ends_with($word->text, 'les') ? 3 : 2;
                if (!in_array(mb_substr($word->text, $len - ($offset + 1), 1), $this->vowels) &&
                    !in_array(mb_substr($word->text, $len - $offset, 1), $this->vowels)) {
                    WordPhonicMap::updateOrCreate( // Fixed typo
                        ['word_id' => $word->id, 'phonic_id' => $phonic->id],
                        ['id' => (string) Str::uuid()]
                    );
                    return [$phonic->subcategory_code, $offset === 3 ? 'les' : 'le'];
                }
            }
        }
        return [false, null];
    }

    protected function phonicType9(PassageWord $word)
    {
        $phonic = Phonic::where('subcategory_code', 'r_controlled')->first();
        if (!$phonic) return [false, null];
        $len = mb_strlen($word->text);
        for ($i = 0; $i < $len - 1; $i++) {
            if (in_array(mb_substr($word->text, $i, 1), $this->vowels) && mb_substr($word->text, $i + 1, 1) === 'r') {
                WordPhonicMap::updateOrCreate(
                    ['word_id' => $word->id, 'phonic_id' => $phonic->id],
                    ['id' => (string) Str::uuid()]
                );
                return [$phonic->subcategory_code, 'r'];
            }
        }
        return [false, null];
    }


    protected function phonicsWordCategorization(PassageWord $word)
    {
        $phonicsMatches = [];

        [$code1, $sub1] = $this->phonicType1($word);
        if ($code1 === 'cvc') {
            $phonicsMatches[] = $code1;
            return $phonicsMatches;
        }

        $phonicsMatches[] = $this->phonicTypeGeneric($word, 'consonant_blends');
        $phonicsMatches[] = $this->phonicTypeGeneric($word, 'consonant_digraphs_and_trigraphs');
        $phonicsMatches[] = $this->phonicTypeGeneric($word, 'silent_ghost');
        $phonicsMatches[] = $this->phonicType5($word);
        $phonicsMatches[] = $this->phonicType6($word);
        $phonicsMatches[] = $this->phonicType7($word);
        $phonicsMatches[] = $this->phonicType8($word);
        $phonicsMatches[] = $this->phonicType9($word);

        return array_values(array_filter($phonicsMatches, function($m) {
            return $m[1] !== null;
        }));
    }

    /**
     * Extracts words from a passage based on language rules.
     * Matches Python: fable/passage/util/word_categorization.py:words_from_passage
     */
    public function getWordsFromPassage(string $content, string $language): array
    {
        if ($language === 'Hindi') {
            // Clean: remove everything except Devanagari, whitespace, and Danda
            $clean = preg_replace('/[^\x{0900}-\x{097F}\s\x{0964}]/u', '', $content);
            // Split: by whitespace and Danda
            return array_values(array_filter(preg_split('/[\s\x{0964}]+/u', $clean)));
        } elseif ($language === 'Marathi') {
            // Clean: include Devanagari extensions for Marathi
            $clean = preg_replace('/[^\x{0900}-\x{097F}\x{1CD0}-\x{1CF6}\s\x{0964}]/u', '', $content);
            // Split: include Zero Width Space (\x{200B})
            return array_values(array_filter(preg_split('/[\s\x{0964}\x{200B}]+/u', $clean)));
        } elseif ($language === 'English') {
            // Matches words including hyphens and apostrophes, then filters out punctuation
            preg_match_all("~[-#$\w@.,/']+~u", $content, $matches);
            $words = [];
            $punctuation = "!\"#$%&'()*+,-./:;<=>?@[\\]^_`{|}~";
            foreach ($matches[0] as $word) {
                if (str_contains($punctuation, $word)) {
                    continue;
                }
                $words[] = mb_strtolower($word, 'UTF-8');
            }
            return $words;
        }
        return [];
    }
}
