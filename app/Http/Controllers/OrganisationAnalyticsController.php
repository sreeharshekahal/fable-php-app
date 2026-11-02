<?php

namespace App\Http\Controllers;

use App\Models\Level;
use App\Models\Organisation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OrganisationAnalyticsController extends Controller
{
    public function groupWiseDistributionPerGrade(Request $request)
    {
        $validator = Validator::make($request->query(), [
            'organisation' => 'required|uuid',
            'level' => 'required|integer',
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }
        $filters = $validator->validated();
        $organisation = Organisation::findOrFail($filters['organisation']);
        $level = Level::where('rank', $filters['level'])->firstOrFail();

        $totals = DB::table('access_student')->where('organisation_id', $organisation->id)
            ->selectRaw('grade_id, COUNT(*) as total')->groupBy('grade_id')->pluck('total', 'grade_id');
        $groups = $organisation->groups()->where('level_id', $level->id)
            ->with('grade')->withCount('students')->orderBy('created')->get();

        // The legacy API returns one group per grade level.
        $result = [];
        foreach ($groups as $group) {
            $total = (int) $group->students_count;
            $denominator = (int) ($totals[$group->grade_id] ?? 0);
            $result[(string) $group->grade->level] = [
                'grade' => $group->grade_id,
                'group' => $group->id,
                'grade__level' => $group->grade->level,
                'group__title' => $group->title,
                'total' => $total,
                'percentage' => $denominator === 0 ? 0.0 : $total / $denominator * 100,
            ];
        }
        return response()->json(array_values($result));
    }
}
