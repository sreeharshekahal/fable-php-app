<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\User;
use App\Http\Controllers\Dashboard;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AssessmentCountController;
use App\Http\Controllers\OrganisationAnalyticsController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\OrganisationController;
use App\Http\Controllers\OrganisationGroupController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\WordController;
use App\Http\Controllers\GroupingParameterController;
use App\Http\Controllers\AccuracyController;
use App\Http\Controllers\BenchmarkTemplateController;
use App\Http\Controllers\GradeController;
use App\Http\Controllers\PassageController;
use App\Http\Controllers\StudentBulkUpdateController;
use App\Http\Controllers\AuthPasswordController;
use App\Http\Controllers\AuthSessionController;
use App\Http\Controllers\StudentAssessmentReportController;


/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware(['token.auth'])->group(function () {
    Route::put('/updateStudent/{id}', [User::class, 'updateStudent']);
    Route::put('/updateTeacher/{id}', [User::class, 'updateTeacher']);
    Route::get('/getCounts', [Dashboard::class, 'getCounts']);
    Route::get('/getTeacher/{id}', [User::class, 'getTeacher']);
});

Route::post('/saveAudio', [User::class, 'saveAudio']);
Route::get('/getAudio', [User::class, 'getAudio']);
Route::get('/getStudentPic/{id}', [User::class, 'getStudentPic']);
Route::post('/saveDeviceDetails/{id}', [User::class, 'saveDeviceDetails']);
Route::get('/allOrgUsersCount', [User::class, 'allOrgUsersCount']);
Route::get('/organisationUsersCount/{id}', [User::class, 'organisationUsersCount']);
Route::get('/organisations/{id}/groups', [OrganisationGroupController::class, 'getOrganisationGroups']);
Route::post('/bulk-upload-student', [User::class, 'bulkUploadStudent']);
Route::post('/bulk-upload-teacher', [User::class, 'bulkUploadTeacher']);
Route::get('/passagePDF/{id}', [User::class, 'getPassagePDFNew']);
Route::post('/add-student', [User::class, 'addStudent']);
Route::post('/edit-student/{id}', [User::class, 'editStudent']);
Route::post('/add-teacher', [User::class, 'addTeacher']);

// Auth & Password Reset endpoints (Django rest-auth compatible)
Route::prefix('rest-auth')->group(function () {
    Route::post('/login', [AuthSessionController::class, 'login']);
    Route::post('/login/', [AuthSessionController::class, 'login']);
    Route::post('/password/reset', [AuthPasswordController::class, 'passwordReset']);
    Route::post('/password/reset/', [AuthPasswordController::class, 'passwordReset']);
    Route::post('/password/reset/confirm', [AuthPasswordController::class, 'passwordResetConfirm']);
    Route::post('/password/reset/confirm/', [AuthPasswordController::class, 'passwordResetConfirm']);

    Route::middleware(['django.auth'])->group(function () {
        Route::post('/logout', [AuthSessionController::class, 'logout']);
        Route::post('/logout/', [AuthSessionController::class, 'logout']);
        Route::get('/user', [AuthSessionController::class, 'user']);
        Route::get('/user/', [AuthSessionController::class, 'user']);
        Route::post('/password/change', [AuthPasswordController::class, 'passwordChange']);
        Route::post('/password/change/', [AuthPasswordController::class, 'passwordChange']);
    });
});

// Non-prefixed aliases
Route::post('/login', [AuthSessionController::class, 'login']);
Route::post('/password/reset', [AuthPasswordController::class, 'passwordReset']);
Route::post('/password/reset/confirm', [AuthPasswordController::class, 'passwordResetConfirm']);
Route::post('/forgot-password/{uid}/{token}/', [AuthPasswordController::class, 'passwordResetConfirm']);

Route::middleware(['django.auth'])->group(function () {
    Route::post('/logout', [AuthSessionController::class, 'logout']);
    Route::post('/password/change', [AuthPasswordController::class, 'passwordChange']);
    Route::middleware(['role:admin'])->group(function () {
        Route::get('/group-wise-distribution-per-grade', [OrganisationAnalyticsController::class, 'groupWiseDistributionPerGrade']);
        Route::delete('/bulk-delete-users', [UserController::class, 'bulkDeleteUsers']);
        Route::get('/performance-per-grade', [AnalyticsController::class, 'performancePerGrade']);
    });

    // Student Bulk Update
    Route::middleware(['admin.permission'])->group(function () {
        Route::post('/students/bulk-update', [StudentBulkUpdateController::class, 'bulkUpdate']);
        Route::get('/students/bulk-update/stats', [StudentBulkUpdateController::class, 'getStats']);
        Route::get('/students/list', [StudentBulkUpdateController::class, 'listStudents']);
    });
});

Route::middleware(['django.auth'])->prefix('students')->group(function () {
    Route::get('/{id}/assessment-report', [StudentAssessmentReportController::class, 'getReport']);
});

Route::get('/students', [StudentController::class, 'getStudents']);
Route::get('/students/{id}', [User::class, 'getStudent']);

