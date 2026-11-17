<?php

namespace App\Exports;

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
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Worksheet summarizing student assessment distributions for a specific period (BOY, MOY, EOY).
 *
 * For each grade in the period, displays:
 *   1. Red header banner ("{Grade} {Period} Assessment Overview")
 *   2. Summary table categorized into 4 benchmark tiers:
 *      - Benchmarking
 *      - Fluency Intervention
 *      - Intensive Intervention
 *      - High Priority
 *   3. Embedded 100% stacked column chart placed side-by-side to the right of each grade table.
 */
class AssessmentPeriodOverviewSheet implements FromArray, WithTitle, WithCharts, WithColumnWidths, WithStyles, WithStrictNullComparison
{
    /**
     * Export query filters (organization, language, type, date range).
     */
    protected array $filters;

    /**
     * Assessment period identifier in uppercase ('BOY', 'MOY', or 'EOY').
     */
    protected string $period;

    /**
     * Metadata tracking for each grade block (rows, chart positions, and cell coordinates).
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $blocks = [];

    protected array $divisionBlocks = [];

    /**
     * Create a new AssessmentPeriodOverviewSheet instance.
     *
     * @param array $filters
     * @param string $period
     */
    public function __construct(array $filters, string $period)
    {
        $this->filters = $filters;
        $this->period  = strtoupper($period);
    }

    /**
     * Worksheet tab title (e.g., 'BOY - Data Summary').
     *
     * @return string
     */
    public function title(): string
    {
        return $this->period . ' - Data Summary';
    }

    /**
     * Column widths mapping for table columns and chart spacing.
     *
     * @return array
     */
    public function columnWidths(): array
    {
        return [
            'A' => 20, // Number of Students
            'B' => 28, // Descriptor
            'C' => 16, // %
            'D' => 22, // Also used by the division distribution table
            'E' => 14,
            'F' => 14,
            'G' => 14,
            'H' => 14,
            'I' => 14,
            'J' => 14,
            'K' => 14,
            'L' => 14,
            'M' => 14,
        ];
    }

