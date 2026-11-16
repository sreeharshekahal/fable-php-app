<?php

namespace Tests\Feature;

use App\Services\StudentAssessmentReportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AssessmentChecklistPeriodTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('assessment_checklist', function (Blueprint $t) {
            $t->string('id'); $t->string('title'); $t->integer('type'); $t->string('language_id');
        });
        Schema::create('assessment_assessment_checklists', function (Blueprint $t) {
            $t->string('assessment_id'); $t->string('checklist_id');
        });
        DB::table('assessment_checklist')->insert([
            'id' => 'criterion', 'title' => 'Retells details', 'type' => 1, 'language_id' => 'english',
        ]);
    }

    public function test_every_assessment_in_selected_period_contributes(): void
    {
        foreach (['BOY', 'MOY', 'EOY'] as $period) {
            $assessments = collect(range(1, 7))->map(function ($i) use ($period) {
                return (object) ['id' => "$period-$i", 'created' => "2025-07-0$i", 'assessment_period' => $period];
            });
            $assessments->push((object) ['id' => 'other', 'created' => '2025-07-08', 'assessment_period' => 'ALL']);
            DB::table('assessment_assessment_checklists')->insert(['assessment_id' => "$period-1", 'checklist_id' => 'criterion']);
            $report = (new StudentAssessmentReportService())->resolveRetellAndFluencyQuality('student', 'english', $assessments, $period);
            $this->assertSame(7, $report['assessments_evaluated_count']);
            $this->assertCount(7, $report['periods'][$period]['assessment_ids']);
            $this->assertSame('Yes', $report['periods'][$period]['criteria'][0]['status']);
        }
    }

    public function test_empty_period_does_not_fetch_unscoped_assessments(): void
    {
        $report = (new StudentAssessmentReportService())->resolveRetellAndFluencyQuality('student', 'english', collect(), 'EOY');
        $this->assertSame(0, $report['assessments_evaluated_count']);
        $this->assertSame([], $report['periods']['EOY']['assessment_ids']);
        $this->assertSame('No', $report['periods']['EOY']['criteria'][0]['status']);
    }

    public function test_passage_names_do_not_override_or_supply_periods(): void
    {
        $assessments = collect([
            (object) ['id' => 'explicit', 'created' => '2025-07-01', 'assessment_period' => 'BOY', 'passage_name' => 'Story (EOY)'],
            (object) ['id' => 'missing', 'created' => '2025-07-02', 'assessment_period' => null, 'passage_name' => 'Story (EOY)'],
            (object) ['id' => 'numeric', 'created' => '2025-07-03', 'assessment_period' => '202602', 'passage_name' => 'Story (EOY)'],
        ]);
        $service = new StudentAssessmentReportService();
        $report = $service->resolveRetellAndFluencyQuality('student', 'english', $assessments, 'EOY');
        $this->assertSame(0, $report['assessments_evaluated_count']);
        $report = $service->resolveRetellAndFluencyQuality('student', 'english', $assessments, 'BOY');
        $this->assertSame(['explicit'], $report['periods']['BOY']['assessment_ids']);
        $this->assertNull($service->normalizePeriod(null));
        $this->assertNull($service->normalizePeriod('202602'));
        $this->assertNull($service->normalizePeriod('invalid'));
        $this->assertSame('EOY', $service->normalizePeriod(' eoy '));
    }

    public function test_full_year_counts_every_scoped_assessment(): void
    {
        $assessments = collect(range(1, 8))->map(function ($i) {
            return (object) ['id' => "a$i", 'created' => "2025-07-0$i", 'assessment_period' => 'BOY'];
        });
        $report = (new StudentAssessmentReportService())->resolveRetellAndFluencyQuality('student', 'english', $assessments);
        $this->assertSame(8, $report['assessments_evaluated_count']);
        $this->assertSame(8, $report['periods']['BOY']['assessments_evaluated_count']);
        $this->assertSame(0, $report['periods']['EOY']['assessments_evaluated_count']);
    }
}
