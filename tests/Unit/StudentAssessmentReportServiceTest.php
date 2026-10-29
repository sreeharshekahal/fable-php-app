<?php

namespace Tests\Unit;

use App\Services\StudentAssessmentReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentAssessmentReportServiceTest extends TestCase
{
    protected StudentAssessmentReportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StudentAssessmentReportService();
    }

    /**
     * Test Academic Year Calculation:
     * BOY in March 2026, MOY in August 2026, EOY in December 2026
     * all belong to 2026-2027 academic year.
     */
    public function test_academic_year_single_cycle()
    {
        $assessments = collect([
            (object)[
                'id' => '1',
                'created' => '2026-03-15 10:00:00',
                'assessment_period' => 'BOY',
                'type' => 1,
            ],
            (object)[
                'id' => '2',
                'created' => '2026-08-20 11:00:00',
                'assessment_period' => 'MOY',
                'type' => 1,
            ],
            (object)[
                'id' => '3',
                'created' => '2026-12-10 09:30:00',
                'assessment_period' => 'EOY',
                'type' => 1,
            ],
        ]);

        $info = $this->service->calculateAcademicYearInfo($assessments);

        $this->assertEquals('2026-2027', $info['academic_year']);
        $this->assertEquals(2026, $info['start_year']);
        $this->assertCount(3, $info['assessments']);
    }

    /**
     * Test Academic Year Transition:
     * When a new BOY starts in April 2027 (different calendar year),
     * academic year changes to 2027-2028 and only the new BOY data displays.
     */
    public function test_academic_year_new_boy_in_different_calendar_year()
    {
        $assessments = collect([
            (object)[
                'id' => '1',
                'created' => '2026-03-15 10:00:00',
                'assessment_period' => 'BOY',
                'type' => 1,
            ],
            (object)[
                'id' => '2',
                'created' => '2026-08-20 11:00:00',
                'assessment_period' => 'MOY',
                'type' => 1,
            ],
            (object)[
                'id' => '3',
                'created' => '2026-12-10 09:30:00',
                'assessment_period' => 'EOY',
                'type' => 1,
            ],
            (object)[
                'id' => '4',
                'created' => '2027-04-05 10:15:00',
                'assessment_period' => 'BOY',
                'type' => 1,
            ],
        ]);

        $info = $this->service->calculateAcademicYearInfo($assessments);

        $this->assertEquals('2027-2028', $info['academic_year']);
        $this->assertEquals(2027, $info['start_year']);
        $this->assertCount(1, $info['assessments']);
        $this->assertEquals('4', $info['assessments']->first()->id);
    }

    /**
     * Test Period Normalization (only BOY, MOY, EOY, ALL supported; BOI/MOI/EOI rejected).
     */
    public function test_period_normalization()
    {
        $this->assertEquals('BOY', $this->service->normalizePeriod('BOY'));
        $this->assertEquals('BOY', $this->service->normalizePeriod('boy'));
        $this->assertEquals('MOY', $this->service->normalizePeriod('MOY'));
        $this->assertEquals('MOY', $this->service->normalizePeriod('moy'));
        $this->assertEquals('EOY', $this->service->normalizePeriod('EOY'));
        $this->assertEquals('EOY', $this->service->normalizePeriod('eoy'));
        $this->assertEquals('ALL', $this->service->normalizePeriod('ALL'));
        $this->assertEquals('ALL', $this->service->normalizePeriod('all'));
        $this->assertNull($this->service->normalizePeriod('BOI'));
        $this->assertNull($this->service->normalizePeriod('MOI'));
        $this->assertNull($this->service->normalizePeriod('EOI'));
        $this->assertNull($this->service->normalizePeriod(null));
        $this->assertNull($this->service->normalizePeriod('invalid'));
    }

    /**
     * Test parseBooleanFilter handles various inputs.
     */
    public function test_parse_boolean_filter()
    {
        $this->assertTrue($this->service->parseBooleanFilter(['audio_link' => 'true'], ['audio_link']));
        $this->assertTrue($this->service->parseBooleanFilter(['audio_link' => '1'], ['audio_link']));
        $this->assertTrue($this->service->parseBooleanFilter(['audio_link' => true], ['audio_link']));
        $this->assertTrue($this->service->parseBooleanFilter(['audio_link' => 'yes'], ['audio_link']));
        $this->assertTrue($this->service->parseBooleanFilter(['audio_link' => ''], ['audio_link']));

        $this->assertFalse($this->service->parseBooleanFilter(['audio_link' => 'false'], ['audio_link']));
        $this->assertFalse($this->service->parseBooleanFilter(['audio_link' => '0'], ['audio_link']));
        $this->assertFalse($this->service->parseBooleanFilter(['audio_link' => false], ['audio_link']));
        $this->assertFalse($this->service->parseBooleanFilter(['audio_link' => 'no'], ['audio_link']));

        // Default when omitted
        $this->assertTrue($this->service->parseBooleanFilter([], ['audio_link'], true));
        $this->assertFalse($this->service->parseBooleanFilter([], ['audio_link'], false));
    }

    /**
     * Test resolveGraphTypeFilters with independent flags and graph_type string.
     */
    public function test_resolve_graph_type_filters()
    {
        // 1. Defaults (both true)
        $res = $this->service->resolveGraphTypeFilters([]);
        $this->assertTrue($res['progress_monitoring']);
        $this->assertTrue($res['benchmarking']);

        // 2. Explicit booleans
        $res = $this->service->resolveGraphTypeFilters(['progress_monitoring' => 'true', 'benchmarking' => 'false']);
        $this->assertTrue($res['progress_monitoring']);
        $this->assertFalse($res['benchmarking']);

        $res = $this->service->resolveGraphTypeFilters(['progress_monitoring' => '0', 'benchmarking' => '1']);
        $this->assertFalse($res['progress_monitoring']);
        $this->assertTrue($res['benchmarking']);

        // 3. graph_type string
        $res = $this->service->resolveGraphTypeFilters(['graph_type' => 'progress_monitoring']);
        $this->assertTrue($res['progress_monitoring']);
        $this->assertFalse($res['benchmarking']);

        $res = $this->service->resolveGraphTypeFilters(['graph_type' => 'benchmarking']);
        $this->assertFalse($res['progress_monitoring']);
        $this->assertTrue($res['benchmarking']);

        $res = $this->service->resolveGraphTypeFilters(['graph_type' => 'both']);
        $this->assertTrue($res['progress_monitoring']);
        $this->assertTrue($res['benchmarking']);

        $res = $this->service->resolveGraphTypeFilters(['graph_type' => 'none']);
        $this->assertFalse($res['progress_monitoring']);
        $this->assertFalse($res['benchmarking']);

        // 4. graph_type array
        $res = $this->service->resolveGraphTypeFilters(['graph_type' => ['progress_monitoring']]);
        $this->assertTrue($res['progress_monitoring']);
        $this->assertFalse($res['benchmarking']);

        // 5. comma separated
        $res = $this->service->resolveGraphTypeFilters(['graph_type' => 'progress_monitoring,benchmarking']);
        $this->assertTrue($res['progress_monitoring']);
        $this->assertTrue($res['benchmarking']);
    }

    /**
     * Test buildTrendAnalysis respects PM and BM flags.
     */
    public function test_build_trend_analysis_respects_graph_type_filters()
    {
        $student = new \App\Models\Student();
        $student->id = (string) Str::uuid();

        $assessments = collect([
            (object)[
                'id' => (string) Str::uuid(),
                'created' => '2026-05-10 10:00:00',
                'type' => 0, // PM
                'passage_grade_level' => 3,
                'assessment_grade_level' => 3,
                'last_word_index' => 50,
                'no_error_words' => 2,
                'notes' => '',
                'assessment_period' => 'BOY',
                'passage_name' => 'P1',
                'audio' => null,
                'benchmark_goal' => 30,
                'passage_language_id' => null,
                'grade_id' => null,
                'assessment_group_level_id' => null,
            ],
            (object)[
                'id' => (string) Str::uuid(),
                'created' => '2026-05-15 10:00:00',
                'type' => 1, // BM
                'passage_grade_level' => 3,
                'assessment_grade_level' => 3,
                'last_word_index' => 60,
                'no_error_words' => 5,
                'notes' => '',
                'assessment_period' => 'BOY',
                'passage_name' => 'P2',
                'audio' => null,
                'benchmark_goal' => 35,
                'passage_language_id' => null,
                'grade_id' => null,
                'assessment_group_level_id' => null,
            ],
        ]);

        // When both enabled
        $trendBoth = $this->service->buildTrendAnalysis($assessments, $student, null, '2026-2027', true, true, false);
        $this->assertEquals('2026-27', $trendBoth['academic_year']);
        $this->assertEquals('Trend Analysis - 2026-27', $trendBoth['title']);
        $this->assertNotNull($trendBoth['progress_monitoring']);
        $this->assertNotNull($trendBoth['benchmarking']);

        // When PM only
        $trendPmOnly = $this->service->buildTrendAnalysis($assessments, $student, null, '2026-2027', true, false, false);
        $this->assertNotNull($trendPmOnly['progress_monitoring']);
        $this->assertNull($trendPmOnly['benchmarking']);

        // When BM only
        $trendBmOnly = $this->service->buildTrendAnalysis($assessments, $student, null, '2026-2027', false, true, false);
        $this->assertNull($trendBmOnly['progress_monitoring']);
        $this->assertNotNull($trendBmOnly['benchmarking']);
    }

    /**
     * Test Audio Link filter: when disabled, audio fields return null/N/A.
     * Also tests that repeated variables (audio, qualitative_feedback) are not present.
     */
    public function test_audio_link_filter_disables_audio_fields()
    {
        $item = (object)[
            'id' => (string) Str::uuid(),
            'created' => '2026-06-25 10:00:00',
            'last_word_index' => 50,
            'no_error_words' => 5,
            'notes' => 'Good reading',
            'assessment_period' => 'BOY',
            'passage_name' => 'Sample Passage',
            'audio' => 'https://example.com/audio.mp3',
            'type' => 1,
            'benchmark_goal' => 35,
            'passage_language_id' => null,
            'grade_id' => null,
            'assessment_group_level_id' => null,
        ];

        $student = new \App\Models\Student();
        $student->id = (string) Str::uuid();

        // Audio link enabled
        $formattedWithAudio = $this->service->formatAssessmentItem($item, $student, null, true, 1);
        $this->assertTrue($formattedWithAudio['has_audio']);
        $this->assertEquals('https://example.com/audio.mp3', $formattedWithAudio['audio_download_url']);
        $this->assertEquals('Download', $formattedWithAudio['audio_label']);
        $this->assertEquals('Good reading', $formattedWithAudio['teacher_notes']);
        $this->assertArrayNotHasKey('audio', $formattedWithAudio);
        $this->assertArrayNotHasKey('qualitative_feedback', $formattedWithAudio);

        // Audio link disabled
        $formattedWithoutAudio = $this->service->formatAssessmentItem($item, $student, null, false, 1);
        $this->assertFalse($formattedWithoutAudio['has_audio']);
        $this->assertNull($formattedWithoutAudio['audio_download_url']);
        $this->assertEquals('N/A', $formattedWithoutAudio['audio_label']);
        $this->assertArrayNotHasKey('audio', $formattedWithoutAudio);
        $this->assertArrayNotHasKey('qualitative_feedback', $formattedWithoutAudio);
    }

    /**
     * Test Retell and Fluency Quality resolves based on last assessment.
     */
    public function test_retell_and_fluency_quality_evaluates_only_last_assessment()
    {
        $olderId = (string) Str::uuid();
        $latestId = (string) Str::uuid();
        $studentId = (string) Str::uuid();

        $assessments = collect([
            (object)[
                'id' => $olderId,
                'created' => '2026-06-20 09:00:00',
            ],
            (object)[
                'id' => $latestId,
                'created' => '2026-06-25 10:00:00',
            ],
        ]);

        $result = $this->service->resolveRetellAndFluencyQuality($studentId, null, $assessments);

        $this->assertEquals('Checklist Based on last assessment', $result['subtitle']);
        $this->assertEquals($latestId, $result['assessment_id']);
        $this->assertEquals(1, $result['assessments_evaluated_count']);
    }
}

