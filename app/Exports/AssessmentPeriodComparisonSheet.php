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
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * "Comparison of Period Summary" sheet.
 *
 * Layout:
 *   Left side: Table
 *     - Column A: Grade label (e.g. Grade 1, Grade 2), merged vertically across 6 rows per grade.
 *     - For each Period (e.g. BOY, MOY, EOY), 5 columns:
 *         1. Number of Students
 *         2. Descriptor (with score range, e.g. "प्रवीण (17 & above)" or "Benchmarking (17 & above)")
 *         3. Percentage
 *         4. Mean accuracy percent
 *         5. Percent =>2 retell
 *       If no data for that period in a grade: Red banner says "BOY - Not done" and rows below are shaded grey.
 *   Right side: Visual Charts
 *     - One 100% stacked column chart per Grade × Period combination that has data.
 *     - Placed side-by-side horizontally (4 columns wide each, aligned with the grade rows).
 *     - Legend at top with the 4 benchmark levels and score ranges.
 */
class AssessmentPeriodComparisonSheet implements FromArray, WithTitle, WithCharts, WithColumnWidths, WithStyles, WithStrictNullComparison
{
    /**
     * Export query filters (organisation, language, type, date range).
     */
    protected array $filters;

    private const CHART_HEIGHT_ROWS = 18;

    /**
     * Canonical list of available period codes (e.g. ['BOY', 'MOY', 'EOY']).
     *
     * @var array<string>
     */
    protected array $periods;

    /**
     * Metadata tracking for styles() table blocks.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $gradeBlocks = [];

    /**
     * Metadata tracking for charts() positioning and data references.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $chartItems = [];

    /**
     * Create a new AssessmentPeriodComparisonSheet instance.
     *
     * @param array $filters
     * @param array $periods
     */
    public function __construct(array $filters, array $periods)
    {
        $this->filters = $filters;
        $this->periods = array_values($periods);
    }

    /**
     * Worksheet tab title.
     *
     * @return string
     */
    public function title(): string
    {
        return implode(', ', $this->periods) . ' Comparison';
    }

    /**
     * Column widths mapping for table columns and chart visual slots.
     *
     * @return array
     */
    public function columnWidths(): array
    {
        $widths = ['A' => 14, 'B' => 16]; // Grade label column

        // Allocate widths for each period's 5 columns
        $colIdx = 3;
        foreach ($this->periods as $p) {
            $widths[$this->colLetter($colIdx++)] = 18; // Number of Students
            $widths[$this->colLetter($colIdx++)] = 26; // Descriptor
            $widths[$this->colLetter($colIdx++)] = 10; // %
            $widths[$this->colLetter($colIdx++)] = 22; // Mean accuracy percent
            $widths[$this->colLetter($colIdx++)] = 22; // Percent =>2 retell
        }

        // Add widths for chart columns (4 columns per chart, e.g. 10 charts max)
        for ($c = 0; $c < 40; $c++) {
            $widths[$this->colLetter($colIdx++)] = 14;
        }

        return $widths;
    }

