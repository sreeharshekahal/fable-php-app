<?php

namespace App\Exports;

use Illuminate\Support\Facades\DB;
use App\Exports\Traits\HasAssessmentExportHelpers;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCharts;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * "Summary" sheet for Assessment Export.
 *
 * Provides a high-level analytics and performance overview of reading assessments:
 *   1. Key Metrics Overview (KPI cards):
 *      - Total Assessments
 *      - Overall Average Accuracy (%)
 *      - Total Correct Words
 *      - Total Error Words
 *   2. Performance by Grade:
 *      - Total assessments, average accuracy %, and average correct words per grade level.
 *   3. Performance by Assessment Type:
 *      - Breakdown between Benchmark and Progressive assessments.
 *   4. Performance by Gender:
 *      - Breakdown across Male, Female, and Other students.
 *   5. Embedded Charts:
 *      - Average Accuracy by Grade (%) column chart (positioned at F3:N17).
 *      - Assessment Volume by Type column chart (positioned at F19:N33).
 */
class AssessmentPeriodSummarySheet implements FromArray, WithTitle, WithCharts, WithColumnWidths, WithStyles, WithStrictNullComparison
{
    /**
     * Query filter parameters (e.g., language, organisation, type, from/to dates).
     *
     * @var array
     */
    protected array $filters;

    /**
     * Starting row index for the Grade performance table.
     * Updated dynamically during array() assembly.
     *
     * @var int
     */
    protected int $gradeStartRow = 10;

    /**
     * Ending row index for the Grade performance table.
     *
     * @var int
     */
    protected int $gradeEndRow = 10;

    /**
     * Starting row index for the Assessment Type breakdown table.
     *
     * @var int
     */
    protected int $typeStartRow = 13;

    /**
     * Ending row index for the Assessment Type breakdown table.
     *
     * @var int
     */
    protected int $typeEndRow = 14;

    /**
     * Starting row index for the Gender breakdown table.
     *
     * @var int
     */
    protected int $genderStartRow = 17;

    /**
     * Ending row index for the Gender breakdown table.
     *
     * @var int
     */
    protected int $genderEndRow = 18;

    /**
     * Create a new AssessmentPeriodSummarySheet instance.
     *
     * @param array $filters Associative array of filters (language, organisation, type, from, to)
     */
    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    /**
     * Return the worksheet tab title.
     *
     * @return string
     */
    public function title(): string
    {
        return 'Summary';
    }

    /**
     * Define default column widths for the summary data layout.
     *
     * @return array<string, int>
     */
    public function columnWidths(): array
    {
        return [
            'A' => 26,
            'B' => 20,
            'C' => 20,
            'D' => 20,
        ];
    }

