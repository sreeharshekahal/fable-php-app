<?php

namespace App\Http\Controllers;

use App\Models\Grade;
use App\Models\Organisation;
use App\Models\OrganisationGroup;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AssessmentCountController extends Controller
{
    public function organisationWise(Request $request)
    {
        $validator = Validator::make($request->query(), [
            'organisation' => 'required|uuid',
            'from' => 'required_with:to|date_format:d-m-Y',
            'to' => 'required_with:from|date_format:d-m-Y',
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }
        $filters = $validator->validated();
        $organisation = Organisation::findOrFail($filters['organisation']);

        $query = DB::table('assessment_assessment as a')
            ->where('a.organisation_id', $organisation->id);
        if (isset($filters['from'], $filters['to'])) {
            $query->where('a.created', '>=', Carbon::createFromFormat('!d-m-Y', $filters['from']))
                ->where('a.created', '<', Carbon::createFromFormat('!d-m-Y', $filters['to'])->addDay());
        }

        $rows = $query->selectRaw('DATE(a.created) as date, COUNT(a.id) as total')
            ->groupByRaw('DATE(a.created)')
            ->orderBy('date')->get();

        return response()->json($rows->map(function ($row) use ($organisation) {
            return [
                'organisation' => $organisation->id,
                'organisation__title' => $organisation->title,
                'date' => $row->date,
                'total' => (int) $row->total,
            ];
        }));
    }

    public function gradeWise(Request $request)
    {
        $validator = Validator::make($request->query(), [
            'organisation' => 'required|uuid',
            'grade' => 'required|uuid',
            'from' => 'required_with:to|date_format:d-m-Y',
            'to' => 'required_with:from|date_format:d-m-Y',
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }
        $filters = $validator->validated();
        $organisation = Organisation::findOrFail($filters['organisation']);
        $grade = Grade::findOrFail($filters['grade']);

        $query = DB::table('assessment_assessment as a')
            ->where('a.organisation_id', $organisation->id)
            ->where('a.grade_id', $grade->id);
        if (isset($filters['from'], $filters['to'])) {
            $query->where('a.created', '>=', Carbon::createFromFormat('!d-m-Y', $filters['from']))
                ->where('a.created', '<', Carbon::createFromFormat('!d-m-Y', $filters['to'])->addDay());
        }

        $rows = $query->selectRaw('DATE(a.created) as date, COUNT(a.id) as total')
            ->groupByRaw('DATE(a.created)')
            ->orderBy('date')->get();

        return response()->json($rows->map(function ($row) use ($grade) {
            return [
                'grade' => $grade->id,
                'grade__level' => $grade->level,
                'date' => $row->date,
                'total' => (int) $row->total,
            ];
        }));
    }

    public function groupWise(Request $request)
    {
        $validator = Validator::make($request->query(), [
            'organisation' => 'required|uuid',
            'grade' => 'required|uuid',
            'group' => 'required|uuid',
            'from' => 'required_with:to|date_format:d-m-Y',
            'to' => 'required_with:from|date_format:d-m-Y',
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }
        $filters = $validator->validated();
        $organisation = Organisation::findOrFail($filters['organisation']);
        $grade = Grade::findOrFail($filters['grade']);
        $group = OrganisationGroup::findOrFail($filters['group']);

        $query = DB::table('assessment_assessment as a')
            ->where('a.organisation_id', $organisation->id)
            ->where('a.grade_id', $grade->id)
            ->where('a.group_id', $group->id);
        if (isset($filters['from'], $filters['to'])) {
            $query->where('a.created', '>=', Carbon::createFromFormat('!d-m-Y', $filters['from']))
                ->where('a.created', '<', Carbon::createFromFormat('!d-m-Y', $filters['to'])->addDay());
        }

        $rows = $query->selectRaw('DATE(a.created) as date, COUNT(a.id) as total')
            ->groupByRaw('DATE(a.created)')
            ->orderBy('date')->get();

        return response()->json($rows->map(function ($row) {
            return ['date' => $row->date, 'total' => (int) $row->total];
        }));
    }
}
