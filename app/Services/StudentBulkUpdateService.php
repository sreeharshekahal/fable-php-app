<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * StudentBulkUpdateService
 *
 * Encapsulates business logic for performing bulk updates on student records,
 * retrieving assignment statistics, and listing students for assignment.
 */
class StudentBulkUpdateService
{
    /**
     * Return a paginated list of students for the division/bulk assignment UI.
     *
     * @param string      $orgId    Organisation UUID (required, tenant scope).
     * @param string|null $division Filter: 'none' = unassigned, or specific division string.
     * @param string|null $search   Search term applied after decryption (in-memory).
     * @param int         $page     1-indexed page number.
     * @param int         $pageSize Records per page (max 200 enforced here).
     *
     * @return array{count: int, next: int|null, previous: int|null, results: array}
     */
    public function listStudents(
        string $orgId,
        ?string $division = null,
        ?string $search = null,
        int $page = 1,
        int $pageSize = 50
    ): array {
        $pageSize = max(1, min($pageSize, 200));
        $page = max(1, $page);
        $offset = ($page - 1) * $pageSize;

        $query = DB::table('access_student as s')
            ->select([
                's.id',
                'u.first_name',
                'u.last_name',
                DB::raw("NULLIF(s.division, '') as division"),
            ])
            ->join('auth_user as u', 'u.id', '=', 's.user_id')
            ->where('s.organisation_id', $orgId);

        // Division filter ---------------------------------------------------
        if ($division === 'none') {
            // Unassigned: NULL or empty string
            $query->where(function ($q) {
                $q->whereNull('s.division')->orWhere('s.division', '');
            });
        } elseif ($division) {
            $query->whereRaw('UPPER(s.division) = ?', [strtoupper(trim($division))]);
        }

        // Names are encrypted; fetch all then filter/paginate in memory ------
        $all = $query->orderBy('u.id')->get();

        $mapped = $all->map(fn ($row) => $this->decryptStudent($row));

        if ($search !== null && $search !== '') {
            $term = strtolower(trim($search));
            $mapped = $mapped->filter(function ($row) use ($term) {
                return str_contains(strtolower($row['first_name']), $term)
                    || str_contains(strtolower($row['last_name']), $term);
            })->values();
        }

        // Sort by first_name ascending after decryption
        $mapped = $mapped->sortBy(fn ($r) => strtolower($r['first_name']))->values();

        $total = $mapped->count();
        $results = $mapped->slice($offset, $pageSize)->values();

        return [
            'count'    => $total,
            'next'     => ($offset + $pageSize < $total) ? $page + 1 : null,
            'previous' => $page > 1 ? $page - 1 : null,
            'results'  => $results->all(),
        ];
    }

    /**
     * Bulk update fields for multiple students within a transaction.
     *
     * @param string $orgId Organisation UUID
     * @param array $payload Payload containing student_ids and optional fields
     * @param array $updates Key-value pairs of sanitized fields to update
     * @return array{updated: int, updates: array, assignment_stats: array, message: string}
     */
    public function bulkUpdate(string $orgId, array $payload, array $updates = []): array
    {
        $studentIds = $payload['student_ids'] ?? [];

        if (empty($updates)) {
            foreach (['division', 'grade_id', 'group_id', 'group_hindi_id', 'group_marathi_id', 'gender', 'is_english_second_language', 'disability', 'date_of_birth'] as $field) {
                if (array_key_exists($field, $payload)) {
                    $updates[$field] = $payload[$field];
                }
            }
        }

        if (array_key_exists('division', $updates)) {
            $val = $updates['division'];
            $updates['division'] = ($val === null || trim((string) $val) === '') ? null : trim((string) $val);
        }

        if (empty($studentIds) || empty($updates)) {
            return [
                'updated' => 0,
                'updates' => [],
                'assignment_stats' => $this->getDivisionAssignmentStats($orgId),
                'message' => 'No students or updates provided',
            ];
        }

        return DB::transaction(function () use ($orgId, $studentIds, $updates) {
            $updated = 0;
            foreach (array_chunk($studentIds, 500) as $chunk) {
                $updated += DB::table('access_student')
                    ->where('organisation_id', $orgId)
                    ->whereIn('id', $chunk)
                    ->update($updates);
            }

            return [
                'updated' => $updated,
                'updates' => $updates,
                'assignment_stats' => $this->getDivisionAssignmentStats($orgId),
                'message' => 'Students updated successfully',
            ];
        });
    }

    /**
     * Return division assignment stats for an organisation.
     *
     * @param string $orgId
     * @return array{total: int, assigned: int, all_assigned: bool}
     */
    public function getDivisionAssignmentStats(string $orgId): array
    {
        $stats = DB::table('access_student')
            ->where('organisation_id', $orgId)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE
                    WHEN division IS NOT NULL AND division <> ''
                    THEN 1 ELSE 0
                END) as assigned
            ")
            ->first();

        $total = (int) ($stats ? $stats->total : 0);
        $assigned = (int) ($stats ? $stats->assigned : 0);

        return [
            'total' => $total,
            'assigned' => $assigned,
            'all_assigned' => $total > 0 && $assigned === $total,
        ];
    }

    /**
     * Decrypt first_name and last_name from a raw DB row.
     * Mirrors the decryption pattern used in StudentController.
     */
    private function decryptStudent(object $row): array
    {
        $firstName = $row->first_name ?? '';
        $lastName  = $row->last_name ?? '';

        try {
            if (!empty($firstName) && str_contains($firstName, 'eyJpdiI6')) {
                $firstName = Crypt::decryptString($firstName);
            }
            if (!empty($lastName) && str_contains($lastName, 'eyJpdiI6')) {
                $lastName = Crypt::decryptString($lastName);
            }
        } catch (\Exception $e) {
            Log::warning('StudentBulkUpdateService: failed to decrypt name for student ' . $row->id, [
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'id'         => $row->id,
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'division'   => $row->division ?: null,
        ];
    }
}