    /**
     * Build the spreadsheet rows array including KPI blocks, tables, and spacing.
     * Also records dynamic row boundaries used by charts() and styles().
     *
     * @return array
     */
    public function array(): array
    {
        $filters = $this->filters;

        /**
         * Reusable filter closure applied across all aggregated queries.
         */
        $applyFilters = function ($q) use ($filters) {
            if (!empty($filters['language'])) {
                $q->where('l.name', $filters['language']);
            }
            if (!empty($filters['organisation'])) {
                $q->where('a.organisation_id', $filters['organisation']);
            }
            if (!empty($filters['grade'])) {
                $gradeIds = HasAssessmentExportHelpers::parseUuidList($filters['grade']);
                if (!empty($gradeIds)) {
                    $q->whereIn('a.grade_id', $gradeIds);
                }
            }
            if (isset($filters['type']) && $filters['type'] !== null && $filters['type'] !== '') {
                $q->where('a.type', $filters['type']);
            }
            if (!empty($filters['from']) && !empty($filters['to'])) {
                $q->whereDate('a.created', '>=', $filters['from'])
                  ->whereDate('a.created', '<=', $filters['to']);
            }
        };

        // -------------------------------------------------------------
        // 1. SQL Aggregation: Key Performance Indicator (KPI) Metrics
        // -------------------------------------------------------------
        $kpi = DB::table('assessment_assessment as a')
            ->leftJoin('passage_passage as p', 'a.passage_id', '=', 'p.id')
            ->leftJoin('common_language as l', 'p.language_id', '=', 'l.id')
            ->where($applyFilters)
            ->selectRaw("
                COUNT(*) as total_assessments,
                COALESCE(SUM(GREATEST(0, (COALESCE(a.last_word_index, -1) + 1) - COALESCE(a.no_error_words, 0))), 0) as total_correct_words,
                COALESCE(SUM(COALESCE(a.no_error_words, 0)), 0) as total_error_words,
                COALESCE(AVG(CASE WHEN (COALESCE(a.last_word_index, -1) + 1) > 0 THEN (GREATEST(0, (COALESCE(a.last_word_index, -1) + 1) - COALESCE(a.no_error_words, 0)) * 100.0) / (a.last_word_index + 1) ELSE 0 END), 0) as avg_accuracy
            ")
            ->first();

        // -------------------------------------------------------------
        // 2. SQL Aggregation: Performance Metrics Grouped by Grade
        // -------------------------------------------------------------
        $gradeResults = DB::table('assessment_assessment as a')
            ->leftJoin('common_grade as g', 'a.grade_id', '=', 'g.id')
            ->leftJoin('passage_passage as p', 'a.passage_id', '=', 'p.id')
            ->leftJoin('common_language as l', 'p.language_id', '=', 'l.id')
            ->where($applyFilters)
            ->selectRaw("
                COALESCE(g.title, 'Unknown Grade') as grade_title,
                COUNT(*) as count,
                COALESCE(AVG(CASE WHEN (COALESCE(a.last_word_index, -1) + 1) > 0 THEN (GREATEST(0, (COALESCE(a.last_word_index, -1) + 1) - COALESCE(a.no_error_words, 0)) * 100.0) / (a.last_word_index + 1) ELSE 0 END), 0) as avg_accuracy,
                COALESCE(AVG(GREATEST(0, (COALESCE(a.last_word_index, -1) + 1) - COALESCE(a.no_error_words, 0))), 0) as avg_correct_words
            ")
            ->groupBy('g.title', 'g.level')
            ->orderByRaw("COALESCE(g.level, 999) ASC, g.title ASC")
            ->get();

        // -------------------------------------------------------------
        // 3. SQL Aggregation: Performance Metrics Grouped by Type
        // -------------------------------------------------------------
        $typeResults = DB::table('assessment_assessment as a')
            ->leftJoin('passage_passage as p', 'a.passage_id', '=', 'p.id')
            ->leftJoin('common_language as l', 'p.language_id', '=', 'l.id')
            ->where($applyFilters)
            ->selectRaw("
                CASE WHEN a.type = 0 THEN 'Progressive' ELSE 'Benchmark' END as type_name,
                COUNT(*) as count,
                COALESCE(AVG(CASE WHEN (COALESCE(a.last_word_index, -1) + 1) > 0 THEN (GREATEST(0, (COALESCE(a.last_word_index, -1) + 1) - COALESCE(a.no_error_words, 0)) * 100.0) / (a.last_word_index + 1) ELSE 0 END), 0) as avg_accuracy
            ")
            ->groupBy('a.type')
            ->get()
            ->keyBy('type_name');

        // -------------------------------------------------------------
        // 4. SQL Aggregation: Performance Metrics Grouped by Gender
        // -------------------------------------------------------------
        $genderResults = DB::table('assessment_assessment as a')
            ->leftJoin('access_student as s', 'a.student_id', '=', 's.id')
            ->leftJoin('passage_passage as p', 'a.passage_id', '=', 'p.id')
            ->leftJoin('common_language as l', 'p.language_id', '=', 'l.id')
            ->where($applyFilters)
            ->selectRaw("
                CASE WHEN s.gender = 0 THEN 'Male' WHEN s.gender = 1 THEN 'Female' ELSE 'Other' END as gender_name,
                COUNT(*) as count,
                COALESCE(AVG(CASE WHEN (COALESCE(a.last_word_index, -1) + 1) > 0 THEN (GREATEST(0, (COALESCE(a.last_word_index, -1) + 1) - COALESCE(a.no_error_words, 0)) * 100.0) / (a.last_word_index + 1) ELSE 0 END), 0) as avg_accuracy
            ")
            ->groupBy('s.gender')
            ->get()
            ->keyBy('gender_name');

        $rows = [];

        // Row 1: Sheet Title Header Banner
        $rows[] = ['Assessment Analytics & Performance Summary', '', '', ''];
        // Row 2: Blank separator
        $rows[] = ['', '', '', ''];

        // Row 3-6: Key Metrics Overview 2x2 table
        $rows[] = ['Key Metrics Overview', '', '', ''];
        $rows[] = ['Metric', 'Value', 'Metric', 'Value'];
        $rows[] = ['Total Assessments', (int) ($kpi->total_assessments ?? 0), 'Average Accuracy (%)', round((float) ($kpi->avg_accuracy ?? 0), 2)];
        $rows[] = ['Total Correct Words', (int) ($kpi->total_correct_words ?? 0), 'Total Error Words', (int) ($kpi->total_error_words ?? 0)];
        // Row 7: Blank separator
        $rows[] = ['', '', '', ''];

        // Rows 8+: Grade Performance Breakdown Table
        $rows[] = ['Performance by Grade', '', '', ''];
        $rows[] = ['Grade', 'Total Assessments', 'Avg Accuracy (%)', 'Avg Correct Words'];

        // Track data row boundaries for dynamic chart source & borders
        $this->gradeStartRow = count($rows) + 1;
        if ($gradeResults->isEmpty()) {
            $rows[] = ['No Data Available', 0, 0, 0];
        } else {
            foreach ($gradeResults as $gr) {
                $rows[] = [
                    $gr->grade_title,
                    (int) $gr->count,
                    round((float) $gr->avg_accuracy, 2),
                    round((float) $gr->avg_correct_words, 1),
                ];
            }
        }
        $this->gradeEndRow = count($rows);

        // Blank separator
        $rows[] = ['', '', '', ''];

        // Assessment Type Breakdown Table
        $rows[] = ['Performance by Assessment Type', '', '', ''];
        $rows[] = ['Assessment Type', 'Total Assessments', 'Avg Accuracy (%)', ''];

        $this->typeStartRow = count($rows) + 1;
        foreach (['Benchmark', 'Progressive'] as $t) {
            $data = $typeResults->get($t);
            $rows[] = [
                $t,
                $data ? (int) $data->count : 0,
                $data ? round((float) $data->avg_accuracy, 2) : 0,
                '',
            ];
        }
        $this->typeEndRow = count($rows);

        // Blank separator
        $rows[] = ['', '', '', ''];

        // Student Gender Breakdown Table
        $rows[] = ['Performance by Gender', '', '', ''];
        $rows[] = ['Gender', 'Total Assessments', 'Avg Accuracy (%)', ''];

        $this->genderStartRow = count($rows) + 1;
        foreach (['Male', 'Female', 'Other'] as $g) {
            $data = $genderResults->get($g);
            $rows[] = [
                $g,
                $data ? (int) $data->count : 0,
                $data ? round((float) $data->avg_accuracy, 2) : 0,
                '',
            ];
        }
        $this->genderEndRow = count($rows);

        return $rows;
    }

    /**
     * Define native Excel charts to render alongside the tables.
     *
     * Chart 1: Average Accuracy by Grade (%) - Bar/Column chart placed at F3:N17
     * Chart 2: Assessment Volume by Type - Bar/Column chart placed at F19:N33
     *
     * @return array<\PhpOffice\PhpSpreadsheet\Chart\Chart>
     */
    public function charts()
    {
        $charts = [];

        // -------------------------------------------------------------
        // Chart 1: Average Accuracy by Grade (Column Chart)
        // -------------------------------------------------------------
        $gradePointCount = ($this->gradeEndRow - $this->gradeStartRow + 1);
        if ($gradePointCount > 0) {
            // Category axis: Grade titles in column A
            $gradeCategories = [
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Summary'!\$A\${$this->gradeStartRow}:\$A\${$this->gradeEndRow}", null, $gradePointCount),
            ];
            // Value series: Avg Accuracy (%) in column C
            $gradeValues = [
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Summary'!\$C\${$this->gradeStartRow}:\$C\${$this->gradeEndRow}", null, $gradePointCount),
            ];

            $gradeSeries = new DataSeries(
                DataSeries::TYPE_BARCHART,
                DataSeries::GROUPING_CLUSTERED,
                range(0, count($gradeValues) - 1),
                [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1, ['Avg Accuracy (%)'])],
                $gradeCategories,
                $gradeValues,
                DataSeries::DIRECTION_COL
            );

            $plotArea1 = new PlotArea(null, [$gradeSeries]);
            $legend1 = new Legend(Legend::POSITION_TOPRIGHT, null, false);
            $title1 = new Title('Average Accuracy by Grade (%)');

            $chart1 = new Chart('grade_accuracy_chart', $title1, $legend1, $plotArea1);
            $chart1->setTopLeftPosition('F3');
            $chart1->setBottomRightPosition('N17');

            $charts[] = $chart1;
        }

        // -------------------------------------------------------------
        // Chart 2: Total Assessments by Assessment Type (Column Chart)
        // -------------------------------------------------------------
        $typePointCount = ($this->typeEndRow - $this->typeStartRow + 1);
        if ($typePointCount > 0) {
            // Category axis: Assessment types in column A
            $typeCategories = [
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Summary'!\$A\${$this->typeStartRow}:\$A\${$this->typeEndRow}", null, $typePointCount),
            ];
            // Value series: Total assessment counts in column B
            $typeValues = [
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Summary'!\$B\${$this->typeStartRow}:\$B\${$this->typeEndRow}", null, $typePointCount),
            ];

            $typeSeries = new DataSeries(
                DataSeries::TYPE_BARCHART,
                DataSeries::GROUPING_CLUSTERED,
                range(0, count($typeValues) - 1),
                [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1, ['Assessments Count'])],
                $typeCategories,
                $typeValues,
                DataSeries::DIRECTION_COL
            );

            $plotArea2 = new PlotArea(null, [$typeSeries]);
            $legend2 = new Legend(Legend::POSITION_TOPRIGHT, null, false);
            $title2 = new Title('Assessment Volume by Type');

            $chart2 = new Chart('type_volume_chart', $title2, $legend2, $plotArea2);
            $chart2->setTopLeftPosition('F19');
            $chart2->setBottomRightPosition('N33');

            $charts[] = $chart2;
        }

        return $charts;
    }

    /**
     * Apply styles, colors, fills, borders, and alignment to the worksheet.
     *
     * @param Worksheet $sheet
     * @return array
     */
    public function styles(Worksheet $sheet)
    {
        // 1. Title Banner (Row 1): Navy fill with centered white bold text
        $sheet->mergeCells('A1:D1');
        $sheet->getStyle('A1:D1')->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size'  => 14,
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1F4E78'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(32);

        // 2. Section Headers Styling: Soft blue background with navy bold text
        $sectionRows = [3, 8, ($this->gradeEndRow + 2), ($this->typeEndRow + 2)];
        foreach ($sectionRows as $r) {
            $sheet->mergeCells("A{$r}:D{$r}");
            $sheet->getStyle("A{$r}:D{$r}")->applyFromArray([
                'font' => [
                    'bold'  => true,
                    'color' => ['rgb' => '1F4E78'],
                    'size'  => 12,
                ],
                'fill' => [
                    'fillType'   => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'D9E1F2'],
                ],
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);
            $sheet->getRowDimension($r)->setRowHeight(22);
        }

        // 3. Table Column Headers Styling: Royal blue background with white text
        $tableHeaderRows = [4, 9, ($this->gradeEndRow + 3), ($this->typeEndRow + 3)];
        foreach ($tableHeaderRows as $hr) {
            $sheet->getStyle("A{$hr}:D{$hr}")->applyFromArray([
                'font' => [
                    'bold'  => true,
                    'color' => ['rgb' => 'FFFFFF'],
                    'size'  => 10,
                ],
                'fill' => [
                    'fillType'   => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '2F5597'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER,
                ],
            ]);
            $sheet->getRowDimension($hr)->setRowHeight(20);
        }

        // 4. Table Cell Borders and Alignment: Thin grey borders across all data ranges
        $dataRanges = [
            'A5:D6',
            "A{$this->gradeStartRow}:D{$this->gradeEndRow}",
            "A{$this->typeStartRow}:D{$this->typeEndRow}",
            "A{$this->genderStartRow}:D{$this->genderEndRow}",
        ];

        foreach ($dataRanges as $range) {
            $sheet->getStyle($range)->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color'       => ['rgb' => 'D3D3D3'],
                    ],
                ],
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);
        }

        // 5. Number Formatting: Format Accuracy percentage cells with %
        $sheet->getStyle('D5')->getNumberFormat()->setFormatCode('0.0"%"');
        if ($this->gradeEndRow >= $this->gradeStartRow) {
            $sheet->getStyle("C{$this->gradeStartRow}:C{$this->gradeEndRow}")->getNumberFormat()->setFormatCode('0.0"%"');
        }
        if ($this->typeEndRow >= $this->typeStartRow) {
            $sheet->getStyle("C{$this->typeStartRow}:C{$this->typeEndRow}")->getNumberFormat()->setFormatCode('0.0"%"');
        }
        if ($this->genderEndRow >= $this->genderStartRow) {
            $sheet->getStyle("C{$this->genderStartRow}:C{$this->genderEndRow}")->getNumberFormat()->setFormatCode('0.0"%"');
        }

        return [];
    }
}
