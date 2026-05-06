<?php

use App\Http\Controllers\Calendar\CalendarController;
use App\Http\Controllers\Calendar\TaskController as CalendarTaskController;
use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Community\CommunityController;
use App\Http\Controllers\Plant\PlantCareDebugController;
use App\Http\Controllers\Inventory\InventoryController;
use App\Http\Controllers\Location\ReverseGeocodeController;
use App\Http\Controllers\Plot\AnalyticsController;
use App\Http\Controllers\Plot\ExportController;
use App\Http\Controllers\Plot\HarvestController;
use App\Http\Controllers\Plot\HistoryController;
use App\Http\Controllers\Plot\ShareController;
use App\Http\Controllers\Plot\WorkspaceController;
use App\Http\Controllers\User\AccountController as UserAccountController;
use App\Http\Controllers\User\LoginController;
use App\Http\Controllers\User\CurrentUserController;
use App\Http\Controllers\User\LogoutController;
use App\Http\Controllers\User\PasswordResetController;
use App\Http\Controllers\User\SignUpController;
use App\Http\Controllers\Plant\PlantController;
use App\Http\Controllers\Plant\CatalogPlantController;
use App\Http\Controllers\Plant\PlantConditionController;
use App\Http\Controllers\Plot\PlotController;
use App\Http\Controllers\Plot\RotationController;
use App\Http\Controllers\Plot\SchemeController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [SignUpController::class, 'store']);
Route::post('/login', [LoginController::class, 'store']);
Route::post('/forgot-password', [PasswordResetController::class, 'forgot']);
Route::post('/reset-password', [PasswordResetController::class, 'reset']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [CurrentUserController::class, 'show']);
    Route::patch('/me', [UserAccountController::class, 'update']);
    Route::post('/logout', [LogoutController::class, 'destroy']);
    Route::delete('/access/{accessRight}', [ShareController::class, 'destroyById']);
    Route::get('/community', [CommunityController::class, 'index']);
    Route::get('/geocode/reverse', [ReverseGeocodeController::class, 'show']);
    Route::prefix('dev')->middleware('dev.only')->group(function () {
        Route::get('/plant-care-test/search', [PlantCareDebugController::class, 'search']);
        Route::get('/plant-care-test/species/{speciesId}', [PlantCareDebugController::class, 'species']);
        Route::get('/plant-care-test/weather', [PlantCareDebugController::class, 'weather']);
    });

    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('/users', [AccountController::class, 'index']);
        Route::get('/users/{user}', [AccountController::class, 'show']);
        Route::patch('/users/{user}/role', [AccountController::class, 'updateRole']);
        Route::delete('/users/{user}', [AccountController::class, 'destroy']);
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
    });

    Route::apiResource('plots', PlotController::class);
    Route::post('/plots/{plot}/share', [ShareController::class, 'store']);
    Route::delete('/plots/{plot}/share/{recipient}', [ShareController::class, 'destroy']);
    Route::get('/plots/{plot}/access', [ShareController::class, 'index']);
    Route::get('/plots/{plot}/analytics', [AnalyticsController::class, 'show']);
    Route::post('/plots/{plot}/analytics', [AnalyticsController::class, 'store']);
    Route::get('/plots/{plot}/history', [HistoryController::class, 'index']);
    Route::put('/plots/{plot}/workspace', [WorkspaceController::class, 'update']);
    Route::get('/plots/{plot}/export/pdf', [ExportController::class, 'pdf']);
    Route::get('/plots/{plot}/community', [CommunityController::class, 'plotFeed']);
    Route::get('/plots/{plot}/plant-zones', [SchemeController::class, 'index']);
    Route::post('/plots/{plot}/plant-zones', [SchemeController::class, 'store']);
    Route::patch('/plots/{plot}/plant-zones/{plantZone}', [SchemeController::class, 'update']);
    Route::delete('/plots/{plot}/plant-zones/{plantZone}', [SchemeController::class, 'destroy']);

    Route::get('/plants', [PlantController::class, 'listAll']);
    Route::get('/plots/{plot}/plants', [PlantController::class, 'index']);
    Route::get('/plants/catalog', [CatalogPlantController::class, 'index']);
    Route::get('/plants/search', [PlantController::class, 'search']);
    Route::get('/catalog-plants', [CatalogPlantController::class, 'index']);
    Route::get('/catalog-plants/search', [CatalogPlantController::class, 'index']);
    Route::get('/catalog-plants/perenual/search', [CatalogPlantController::class, 'searchPerenual']);
    Route::get('/catalog-plants/perenual/species/{speciesId}', [CatalogPlantController::class, 'previewPerenualSpecies']);
    Route::post('/catalog-plants', [CatalogPlantController::class, 'store']);
    Route::get('/catalog-plants/{catalogPlant}', [CatalogPlantController::class, 'show']);
    Route::patch('/catalog-plants/{catalogPlant}', [CatalogPlantController::class, 'update']);
    Route::put('/catalog-plants/{catalogPlant}', [CatalogPlantController::class, 'update']);
    Route::delete('/catalog-plants/{catalogPlant}', [CatalogPlantController::class, 'destroy']);
    Route::post('/plants', [PlantController::class, 'storeGlobal']);
    Route::get('/plants/{plant}', [PlantController::class, 'showGlobal']);
    Route::patch('/plants/{plant}', [PlantController::class, 'updateGlobal']);
    Route::delete('/plants/{plant}', [PlantController::class, 'destroyGlobal']);
    Route::post('/plots/{plot}/plants', [PlantController::class, 'store']);
    Route::get('/plots/{plot}/plants/{plant}', [PlantController::class, 'show']);
    Route::patch('/plots/{plot}/plants/{plant}', [PlantController::class, 'update']);
    Route::delete('/plots/{plot}/plants/{plant}', [PlantController::class, 'destroy']);

    Route::get('/plots/{plot}/plants/{plant}/conditions', [PlantConditionController::class, 'index']);
    Route::post('/plots/{plot}/plants/{plant}/conditions', [PlantConditionController::class, 'store']);

    Route::get('/plots/{plot}/rotations', [RotationController::class, 'index']);
    Route::get('/plots/{plot}/rotations/recommendations', [RotationController::class, 'recommendations']);
    Route::post('/plots/{plot}/rotations', [RotationController::class, 'store']);
    Route::post('/plots/{plot}/rotations/plans', [RotationController::class, 'plan']);
    Route::post('/plots/{plot}/rotations/plans/{rotationPlanDraft}/confirm', [RotationController::class, 'confirm']);
    Route::delete('/plots/{plot}/rotations/plans/{rotationPlanDraft}', [RotationController::class, 'reject']);
    Route::get('/plots/{plot}/harvests', [HarvestController::class, 'index']);
    Route::post('/plots/{plot}/harvests', [HarvestController::class, 'store']);

    Route::get('/plots/{plot}/calendars', [CalendarController::class, 'index']);
    Route::post('/plots/{plot}/calendars', [CalendarController::class, 'store']);
    Route::get('/plots/{plot}/calendars/{calendar}', [CalendarController::class, 'show']);

    Route::get('/calendars/{calendar}/tasks', [CalendarTaskController::class, 'index']);
    Route::patch('/tasks/{task}/complete', [CalendarTaskController::class, 'complete']);
    Route::patch('/tasks/{task}/reject', [CalendarTaskController::class, 'reject']);

    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::post('/inventory', [InventoryController::class, 'store']);
    Route::get('/inventory/{inventoryItem}', [InventoryController::class, 'show']);
    Route::patch('/inventory/{inventoryItem}', [InventoryController::class, 'update']);
    Route::delete('/inventory/{inventoryItem}', [InventoryController::class, 'destroy']);

    Route::post('/community', [CommunityController::class, 'store']);
    Route::patch('/community/{post}', [CommunityController::class, 'update']);
    Route::delete('/community/{post}', [CommunityController::class, 'destroy']);
});
