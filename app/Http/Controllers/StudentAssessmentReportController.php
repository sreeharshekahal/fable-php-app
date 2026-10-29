<?php

namespace App\Http\Controllers;

use App\Services\StudentAssessmentReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StudentAssessmentReportController extends Controller
{
    protected StudentAssessmentReportService $reportService;

    public function __construct(StudentAssessmentReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    /**
     * Get Student Assessment Report Data (JSON).
     *
     * Supports:
     * - Route param {id} or query params: student_id, student, student__id
     * - Filters:
     *   - language / languages__name: English, Hindi, Marathi, etc.
     *   - assessment_period / period: ALL, BOY, MOY, EOY (default: ALL)
     *   - retell_and_fluency / retell_fluency: true/false (default: true)
     *   - trend_analysis: true/false (default: true)
     *   - audio_link / audio: true/false (default: true)
     *   - progress_monitoring: true/false (default: true)
     *   - benchmarking: true/false (default: true)
     *   - graph_type: both, progress_monitoring, benchmarking, none (default: both)
     */
    public function getReport(Request $request, ?string $id = null)
    {
        $studentId = $id ?: ($request->input('student_id') ?:  $request->input('student__id'));

        if (!$studentId) {
            return response()->json([
                'error' => 'Student ID is required',
                'message' => 'Please provide student ID via route parameter or student_id query parameter',
            ], 400);
        }

        try {
            $reportData = $this->reportService->buildReport($studentId, $request);

            return response()->json($reportData, 200, [], JSON_PRESERVE_ZERO_FRACTION);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => 'Student not found',
                'message' => $e->getMessage(),
            ], 404);
        } catch (\Exception $e) {
            Log::error('StudentAssessmentReportController error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'Failed to generate assessment report',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
