<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\User;
use App\Http\Controllers\Dashboard;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AssessmentController;
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
use App\Http\Controllers\LanguageController;

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

/*
|--------------------------------------------------------------------------
| Authentication & Password Reset (rest-auth)
|--------------------------------------------------------------------------
*/
Route::prefix('rest-auth')->group(function () {
    Route::post('/login', [AuthSessionController::class, 'login']);
    Route::post('/password/reset', [AuthPasswordController::class, 'passwordReset']);
    Route::post('/password/reset/confirm', [AuthPasswordController::class, 'passwordResetConfirm']);

    Route::middleware(['django.auth'])->group(function () {
        Route::post('/logout', [AuthSessionController::class, 'logout']);
        Route::get('/user', [AuthSessionController::class, 'user']);
        Route::post('/password/change', [AuthPasswordController::class, 'passwordChange']);
    });
});

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/
Route::get('/languages', [LanguageController::class, 'index']);
Route::get('/languages/{id}', [LanguageController::class, 'show']);

Route::get('/students', [StudentController::class, 'getStudents']);
Route::get('/students/{id}', [User::class, 'getStudent']);

Route::get('/teachers', [TeacherController::class, 'index']);
Route::get('/teachers/{id}', [TeacherController::class, 'show']);

Route::get('/users', [UserController::class, 'getUsers']);
Route::get('/users/{id}', [UserController::class, 'getUserDetails']);

Route::get('/passagePDF/{id}', [User::class, 'getPassagePDFNew']);
Route::get('/getAudio', [User::class, 'getAudio']);
Route::get('/getStudentPic/{id}', [User::class, 'getStudentPic']);
Route::get('/allOrgUsersCount', [User::class, 'allOrgUsersCount']);
Route::get('/organisationUsersCount/{id}', [User::class, 'organisationUsersCount']);
Route::get('/organisations/{id}/groups', [OrganisationGroupController::class, 'getOrganisationGroups']);

Route::post('/saveAudio', [User::class, 'saveAudio']);
Route::post('/saveDeviceDetails/{id}', [User::class, 'saveDeviceDetails']);
Route::post('/bulk-upload-student', [User::class, 'bulkUploadStudent']);
Route::post('/bulk-upload-teacher', [User::class, 'bulkUploadTeacher']);
Route::post('/add-student', [User::class, 'addStudent']);
Route::post('/edit-student/{id}', [User::class, 'editStudent']);
Route::post('/add-teacher', [User::class, 'addTeacher']);

/*
|--------------------------------------------------------------------------
| Legacy Token Auth
|--------------------------------------------------------------------------
*/
Route::middleware(['token.auth'])->group(function () {
    Route::put('/updateStudent/{id}', [User::class, 'updateStudent']);
    Route::put('/updateTeacher/{id}', [User::class, 'updateTeacher']);
    Route::get('/getCounts', [Dashboard::class, 'getCounts']);
    Route::get('/getTeacher/{id}', [User::class, 'getTeacher']);
});

