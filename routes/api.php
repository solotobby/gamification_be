<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\GeneralController;
use App\Http\Controllers\SurveyController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });

Route::group(['namespace' => 'auth'], function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login',  [AuthController::class,'login']);
    ///publi apis
    Route::get('landing', [GeneralController::class, 'ladingpageApi']);
    Route::get('country/list', [GeneralController::class, 'country']);
});

Route::middleware(['auth:api'])->group(function () {
    // Route::post('/update',  [AuthController::class,'update']);
    // Route::post('/change/password',  [AuthController::class,'changePassword']); 
    Route::get('/logout',  [AuthController::class,'logout']); 
});

Route::prefix('survey')->middleware(['auth:api'])->group(function () {
    Route::get('/', [SurveyController::class, 'survey']);
    Route::post('/', [SurveyController::class, 'storeSurvey']);
});

