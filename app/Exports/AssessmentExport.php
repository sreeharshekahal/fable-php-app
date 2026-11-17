<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Multi-sheet Excel workbook export for student assessments.
 *
 * Dynamically constructs the workbook containing:
 *  - Raw assessment records followed by adjacent student data and summary tabs per period
 *  - Assessment period overview summaries (BOY, MOY, EOY) when data is present
 *  - Cross-period comparison summary sheet for available periods
 */
class AssessmentExport implements WithMultipleSheets
{
    /**
     * Export query filters (e.g., organisation, language, type, date range).
     */
    protected array $filters;

    /**
     * Create a new AssessmentExport instance.
     *
     * @param array $filters
     */
    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    /**
     * Build the collection of worksheet instances for the export.
     *
     * Raw Data is followed by a Student Data / Data Summary pair for each
     * available period in BOY, MOY, EOY order, then the comparison sheet.
     *
     * @return array
     */
    public function sheets(): array
    {
        $sheets = [];

        // Keep all matching assessment records in the first tab.
        $sheets[] = new AssessmentPeriodStudentDataSheet($this->filters);

        // Keep each period's student data and summary adjacent.
        $availablePeriods = $this->getAvailablePeriods();
        foreach ($availablePeriods as $period) {
            $sheets[] = new AssessmentPeriodStudentDataSheet($this->filters, $period);
            $sheets[] = new AssessmentPeriodOverviewSheet($this->filters, $period);
        }

        // Compare only periods available under the selected filters.
        if (!empty($availablePeriods)) {
            $sheets[] = new AssessmentPeriodComparisonSheet($this->filters, $availablePeriods);
        }

        return $sheets;
    }

    /**
     * Retrieve the distinct assessment periods (BOY, MOY, EOY) that contain data matching the applied filters.
     *
     * Applies organization, language, assessment type, and date range filters
     * to match the scope of the exported data.
     *
     * @return array Canonical list of available period codes in chronological order (['BOY', 'MOY', 'EOY'])
     */
    private function getAvailablePeriods(): array
    {
        return (new AssessmentSummaryData($this->filters))->availablePeriods();
    }
}