    /**
     * Build the spreadsheet rows array for cross-period grade comparison.
     *
     * Structures each grade block with:
     *  - Row 1: Banner row ("{Period} Assessment Overview" or "{Period} - Not done")
     *  - Row 2: Header row (Number of Students, Descriptor, %, Mean accuracy percent, Percent =>2 retell)
     *  - Rows 3-6: 4 canonical benchmark levels (Benchmarking, Fluency Intervention, Intensive Intervention, High Priority)
     *
     * @return array
     */
    public function array(): array
    {
        $periods = $this->periods;
        $summaryData = $this->summaryData();
        $groups = $summaryData->groups($periods);

        $rows              = [];
        $this->gradeBlocks = [];
        $this->chartItems  = [];

        if ($groups->isEmpty()) {
            return [['No comparison data available', '', '', '', '']];
        }

        $currentRow = 1;

        foreach ($groups as $group) {
            $gradeDisplay = $summaryData->gradeDisplay($group->first());
            $gradeStartRow = $currentRow;
            // Without a division filter, aggregate the whole grade into an overall
            // comparison. A selected division uses the already-filtered population.
            $hasDivision = isset($this->filters['division']) && $this->filters['division'] !== '';
            $divisions = $hasDivision
                ? $group->groupBy(fn ($record) => trim((string) ($record->division ?? '')))
                    ->sortKeys(SORT_NATURAL | SORT_FLAG_CASE)
                : collect(['All Divisions' => $group]);
            // Each division needs a banner, column headers and four intervention rows.
            // Reserve at least the fixed chart height for grades with fewer divisions.
            $gradeDataEndRow = $gradeStartRow + $divisions->count() * 6 - 1;
            $gradeEndRow = $gradeStartRow + max($divisions->count() * 6, self::CHART_HEIGHT_ROWS) - 1;
            foreach ($divisions as $division => $divisionRecords) {
                $group = $divisionRecords;
                $division = (string) $division === '' ? 'Unassigned' : (string) $division;
                $divisionLabel = $hasDivision ? 'Division ' . $division : '';

                $blockStartRow = $currentRow;
                $bannerRow     = $currentRow;
                $headerRow     = $currentRow + 1;
                $dataStartRow  = $currentRow + 2;
                $dataEndRow    = $currentRow + 5;

                // Prepare columns for each period
                $periodData = [];

                foreach ($periods as $pIdx => $period) {
                    $startColIndex = 3 + ($pIdx * 5);

                    $records = $group->where('assessment_period', $period);
                    $summary = $summaryData->summarize($records);
                    $totalStudents = $summary['totalStudents'];
                    $levelMetrics = $summary['levelMetrics'];
                    $hasData = $totalStudents > 0;

                    $periodData[] = [
                        'period'        => $period,
                        'startColIndex' => $startColIndex,
                        'startCol'      => $this->colLetter($startColIndex),
                        'endCol'        => $this->colLetter($startColIndex + 4),
                        'hasData'       => $hasData,
                        'totalStudents' => $totalStudents,
                        'levelMetrics'  => $levelMetrics,
                    ];

                    // If this Grade × Period has data, register a chart
                    if ($hasData) {
                        $formattedGradeTitle = $hasDivision && $divisionLabel !== ''
                            ? $gradeDisplay . ' - ' . $divisionLabel
                            : $gradeDisplay;

                        $this->chartItems[] = [
                            'gradeTitle'    => $formattedGradeTitle,
                            'period'        => $period,
                            'chartTopRow' => $gradeStartRow,
                            'chartBottomRow' => $gradeEndRow + 1,
                            'descCol'       => $this->colLetter($startColIndex + 1), // Descriptor column for this period.
                            'pctCol'        => $this->colLetter($startColIndex + 2),  // Percentage column for this period.
                            'headerRow'     => $headerRow,
                            'dataStartRow'  => $dataStartRow,
                            'dataEndRow'    => $dataEndRow,
                        ];
                    }
                }

                // Build Row 1 of grade block: Banner Row
                $rowBanner = [$currentRow === $gradeStartRow ? $gradeDisplay : '', $divisionLabel];
                foreach ($periodData as $pd) {
                    $heading = $hasDivision ? 'Division-Wise Comparison' : 'Assessment Overview';
                    $text = $pd['hasData']
                        ? "{$gradeDisplay} {$pd['period']} {$heading}"
                        : "{$pd['period']} - Not done";
                    $rowBanner[] = $text;
                    $rowBanner[] = '';
                    $rowBanner[] = '';
                    $rowBanner[] = '';
                    $rowBanner[] = '';
                }
                $rows[] = $rowBanner;

                // Build Row 2 of grade block: Header Row
                $rowHeader = ['', ''];
                foreach ($periodData as $pd) {
                    if ($pd['hasData']) {
                        $rowHeader[] = 'Number of Students';
                        $rowHeader[] = 'Descriptor';
                        $rowHeader[] = '%';
                        $rowHeader[] = 'Mean accuracy percent';
                        $rowHeader[] = 'Percent =>2 retell';
                    } else {
                        $rowHeader[] = '';
                        $rowHeader[] = '';
                        $rowHeader[] = '';
                        $rowHeader[] = '';
                        $rowHeader[] = '';
                    }
                }
                $rows[] = $rowHeader;

                // Build Rows 3-6: The 4 Benchmark Levels
                $canonicalLevels = [
                    'Benchmarking',
                    'Fluency Intervention',
                    'Intensive Intervention',
                    'High Priority',
                ];

                foreach ($canonicalLevels as $lvlKey) {
                    $rowLevel = ['', ''];
                    foreach ($periodData as $pd) {
                        if ($pd['hasData']) {
                            $m     = $pd['levelMetrics'][$lvlKey];
                            $cnt   = $m['count'];
                            $tot   = $pd['totalStudents'];
                            $pct   = $tot > 0 ? round($cnt / $tot * 100, 1) : 0.0;

                            // Descriptor string with bounds
                            $pInfo = $m['param'];
                            $desc  = $pInfo['descriptor'];

                            $rowLevel[] = $cnt;
                            $rowLevel[] = $desc;
                            $rowLevel[] = $pct;
                            // Both metrics use this intervention group's count, not the
                        // whole division population; empty groups display zero.
                        $rowLevel[] = $cnt > 0 ? round($m['acc_sum'] / $cnt, 1) : 0.0;
                            $rowLevel[] = $cnt > 0 ? round($m['retell_ge2'] / $cnt * 100, 1) : 0.0;
                        } else {
                            $rowLevel[] = '';
                            $rowLevel[] = '';
                            $rowLevel[] = '';
                            $rowLevel[] = '';
                            $rowLevel[] = '';
                        }
                    }
                    $rows[] = $rowLevel;
                }

                $this->gradeBlocks[] = [
                    'gradeTitle'   => $gradeDisplay,
                    'gradeStartRow' => $gradeStartRow,
                    'gradeEndRow' => $gradeEndRow,
                    'gradeDataEndRow' => $gradeDataEndRow,
                    'startRow'     => $blockStartRow,
                    'endRow'       => $dataEndRow,
                    'bannerRow'    => $bannerRow,
                    'headerRow'    => $headerRow,
                    'dataStartRow' => $dataStartRow,
                    'dataEndRow'   => $dataEndRow,
                    'periods'      => $periodData,
                ];

                $currentRow = $dataEndRow + 1;
            }
            // Preserve enough space for the fixed chart height, including small grades.
            while (count($rows) < $gradeEndRow) {
                $rows[] = [''];
            }
            $currentRow = $gradeEndRow + 1;
        }

        return $rows;
    }

