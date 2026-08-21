<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class Dashboard extends Controller
{
    public function index(){

    }

    public function getCounts(Request $request)
    {
        $user = auth()->user() ?: $request->get('authenticated_user');
        $teacher = null;
        if ($user) {
            $teacher = DB::table('access_teacher')->where('user_id', $user->id)->first();
        }

        $start = $request->get('start');
        $end = $request->get('end');
        $startDate = null;
        $endDate = null;

        if ($start && $end) {
            $startArr = explode('-', $start);
            $startMonth = $startArr[0];
            $startYear = $startArr[1];
            $startDate = Carbon::createFromFormat('Y-m', "$startYear-$startMonth");

            $endArr = explode('-', $end);
            $endMonth = $endArr[0];
            $endYear = $endArr[1];
            $endDate = Carbon::createFromFormat('Y-m', "$endYear-$endMonth");
        }

        if ($teacher) {
            // 1. Organisation Count
            $orgQuery = DB::table('organisation_organisation')
                ->where('id', $teacher->organisation_id);
            if ($startDate && $endDate) {
                $orgQuery->whereBetween('created', [$startDate, $endDate]);
            }
            $organisationCount = $orgQuery->count();

            // 2. Student Count (filtered by organisation, teacher's groups, and division)
            $studentQuery = DB::table('access_student as s')
                ->where('s.organisation_id', $teacher->organisation_id);

            $studentQuery->where(function ($q) use ($teacher) {
                $q->whereExists(function ($sub) use ($teacher) {
                    $sub->select(DB::raw(1))
                        ->from('access_teacher_groups as tg')
                        ->where('tg.teacher_id', $teacher->id)
                        ->where(function ($inner) {
                            $inner->whereColumn('tg.group_id', 's.group_id')
                                ->orWhereColumn('tg.group_id', 's.group_hindi_id')
                                ->orWhereColumn('tg.group_id', 's.group_marathi_id');
                        });
                });
            });

            if ($teacher->division) {
                $teacherDivisions = array_map('trim', explode(',', $teacher->division));
                $teacherDivisions = array_filter(array_map('strtoupper', $teacherDivisions));
                if (!empty($teacherDivisions)) {
                    $studentQuery->where(function ($q) use ($teacherDivisions) {
                        $q->whereIn(DB::raw('UPPER(s.division)'), $teacherDivisions)
                          ->orWhereNull('s.division')
                          ->orWhere('s.division', '');
                    });
                }
            }

            if ($startDate && $endDate) {
                $studentQuery->join('auth_user as u', 'u.id', '=', 's.user_id')
                    ->whereBetween('u.date_joined', [$startDate, $endDate]);
            }
            $studentCount = $studentQuery->count();

            // 3. Teacher Count (all teachers within same organisation)
            $teacherQuery = DB::table('access_teacher as t')
                ->where('t.organisation_id', $teacher->organisation_id);
            if ($startDate && $endDate) {
                $teacherQuery->join('auth_user as u', 'u.id', '=', 't.user_id')
                    ->whereBetween('u.date_joined', [$startDate, $endDate]);
            }
            $teacherCount = $teacherQuery->count();
        } else {
            // Global counts (for non-teachers or static token)
            $orgQuery = DB::table('organisation_organisation');
            $studentQuery = DB::table('access_student');
            $teacherQuery = DB::table('access_teacher');

            if ($startDate && $endDate) {
                $orgQuery->whereBetween('created', [$startDate, $endDate]);

                $studentQuery->join('auth_user as u', 'u.id', '=', 'access_student.user_id')
                    ->whereBetween('u.date_joined', [$startDate, $endDate]);

                $teacherQuery->join('auth_user as u', 'u.id', '=', 'access_teacher.user_id')
                    ->whereBetween('u.date_joined', [$startDate, $endDate]);
            }

            $organisationCount = $orgQuery->count();
            $studentCount = $studentQuery->count();
            $teacherCount = $teacherQuery->count();
        }

        $data = [
            'organisationCount' => $organisationCount,
            'studentCount' => $studentCount,
            'teacherCount' => $teacherCount
        ];

        return response()->json($data, 200);
    }
}