Route::middleware(['django.auth'])->group(function () {
    Route::delete('/students/{id}', [StudentController::class, 'deleteStudent']);

    Route::post('/validate-promotion', [PromotionController::class, 'validatePromotion']);
    Route::post('/bulk-promote-students', [PromotionController::class, 'bulkPromoteStudents']);
    Route::post('/bulk-undo-promote-students', [PromotionController::class, 'bulkUndoPromoteStudents']);

    Route::get('/group-wise-users', [UserController::class, 'groupWiseUsers']);
    Route::get('/me', [UserController::class, 'me']);

    Route::get('/offline-data', [OrganisationController::class, 'offlineData']);

    Route::middleware(['role:admin,teacher'])->group(function () {
        Route::get('/no-of-assessments-organisation-wise', [AssessmentCountController::class, 'organisationWise']);
        Route::get('/no-of-assessments-grade-wise', [AssessmentCountController::class, 'gradeWise']);
        Route::get('/no-of-assessments-group-wise', [AssessmentCountController::class, 'groupWise']);

        Route::get('/grades', [GradeController::class, 'index']);
        Route::get('/grades/{id}', [GradeController::class, 'show']);

        Route::get('/passages', [PassageController::class, 'index']);
        Route::get('/passages/{id}', [PassageController::class, 'show']);

        Route::get('/checklists', [AssessmentController::class, 'getChecklists']);
        Route::get('/checklists/{id}', [AssessmentController::class, 'getChecklistDetails']);

        Route::get('/word-level-analysis', [AssessmentController::class, 'wordLevelAnalysis']);
    });

    Route::get('/organisations', [OrganisationController::class, 'index']);
    Route::get('/organisations/{id}', [OrganisationController::class, 'show']);
    Route::post('/organisations', [OrganisationController::class, 'store']);
    Route::patch('/organisations/{id}', [OrganisationController::class, 'update']);
    Route::delete('/organisations/{id}', [OrganisationController::class, 'destroy']);

    Route::get('/words', [WordController::class, 'index']);
    Route::get('/words/{wordId}', [WordController::class, 'show']);

    Route::middleware(['admin.permission'])->group(function () {
        Route::get('/grouping-parameters', [GroupingParameterController::class, 'index']);
        Route::post('/grouping-parameters', [GroupingParameterController::class, 'store']);
        Route::put('/grouping-parameters/{id}', [GroupingParameterController::class, 'update']);
        Route::post('/grouping-parameters/bulk-update', [GroupingParameterController::class, 'bulkUpdate']);
        Route::post('/bulk-update-grouping-parameters', [GroupingParameterController::class, 'bulkUpdate']);
        Route::delete('/grouping-parameters/{id}', [GroupingParameterController::class, 'destroy']);
        Route::get('/debug-grouping-parameters', [GroupingParameterController::class, 'debug']);

        Route::get('/accuracies', [AccuracyController::class, 'index']);
        Route::post('/accuracies', [AccuracyController::class, 'store']);
        Route::put('/accuracies/{id}', [AccuracyController::class, 'update']);
        Route::patch('/accuracies/{id}', [AccuracyController::class, 'update']);
        Route::delete('/accuracies/{id}', [AccuracyController::class, 'destroy']);
        Route::get('/debug-accuracies', [AccuracyController::class, 'debug']);

        Route::get('/benchmark-templates', [BenchmarkTemplateController::class, 'index']);
        Route::post('/benchmark-templates', [BenchmarkTemplateController::class, 'store']);
        Route::match(['patch', 'post'], '/benchmark-templates/{id}/activate', [BenchmarkTemplateController::class, 'activate']);
        Route::put('/activate-benchmark-template/{id}', [BenchmarkTemplateController::class, 'activate']);
        Route::get('/benchmark-templates/{id}', [BenchmarkTemplateController::class, 'show']);
        Route::put('/benchmark-templates/{id}', [BenchmarkTemplateController::class, 'update']);
        Route::delete('/benchmark-templates/{id}', [BenchmarkTemplateController::class, 'destroy']);

        Route::patch('/passages/{id}', [PassageController::class, 'update']);
        Route::put('/passages/{id}', [PassageController::class, 'update']);
        Route::put('/passages/{id}/status', [PassageController::class, 'updateStatus']);
    });

    Route::post('/assessments', [AssessmentController::class, 'postAssessments']);
    Route::get('/assessments/export', [AssessmentController::class, 'generateExcel']);
    Route::get('/assessments', [AssessmentController::class, 'getAssessments']);
    Route::get('/assessments/{id}', [AssessmentController::class, 'getAssessmentDetails']);
});


Route::get('/teachers', [TeacherController::class, 'index']);
Route::get('/teachers/{id}', [TeacherController::class, 'show']);

// Analytics Routes (Protected - Requires Teacher Authentication)
Route::middleware(['teacher.auth'])->group(function () {
    Route::get('/word-trend-analysis', [AnalyticsController::class, 'wordTrendAnalysis']);
});

Route::get('/users', [UserController::class, 'getUsers']);
Route::get('/users/{id}', [UserController::class, 'getUserDetails']);

Route::get('/generate-excel', [AssessmentController::class, 'generateExcel']);