    protected function summaryData(): AssessmentSummaryData
    {
        return new AssessmentSummaryData($this->filters);
    }

    /**
     * Build side-by-side 100% stacked column charts to the right of the comparison table.
     *
     * Generates one chart per Grade × Period combination with data, stacking
     * the 4 benchmark levels from bottom (Benchmarking) to top (High Priority).
     *
     * @return array<\PhpOffice\PhpSpreadsheet\Chart\Chart>
     */
    public function charts(): array
    {
        $charts    = [];
        $sheetName = $this->title();

        // Charts start immediately to the right of the comparison table
        // Table width: Grade + Division + 5 columns per period
        $startColIndex = 3 + (count($this->periods) * 5); // First column after the period tables
        $chartWidth    = 4; // 4 columns wide per chart (e.g. L to O, P to S, etc.)
        $gradeChartSlots = []; // Restart the horizontal chart positions for each grade.

        foreach ($this->chartItems as $idx => $item) {
            $gradeTitle = $item['gradeTitle'];
            $period     = $item['period'];
            $chartTitle = "{$gradeTitle} {$period} Summary";
            $chartName   = 'chart_' . preg_replace('/\W+/', '_', $gradeTitle) . '_' . strtolower($item['period']) . "_{$idx}";

            $descCol   = $item['descCol'];
            $pctCol    = $item['pctCol'];
            $headerRow = $item['headerRow'];
            $rBM       = $item['dataStartRow'];
            $rFI       = $item['dataStartRow'] + 1;
            $rII       = $item['dataStartRow'] + 2;
            $rHP       = $item['dataStartRow'] + 3;

            // Category: single "%" header from that period's table
            $categories = [
                new DataSeriesValues(
                    DataSeriesValues::DATASERIES_TYPE_STRING,
                    "'{$sheetName}'!\${$pctCol}\${$headerRow}",
                    null,
                    1
                ),
            ];

            // 4 Series in bottom-to-top stacking order:
            // 1. Benchmarking (bottom, soft pale blue)
            // 2. Fluency Intervention (sky blue)
            // 3. Intensive Intervention (medium blue)
            // 4. High Priority (top, dark navy blue)
            $s1_label = new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                "'{$sheetName}'!\${$descCol}\${$rBM}",
                null,
                1
            );
            $s1_val = (new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                "'{$sheetName}'!\${$pctCol}\${$rBM}",
                null,
                1
            ))->setFillColor('C6D9F1');

