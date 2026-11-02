<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BulkDeleteUsersService
{
    public function delete(array $teachers, array $students): void
    {
        DB::transaction(function () use ($teachers, $students) {
            $userIds = DB::table('access_teacher')->whereIn('id', $teachers)->pluck('user_id')
                ->merge(DB::table('access_student')->whereIn('id', $students)->pluck('user_id'))->unique();
            // Delete every profile of the selected users, matching Django User deletion.
            $teacherIds = DB::table('access_teacher')->whereIn('user_id', $userIds)->pluck('id');
            $studentIds = DB::table('access_student')->whereIn('user_id', $userIds)->pluck('id');
            $assessmentIds = DB::table('assessment_assessment')->whereIn('student_id', $studentIds)
                ->orWhereIn('conducted_by_id', $teacherIds)->pluck('id');

            foreach (['assessment_assessment_checklists', 'assessment_errorwords', 'assessment_assessmentstudentgrouphistory'] as $table) {
                DB::table($table)->whereIn('assessment_id', $assessmentIds)->delete();
            }
            DB::table('assessment_assessment')->whereIn('id', $assessmentIds)->delete();
            foreach (['access_student_languages', 'organisation_studentpromotionhistory', 'organisation_studentgrouphistory'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->whereIn('student_id', $studentIds)->delete();
                }
            }
            foreach (['access_teacher_languages', 'access_teacher_groups'] as $table) {
                DB::table($table)->whereIn('teacher_id', $teacherIds)->delete();
            }
            DB::table('access_student')->whereIn('id', $studentIds)->delete();
            DB::table('access_teacher')->whereIn('id', $teacherIds)->delete();
            DB::table('authtoken_token')->whereIn('user_id', $userIds)->delete();
            foreach (['auth_user_groups', 'auth_user_user_permissions', 'django_admin_log'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->whereIn('user_id', $userIds)->delete();
                }
            }
            DB::table('auth_user')->whereIn('id', $userIds)->delete();
        });
    }
}