    /**
     * Build tabular data rows for all grades in the specified assessment period.
     *
     * Evaluates student scores against the 4 benchmark parameter thresholds,
     * formats the summary table, and pads spacer rows to accommodate charts.
     *
     * @return array
     */
    public function array(): array
    {
        $period = $this->period;
        $summaryData = $this->summaryData();
        $groups = $summaryData->groups([$period]);

        $rows         = [];
        $this->blocks = [];
        $this->divisionBlocks = [];

        if ($groups->isEmpty()) {
            $rows[] = ["{$period} Assessment Overview", '', ''];
            $rows[] = ['Number of Students', 'Descriptor', '%', 'Mean accuracy percent', 'Percent =>2 retell'];
            $rows[] = [0, 'Benchmarking', 0.0, 0.0, 0.0];
            $rows[] = [0, 'Fluency Intervention', 0.0, 0.0, 0.0];
            $rows[] = [0, 'Intensive Intervention', 0.0, 0.0, 0.0];
            $rows[] = [0, 'High Priority', 0.0, 0.0, 0.0];

            $this->blocks[] = [
                'gradeTitle'     => 'All',
                'bannerRow'      => 1,
                'headerRow'      => 2,
                'dataStartRow'   => 3,
                'dataEndRow'     => 6,
                'rowBM'          => 3,
                'rowFI'          => 4,
                'rowII'          => 5,
                'rowHP'          => 6,
                'chartTopRow'    => 1,
                'chartBottomRow' => 16,
            ];

            for ($r = 7; $r <= 16; $r++) {
                $rows[] = ['', '', ''];
            }

            return $rows;
        }

        $totalGradesCount = $groups->count();
        $currentRow       = 1;

        foreach ($groups as $group) {
            $gradeDisplay = $summaryData->gradeDisplay($group->first());
            $summary = $summaryData->summarize($group);
            $totalStudents = $summary['totalStudents'];
            $metrics = $summary['levelMetrics'];
            $counts = array_map(fn ($metric) => $metric['count'], $metrics);
            $bm = $metrics['Benchmarking']['param'];
            $fi = $metrics['Fluency Intervention']['param'];
            $ii = $metrics['Intensive Intervention']['param'];
            $hp = $metrics['High Priority']['param'];

            $bmPct = $totalStudents > 0 ? round($counts['Benchmarking'] / $totalStudents * 100, 1) : 0.0;
            $fiPct = $totalStudents > 0 ? round($counts['Fluency Intervention'] / $totalStudents * 100, 1) : 0.0;
            $iiPct = $totalStudents > 0 ? round($counts['Intensive Intervention'] / $totalStudents * 100, 1) : 0.0;
            $hpPct = $totalStudents > 0 ? round($counts['High Priority'] / $totalStudents * 100, 1) : 0.0;

            // Header Banner
            $bannerText = ($totalGradesCount > 1 || empty($this->filters['language']))
                ? "{$gradeDisplay} {$period} Assessment Overview"
                : "{$period} Assessment Overview";

            $bannerRow = $currentRow;
            $rows[]    = [$bannerText, '', ''];
            $currentRow++;

            // Columns Header
            $headerRow = $currentRow;
            $rows[]    = ['Number of Students', 'Descriptor', '%', 'Mean accuracy percent', 'Percent =>2 retell'];
            $currentRow++;

            // 4 Data Rows in Canonical Order matching Screenshot 2:
            // 1. Benchmarking
            // 2. Fluency Intervention
            // 3. Intensive Intervention
            // 4. High Priority
            $rowBM  = $currentRow;
            $rows[] = [$counts['Benchmarking'], $bm['descriptor'] ?? 'Benchmarking', $bmPct, ...$this->additionalMetrics($metrics['Benchmarking'])];
            $currentRow++;

            $rowFI  = $currentRow;
            $rows[] = [$counts['Fluency Intervention'], $fi['descriptor'] ?? 'Fluency Intervention', $fiPct, ...$this->additionalMetrics($metrics['Fluency Intervention'])];
            $currentRow++;

            $rowII  = $currentRow;
            $rows[] = [$counts['Intensive Intervention'], $ii['descriptor'] ?? 'Intensive Intervention', $iiPct, ...$this->additionalMetrics($metrics['Intensive Intervention'])];
            $currentRow++;

            $rowHP  = $currentRow;
            $rows[] = [$counts['High Priority'], $hp['descriptor'] ?? 'High Priority', $hpPct, ...$this->additionalMetrics($metrics['High Priority'])];
            $currentRow++;

            $chartTopRow    = $bannerRow;
            $chartBottomRow = $bannerRow + 15; // 15 rows tall chart side-by-side with table

            $this->blocks[] = [
                'gradeTitle'     => $gradeDisplay,
                'bannerRow'      => $bannerRow,
                'headerRow'      => $headerRow,
                'dataStartRow'   => $rowBM,
                'dataEndRow'     => $rowHP,
                'rowBM'          => $rowBM,
                'rowFI'          => $rowFI,
                'rowII'          => $rowII,
                'rowHP'          => $rowHP,
                'chartTopRow'    => $chartTopRow,
                'chartBottomRow' => $chartBottomRow,
            ];

            // Pad rows so the next grade table starts below the chart
            $neededRows = $chartBottomRow;
            while ($currentRow <= $neededRows) {
                $rows[] = ['', '', ''];
                $currentRow++;
            }
            // Add 1 blank spacer row between grade blocks
            $rows[] = ['', '', ''];
            $currentRow++;

            // Overall reports show only the grade overview; a division-specific
            // report also includes the selected division's distribution table/chart.
            if (isset($this->filters['division']) && $this->filters['division'] !== '') {
                $this->appendDivisionDistribution($rows, $group, $summaryData, $gradeDisplay);
            }
            $currentRow = count($rows) + 1;
        }

        return $rows;
    }

    /** Mean accuracy and percentage with at least two retell items within one intervention group. */
    protected function additionalMetrics(array $metric): array
    {
        $count = $metric['count'];
        return [
            $count > 0 ? round($metric['acc_sum'] / $count, 1) : 0.0,
            $count > 0 ? round($metric['retell_ge2'] / $count * 100, 1) : 0.0,
        ];
    }