            $s2_label = new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                "'{$sheetName}'!\${$descCol}\${$rFI}",
                null,
                1
            );
            $s2_val = (new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                "'{$sheetName}'!\${$pctCol}\${$rFI}",
                null,
                1
            ))->setFillColor('5B9BD5');

            $s3_label = new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                "'{$sheetName}'!\${$descCol}\${$rII}",
                null,
                1
            );
            $s3_val = (new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                "'{$sheetName}'!\${$pctCol}\${$rII}",
                null,
                1
            ))->setFillColor('2E75B6');

            $s4_label = new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                "'{$sheetName}'!\${$descCol}\${$rHP}",
                null,
                1
            );
            $s4_val = (new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                "'{$sheetName}'!\${$pctCol}\${$rHP}",
                null,
                1
            ))->setFillColor('0F3B66');

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

            // Keep every chart inside its grade's row band, with periods/divisions
            // side by side. The next grade reuses the same chart columns below.
            $topRow = $item['chartTopRow'];
            $bottomRow = $topRow + self::CHART_HEIGHT_ROWS;
            $slot = $gradeChartSlots[$topRow] ?? 0;
            $gradeChartSlots[$topRow] = $slot + 1;
            $tlColIdx = $startColIndex + ($slot * $chartWidth);
            $brColIdx = $tlColIdx + $chartWidth - 1;

            $tlColLetter = $this->colLetter($tlColIdx);
            $brColLetter = $this->colLetter($brColIdx);

            $chart->setTopLeftPosition("{$tlColLetter}{$topRow}");
            $chart->setBottomRightPosition("{$brColLetter}{$bottomRow}");

            $charts[] = $chart;
        }

        return $charts;
    }

    /**
     * Apply styles to the comparison table and period sections.
     *
     * Merges Grade cells across rows, applies red banners for active periods,
     * grey shading for "Not done" periods, and sets 0.0% formatting for percentage columns.
     *
     * @param Worksheet $sheet
     * @return array
     */
    public function styles(Worksheet $sheet): array
    {
        // Overall reports show grade and period metrics without a division column.
        // Keep its underlying coordinates stable for merged cells and chart references.
        $hasDivision = isset($this->filters['division']) && $this->filters['division'] !== '';
        $sheet->getColumnDimension('B')->setVisible($hasDivision);

        foreach ($this->gradeBlocks as $b) {
            $startRow     = $b['startRow'];
            $endRow       = $b['endRow'];
            $bannerRow    = $b['bannerRow'];
            $headerRow    = $b['headerRow'];
            $dataStartRow = $b['dataStartRow'];
            $dataEndRow   = $b['dataEndRow'];

            // Merge the Grade label across its table rows only, excluding chart padding.
            $gradeStart = $b['gradeStartRow'];
            $gradeEnd = $b['gradeDataEndRow'];
            if ($startRow === $gradeStart) {
                $sheet->mergeCells("A{$gradeStart}:A{$gradeEnd}");
            }
            $sheet->mergeCells("B{$startRow}:B{$endRow}");
            $sheet->getStyle("A{$startRow}:B{$endRow}")->applyFromArray([
                'font'      => [
                    'bold'  => true,
                    'color' => ['rgb' => '000000'],
                    'size'  => 11,
                ],
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

            // 2. Periods styling
            foreach ($b['periods'] as $pd) {
                $startCol = $pd['startCol'];
                $endCol   = $pd['endCol'];
                $hasData  = $pd['hasData'];

                // Red Banner Row
                $sheet->mergeCells("{$startCol}{$bannerRow}:{$endCol}{$bannerRow}");
                $sheet->getStyle("{$startCol}{$bannerRow}:{$endCol}{$bannerRow}")->applyFromArray([
                    'font'      => [
                        'bold'  => true,
                        'color' => ['rgb' => 'FFFFFF'],
                        'size'  => 11,
                    ],
                    'fill'      => [
                        'fillType'   => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'C00000'], // Solid red
                    ],
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
                $sheet->getRowDimension($bannerRow)->setRowHeight(24);

                if ($hasData) {
                    // Column Headers (5 columns)
                    $sheet->getStyle("{$startCol}{$headerRow}:{$endCol}{$headerRow}")->applyFromArray([
                        'font'      => [
                            'bold'  => true,
                            'color' => ['rgb' => '000000'],
                            'size'  => 10,
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
                    $sheet->getRowDimension($headerRow)->setRowHeight(24);

                    // Data Rows (Rows 3 to 6)
                    $sheet->getStyle("{$startCol}{$dataStartRow}:{$endCol}{$dataEndRow}")->applyFromArray([
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

                    // Format the period's percentage column.
                    for ($r = $dataStartRow; $r <= $dataEndRow; $r++) {
                        foreach ([2, 3, 4] as $offset) {
                            $column = $this->colLetter($pd['startColIndex'] + $offset);
                            $sheet->getStyle("{$column}{$r}")->getNumberFormat()->setFormatCode('0.0"%"');
                        }
                        $sheet->getRowDimension($r)->setRowHeight(20);
                    }
                } else {
                    // "Not done" -> Fill the 5 columns below with grey
                    $sheet->getStyle("{$startCol}{$headerRow}:{$endCol}{$dataEndRow}")->applyFromArray([
                        'fill'    => [
                            'fillType'   => Fill::FILL_SOLID,
                            'startColor' => ['rgb' => 'A6A6A6'], // Grey shading
                        ],
                        'borders' => [
                            'allBorders' => [
                                'borderStyle' => Border::BORDER_THIN,
                                'color'       => ['rgb' => '000000'],
                            ],
                        ],
                    ]);
                }
            }
        }

        // Uniform row heights make the fixed row
        foreach ($this->gradeBlocks as $block) {
            for ($row = $block['startRow']; $row <= $block['gradeEndRow']; $row++) {
                $sheet->getRowDimension($row)->setRowHeight(24);
            }
        }

        return [];
    }

    /**
     * Convert a 1-based column index to its Excel column letter (e.g., 1 -> 'A', 2 -> 'B', 27 -> 'AA').
     *
     * @param int $n 1-based column index
     * @return string
     */
    protected function colLetter(int $n): string
    {
        $letter = '';
        while ($n > 0) {
            $mod    = ($n - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $n      = intdiv($n - 1, 26);
        }
        return $letter;
    }
}
