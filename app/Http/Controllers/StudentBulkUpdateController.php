<?php

namespace App\Http\Controllers;

use App\Http\Requests\StudentBulkUpdateRequest;
use App\Services\StudentBulkUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * HTTP controller for bulk updating student records,
 * retrieving assignment statistics, and listing students for assignment.
 */
class StudentBulkUpdateController extends Controller
{
    public function __construct(private readonly StudentBulkUpdateService $service) {}

    /**
     * Bulk update student records (division, grade, group, etc.).
     */
    public function bulkUpdate(StudentBulkUpdateRequest $request): JsonResponse
    {
        $orgId = $this->resolveOrgIdFromRequest($request);
        if (!$orgId) {
            return response()->json(['error' => 'organisation param is required or could not be determined.'], 422);
        }

        return response()->json(
            $this->service->bulkUpdate($orgId, $request->validated(), $request->getUpdateAttributes())
        );
    }

    /**
     * Return division assignment stats for an organisation.
     */
    public function getStats(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrgIdFromRequest($request);
        if (!$orgId) {
            return response()->json(['error' => 'organisation param is required.'], 422);
        }

        $stats = $this->service->getDivisionAssignmentStats($orgId);

        return response()->json($stats, 200);
    }

    /**
     * Return a paginated list of students for the assignment UI.
     */
    public function listStudents(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrgIdFromRequest($request);
        if (!$orgId) {
            return response()->json(['error' => 'organisation param is required.'], 422);
        }

        $filters = $request->validate([
            'division' => 'nullable|string|max:255',
            'search' => 'nullable|string|max:255',
            'page' => 'sometimes|integer|min:1',
            'page_size' => 'sometimes|integer|min:1|max:200',
        ]);

        $data = $this->service->listStudents(
            orgId:    $orgId,
            division: $filters['division'] ?? null,
            search:   $filters['search'] ?? null,
            page:     (int) ($filters['page'] ?? 1),
            pageSize: (int) ($filters['page_size'] ?? 50),
        );

        return response()->json($data);
    }

    /**
     * Resolve and sanitize the organisation UUID from the request.
     * Checks query/body params 'organisation' and 'organisation_id',
     * then falls back to authenticated admin or teacher user records.
     */
    private function resolveOrgIdFromRequest(Request $request): ?string
    {
        $raw = $request->input(
            'organisation',
            $request->input('organisation_id', $request->query('organisation', $request->query('organisation_id')))
        );

        if ($raw) {
            return is_string($raw) ? $this->sanitizeUuid($raw) : null;
        }

        $user = auth()->user();
        if (!$user) {
            return null;
        }

        $adminOrg = DB::table('access_admin')
            ->where('user_id', $user->id)
            ->value('organisation_id');

        if ($adminOrg) {
            return $this->sanitizeUuid($adminOrg);
        }

        return $this->sanitizeUuid(
            DB::table('access_teacher')
                ->where('user_id', $user->id)
                ->value('organisation_id')
        );
    }
}
