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
        $organisationCount = DB::table('organisation_organisation')->count();
        $studentCount = DB::table('access_student')->count();
        $teacherCount = DB::table('access_teacher')->count();

        $start = $request->get('start');
        $end = $request->get('end');

        if($start && $end){
           $start = explode('-',$start);
           $startMonth = $start[0];
           $startYear = $start[1];
           $startDate = Carbon::createFromFormat('Y-m', "$startYear-$startMonth");

           $end = explode('-',$end);
           $endMonth = $end[0];
           $endYear = $end[1];
           $endDate = Carbon::createFromFormat('Y-m', "$endYear-$endMonth");

            $organisationCount = DB::table('organisation_organisation')
            ->whereBetween('created', [$startDate, $endDate])
            ->get()->count();

            $studentCount = DB::table('access_student')
            ->join('auth_user', 'auth_user.id', '=', 'access_student.user_id')
            ->whereBetween('date_joined', [$startDate, $endDate])
            ->get()->count();

            $teacherCount = DB::table('access_teacher')
            ->join('auth_user', 'auth_user.id', '=', 'access_teacher.user_id')
            ->whereBetween('date_joined', [$startDate, $endDate])
            ->get()->count();
        }
        $data = ['organisationCount' => $organisationCount, 'studentCount' => $studentCount, 'teacherCount' => $teacherCount];
        return response()->json($data, 200);
    }
}
