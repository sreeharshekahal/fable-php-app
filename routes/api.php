<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\User;
use App\Http\Controllers\Dashboard;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AssessmentController;
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
use App\Http\Controllers\PassageController;


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
        Route::get('/performance-per-grade', [AnalyticsController::class, 'performancePerGrade']);

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

Route::get('/checklists', [AssessmentController::class, 'getChecklists']);
Route::get('/checklists/{id}', [AssessmentController::class, 'getChecklistDetails']);