    protected function summaryData(): AssessmentSummaryData
    {
        return new AssessmentSummaryData($this->filters);
    }

    /** Add a table and chart for the divisions in this grade/language/organisation. */
    protected function appendDivisionDistribution(array &$rows, Collection $records, AssessmentSummaryData $summaryData, string $gradeDisplay): void
    {
        $levels = ['Benchmarking', 'Fluency Intervention', 'Intensive Intervention', 'High Priority'];
        $grade = $records->first();
        $gradeLabel = $grade->grade_level !== null ? 'G' . $grade->grade_level : $grade->grade_title;
        $bannerRow = count($rows) + 1;
        $rows[] = ["{$gradeDisplay} {$this->period} Division-Wise Comparison"];
        $rows[] = ['Division', 'Intervention Groups'];
        $headers = [''];
        $subheaders = [''];
        foreach ($levels as $index => $level) {
            $headers[] = "{$gradeLabel} $level";
            $headers[] = '';
            $subheaders[] = 'N';
            $subheaders[] = '%';
        }
        $rows[] = $headers;
        $rows[] = $subheaders;
        $dataStartRow = count($rows) + 1;
        $totals = array_fill(0, 4, 0);
        $divisions = $records->groupBy(function ($record) {
            $division = trim((string) ($record->division ?? ''));
            return $division === '' ? 'Unassigned' : $division;
        })->sortKeys(SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($divisions as $division => $divisionRecords) {
            $summary = $summaryData->summarize($divisionRecords);
            $row = [(string) $division];
            foreach ($levels as $index => $level) {
                $count = $summary['levelMetrics'][$level]['count'];
                $totals[$index] += $count;
                $row[] = $count;
                // Store a fraction for Excel percentage formatting. The denominator
                // excludes records skipped for missing or unrecognised To Group levels.
                $row[] = $summary['totalStudents'] > 0 ? $count / $summary['totalStudents'] : 0.0;
            }
            $rows[] = $row;
        }
        $dataEndRow = count($rows);
        // Build overall distribution from classified counts, matching division denominators.
        $totalRow = ['Total'];
        foreach ($totals as $count) {
            $totalRow[] = $count;
            $totalRow[] = array_sum($totals) > 0 ? $count / array_sum($totals) : 0.0;
        }
        $rows[] = $totalRow;
        $chartTopRow = count($rows) + 2;
        $chartBottomRow = $chartTopRow + 18;
        $this->divisionBlocks[] = compact('gradeDisplay', 'bannerRow', 'dataStartRow', 'dataEndRow', 'chartTopRow', 'chartBottomRow');
        while (count($rows) <= $chartBottomRow) {
            // Laravel Excel treats [] as zero rows during appendRows().
            // An explicit blank cell preserves chart spacing and all later coordinates.
            $rows[] = [''];
        }
    }

    /**
     * Generate 100% stacked column charts for each grade block.
     *
     * Positioned side-by-side to the right of each grade's summary table, rendering color-coded
     * vertical stacks for the 4 benchmark levels:
     *   - Benchmarking (bottom, soft pale blue)
     *   - Fluency Intervention (sky blue)
     *   - Intensive Intervention (medium blue)
     *   - High Priority (top, dark navy blue)
     *
     * @return array<\PhpOffice\PhpSpreadsheet\Chart\Chart>
     */
    public function charts(): array
    {
        $charts    = [];
        $sheetName = $this->title();
        $period    = $this->period;

        foreach ($this->blocks as $block) {
            $gradeTitle = $block['gradeTitle'];
            $chartTitle = "{$gradeTitle} {$period} Summary";
            $chartName  = 'chart_' . preg_replace('/\W+/', '_', $gradeTitle) . '_' . strtolower($this->period);

            // Category: single "%" header from the table's column C
            $categories = [
                new DataSeriesValues(
                    DataSeriesValues::DATASERIES_TYPE_STRING,
                    "'{$sheetName}'!\$C\${$block['headerRow']}",
                    null,
                    1
                ),
            ];

            // 4 Series in bottom-to-top stacking order:
            // 1. Benchmarking (bottom, soft pale blue)
            $s1_label = new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                "'{$sheetName}'!\$B\${$block['rowBM']}",
                null,
                1
            );
            $s1_val = (new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                "'{$sheetName}'!\$C\${$block['rowBM']}",
                null,
                1
            ))->setFillColor('C6D9F1'); // Pale soft blue

            // 2. Fluency Intervention (sky blue)
            $s2_label = new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                "'{$sheetName}'!\$B\${$block['rowFI']}",
                null,
                1
            );
            $s2_val = (new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                "'{$sheetName}'!\$C\${$block['rowFI']}",
                null,
                1
            ))->setFillColor('5B9BD5'); // Sky blue

            // 3. Intensive Intervention (medium blue)
            $s3_label = new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                "'{$sheetName}'!\$B\${$block['rowII']}",
                null,
                1
            );
            $s3_val = (new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                "'{$sheetName}'!\$C\${$block['rowII']}",
                null,
                1
            ))->setFillColor('2E75B6'); // Medium blue

            // 4. High Priority (top, dark navy blue)
            $s4_label = new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                "'{$sheetName}'!\$B\${$block['rowHP']}",
                null,
                1
            );
            $s4_val = (new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                "'{$sheetName}'!\$C\${$block['rowHP']}",
                null,
                1
            ))->setFillColor('0F3B66'); // Dark navy blue

            $series = new DataSeries(
                DataSeries::TYPE_BARCHART,
                DataSeries::GROUPING_PERCENT_STACKED,
                [0, 1, 2, 3],
                [$s1_label, $s2_label, $s3_label, $s4_label],
                $categories,
                [$s1_val, $s2_val, $s3_val, $s4_val],
                DataSeries::DIRECTION_COL
            );

            $plotArea = new PlotArea(null, [$series]);
            $legend   = new Legend(Legend::POSITION_BOTTOM, null, false);
            $chart    = new Chart($chartName, new Title($chartTitle), $legend, $plotArea);

            // Chart position: Placed side-by-side to the right of the table (Columns G to K)
            $chart->setTopLeftPosition("G{$block['chartTopRow']}");
            $chart->setBottomRightPosition("K{$block['chartBottomRow']}");

            $charts[] = $chart;
        }

        foreach ($this->divisionBlocks as $index => $block) {
            $start = $block['dataStartRow'];
            $end = $block['dataEndRow'];
            $count = $end - $start + 1;
            $header = $block['bannerRow'] + 2;
            $labels = [];
            $values = [];
            foreach (['C6D9F1', '5B9BD5', '2E75B6', '0F3B66'] as $level => $color) {
                $labelColumn = Coordinate::stringFromColumnIndex(2 + $level * 2);
                $valueColumn = Coordinate::stringFromColumnIndex(3 + $level * 2);
                $labels[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING,
                    "'{$sheetName}'!\${$labelColumn}\${$header}", null, 1);
                $values[] = (new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER,
                    "'{$sheetName}'!\${$valueColumn}\${$start}:\${$valueColumn}\${$end}", null, $count))->setFillColor($color);
            }
            $categories = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING,
                "'{$sheetName}'!\$A\${$start}:\$A\${$end}", null, $count)];
            $series = new DataSeries(DataSeries::TYPE_BARCHART, DataSeries::GROUPING_PERCENT_STACKED,
                [0, 1, 2, 3], $labels, $categories, $values);
            $series->setPlotDirection(DataSeries::DIRECTION_COL);
            $chart = new Chart('division_' . strtolower($period) . '_' . $index,
                new Title($block['gradeDisplay'] . ' ' . $period . ' Division-wise Data Distribution'),
                new Legend(Legend::POSITION_RIGHT, null, false), new PlotArea(null, [$series]));
            $chart->setTopLeftPosition('A' . $block['chartTopRow']);
            $chart->setBottomRightPosition('K' . $block['chartBottomRow']);
            $charts[] = $chart;
        }

        return $charts;
    }

    /**
     * Apply visual styles to each grade block.
     *
     * Formats red banner header, bordered column headers, centered data rows,
     * and percentage formatting in Column C.
     *
     * @param Worksheet $sheet
     * @return array
     */
    public function styles(Worksheet $sheet): array
    {
        foreach ($this->blocks as $block) {
            $bannerRow    = $block['bannerRow'];
            $headerRow    = $block['headerRow'];
            $dataStartRow = $block['dataStartRow'];
            $dataEndRow   = $block['dataEndRow'];

            // 1. Red banner header across columns A to E
            $sheet->mergeCells("A{$bannerRow}:E{$bannerRow}");
            $sheet->getStyle("A{$bannerRow}:E{$bannerRow}")->applyFromArray([
                'font'      => [
                    'bold'  => true,
                    'color' => ['rgb' => 'FFFFFF'],
                    'size'  => 12,
                ],
                'fill'      => [
                    'fillType'   => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'C00000'], // Solid red banner
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER,
                ],
                'borders'   => [
                    'outline' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color'       => ['rgb' => '000000'],
                    ],
                ],
            ]);
            $sheet->getRowDimension($bannerRow)->setRowHeight(26);

            // 2. Table column headers (columns A through E)
            $sheet->getStyle("A{$headerRow}:E{$headerRow}")->applyFromArray([
                'font'      => [
                    'bold'  => true,
                    'color' => ['rgb' => '000000'],
                    'size'  => 11,
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER,
                    'wrapText'   => true,
                ],
                'borders'   => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color'       => ['rgb' => '000000'],
                    ],
                ],
            ]);
            $sheet->getRowDimension($headerRow)->setRowHeight(22);

            // 3. Data rows
            $sheet->getStyle("A{$dataStartRow}:E{$dataEndRow}")->applyFromArray([
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER,
                ],
                'borders'   => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color'       => ['rgb' => '000000'],
                    ],
                ],
            ]);

            // Number format for distribution, accuracy and retell percentages
            for ($r = $dataStartRow; $r <= $dataEndRow; $r++) {
                foreach (['C', 'D', 'E'] as $column) {
                    $sheet->getStyle("{$column}{$r}")->getNumberFormat()->setFormatCode('0.0"%"');
                }
                $sheet->getRowDimension($r)->setRowHeight(20);
            }

        }

        foreach ($this->divisionBlocks as $block) {
            $banner = $block['bannerRow'];
            $groupHeader = $banner + 1;
            $levelHeader = $banner + 2;
            $subheader = $banner + 3;
            $start = $block['dataStartRow'];
            $total = $block['dataEndRow'] + 1;
            $sheet->getStyle("A{$banner}:I{$total}")->applyFromArray([
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '808080']]],
            ]);
            $sheet->mergeCells("A{$banner}:I{$banner}");
            $sheet->mergeCells("A{$groupHeader}:A{$subheader}");
            $sheet->mergeCells("B{$groupHeader}:I{$groupHeader}");
            foreach (['B:C', 'D:E', 'F:G', 'H:I'] as $pair) {
                [$left, $right] = explode(':', $pair);
                $sheet->mergeCells("{$left}{$levelHeader}:{$right}{$levelHeader}");
            }
            foreach ([$banner => 'C00000', $levelHeader => '4994F5', $subheader => '001879'] as $row => $color) {
                $sheet->getStyle("A{$row}:I{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $color]],
                ]);
                $sheet->getRowDimension($row)->setRowHeight($row === $levelHeader ? 36 : 26);
            }
            $sheet->getStyle("A{$groupHeader}:A{$subheader}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFFFFF');
            $sheet->getStyle("A{$groupHeader}:A{$subheader}")->getFont()->getColor()->setRGB('000000');
            foreach (['C', 'E', 'G', 'I'] as $column) {
                $sheet->getStyle("{$column}{$start}:{$column}{$total}")->getNumberFormat()->setFormatCode('0.0%');
            }
            $sheet->getStyle("A{$total}:I{$total}")->getFont()->setBold(true);
        }

        return [];
    }

}