/*
|--------------------------------------------------------------------------
| Authenticated Routes (Django Token Auth)
|--------------------------------------------------------------------------
*/
Route::middleware(['django.auth'])->group(function () {
    // Current authenticated user aliases
    Route::get('/user', [AuthSessionController::class, 'user']);
    Route::get('/me', [UserController::class, 'me']);
    Route::get('/group-wise-users', [UserController::class, 'groupWiseUsers']);
    Route::get('/offline-data', [OrganisationController::class, 'offlineData']);

    // Admin-only Routes
    Route::middleware(['admin.permission'])->group(function () {
        // Analytics
        Route::get('/group-wise-distribution-per-grade', [OrganisationAnalyticsController::class, 'groupWiseDistributionPerGrade']);
        Route::get('/performance-per-grade', [AnalyticsController::class, 'performancePerGrade']);

        // Bulk operations
        Route::delete('/bulk-delete-users', [UserController::class, 'bulkDeleteUsers']);
        Route::delete('/bulk-delete-passages', [PassageController::class, 'bulkDeletePassages']);

        // Student Bulk Management
        Route::post('/students/bulk-update', [StudentBulkUpdateController::class, 'bulkUpdate']);
        Route::get('/students/bulk-update/stats', [StudentBulkUpdateController::class, 'getStats']);
        Route::get('/students/list', [StudentBulkUpdateController::class, 'listStudents']);

        // Grouping Parameters
        Route::get('/grouping-parameters', [GroupingParameterController::class, 'index']);
        Route::post('/grouping-parameters', [GroupingParameterController::class, 'store']);
        Route::put('/grouping-parameters/{id}', [GroupingParameterController::class, 'update']);
        Route::delete('/grouping-parameters/{id}', [GroupingParameterController::class, 'destroy']);
        Route::post('/bulk-update-grouping-parameters', [GroupingParameterController::class, 'bulkUpdate']);
        Route::get('/debug-grouping-parameters', [GroupingParameterController::class, 'debug']);

        // Accuracies
        Route::get('/accuracies', [AccuracyController::class, 'index']);
        Route::get('/accuracies/{id}', [AccuracyController::class, 'show']);
        Route::post('/accuracies', [AccuracyController::class, 'store']);
        Route::match(['put', 'patch'], '/accuracies/{id}', [AccuracyController::class, 'update']);
        Route::delete('/accuracies/{id}', [AccuracyController::class, 'destroy']);
        Route::get('/debug-accuracies', [AccuracyController::class, 'debug']);

        // Benchmark Templates
        Route::get('/benchmark-templates', [BenchmarkTemplateController::class, 'index']);
        Route::post('/benchmark-templates', [BenchmarkTemplateController::class, 'store']);
        Route::match(['patch', 'post'], '/benchmark-templates/{id}/activate', [BenchmarkTemplateController::class, 'activate']);
        Route::put('/activate-benchmark-template/{id}', [BenchmarkTemplateController::class, 'activate']);
        Route::get('/benchmark-templates/{id}', [BenchmarkTemplateController::class, 'show']);
        Route::put('/benchmark-templates/{id}', [BenchmarkTemplateController::class, 'update']);
        Route::delete('/benchmark-templates/{id}', [BenchmarkTemplateController::class, 'destroy']);

        // Passage Management
        Route::match(['put', 'patch'], '/passages/{id}', [PassageController::class, 'update']);
        Route::put('/passages/{id}/status', [PassageController::class, 'updateStatus']);
    });

    // Admin & Teacher Accessible Routes
    Route::middleware(['role:admin,teacher'])->group(function () {
        Route::get('/no-of-assessments-organisation-wise', [OrganisationAnalyticsController::class, 'organisationWise']);
        Route::get('/no-of-assessments-grade-wise', [OrganisationAnalyticsController::class, 'gradeWise']);
        Route::get('/no-of-assessments-group-wise', [OrganisationAnalyticsController::class, 'groupWise']);

        Route::get('/grades', [GradeController::class, 'index']);
        Route::get('/grades/{id}', [GradeController::class, 'show']);

        Route::get('/passages', [PassageController::class, 'index']);
        Route::get('/passages/{id}', [PassageController::class, 'show']);

        Route::get('/checklists', [AssessmentController::class, 'getChecklists']);
        Route::get('/checklists/{id}', [AssessmentController::class, 'getChecklistDetails']);

        Route::get('/word-level-analysis', [AssessmentController::class, 'wordLevelAnalysis']);
    });

    // Student Assessment Reports & Management
    Route::get('/students/{id}/assessment-report', [StudentAssessmentReportController::class, 'getReport']);
    Route::delete('/students/{id}', [StudentController::class, 'deleteStudent']);
    Route::post('/validate-promotion', [PromotionController::class, 'validatePromotion']);
    Route::post('/bulk-promote-students', [PromotionController::class, 'bulkPromoteStudents']);
    Route::post('/bulk-undo-promote-students', [PromotionController::class, 'bulkUndoPromoteStudents']);

    // Organisations CRUD
    Route::get('/organisations', [OrganisationController::class, 'index']);
    Route::get('/organisations/{id}', [OrganisationController::class, 'show']);
    Route::post('/organisations', [OrganisationController::class, 'store']);
    Route::patch('/organisations/{id}', [OrganisationController::class, 'update']);
    Route::delete('/organisations/{id}', [OrganisationController::class, 'destroy']);

    // Words Dictionary & Search
    Route::get('/words', [WordController::class, 'index']);
    Route::get('/words/{wordId}', [WordController::class, 'show']);

    // Assessments
    Route::post('/assessments', [AssessmentController::class, 'postAssessments']);
    Route::get('/assessments', [AssessmentController::class, 'getAssessments']);
    Route::get('/assessments/export', [AssessmentController::class, 'generateExcel']);
    Route::get('/assessments/{id}', [AssessmentController::class, 'getAssessmentDetails']);
    Route::get('/generate-excel', [AssessmentController::class, 'generateExcel']);
});

/*
|--------------------------------------------------------------------------
| Analytics Routes (Teacher Authentication)
|--------------------------------------------------------------------------
*/
Route::middleware(['teacher.auth'])->group(function () {
    Route::get('/word-trend-analysis', [AnalyticsController::class, 'wordTrendAnalysis']);
});
