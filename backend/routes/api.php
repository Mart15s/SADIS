<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Calendar\CalendarController;
use App\Http\Controllers\Calendar\TaskController as CalendarTaskController;
use App\Http\Controllers\Community\CommunityController;
use App\Http\Controllers\Inventory\InventoryController;
use App\Http\Controllers\Location\ReverseGeocodeController;
use App\Http\Controllers\Plant\CatalogPlantController;
use App\Http\Controllers\Plant\PlantConditionController;
use App\Http\Controllers\Plant\PlantController;
use App\Http\Controllers\Plot\AnalyticsController;
use App\Http\Controllers\Plot\ExportController;
use App\Http\Controllers\Plot\HarvestController;
use App\Http\Controllers\Plot\HistoryController;
use App\Http\Controllers\Plot\PlotController;
use App\Http\Controllers\Plot\RotationController;
use App\Http\Controllers\Plot\SchemeController;
use App\Http\Controllers\Plot\ShareController;
use App\Http\Controllers\Plot\WorkspaceController;
use App\Http\Controllers\User\AccountController as UserAccountController;
use App\Http\Controllers\User\CurrentUserController;
use App\Http\Controllers\User\LoginController;
use App\Http\Controllers\User\LogoutController;
use App\Http\Controllers\User\PasswordResetController;
use App\Http\Controllers\User\SignUpController;
use App\Http\Controllers\Yava\CommunityController as YavaCommunityController;
use App\Http\Controllers\Yava\ContextController;
use App\Http\Controllers\Yava\CropController as YavaCropController;
use App\Http\Controllers\Yava\FarmController;
use App\Http\Controllers\Yava\FieldController;
use App\Http\Controllers\Yava\OnboardingController;
use App\Http\Controllers\Yava\OperationsController;
use App\Http\Controllers\Yava\OtpController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [SignUpController::class, 'store'])->middleware('throttle:registration');
Route::post('/login', [LoginController::class, 'store']);
Route::post('/forgot-password', [PasswordResetController::class, 'forgot']);
Route::post('/reset-password', [PasswordResetController::class, 'reset']);
Route::middleware('throttle:otp')->prefix('v1/auth/otp')->group(function () {
    Route::post('/request', [OtpController::class, 'request']);
    Route::post('/verify', [OtpController::class, 'verify']);
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/me', [CurrentUserController::class, 'show']);
    Route::patch('/me', [UserAccountController::class, 'update']);
    Route::post('/logout', [LogoutController::class, 'destroy']);
    Route::delete('/access/{accessRight}', [ShareController::class, 'destroyById']);
    Route::get('/community', [CommunityController::class, 'index']);
    Route::get('/geocode/reverse', [ReverseGeocodeController::class, 'show']);
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
    Route::post('/plots/{plot}/plant-zones/{plantZone}/archive', [SchemeController::class, 'archive']);
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
    Route::patch('/plots/{plot}/rotations/plans/{rotationPlanDraft}/items/{plant}', [RotationController::class, 'updateDraftItem']);
    Route::post('/plots/{plot}/rotations/plans/{rotationPlanDraft}/confirm', [RotationController::class, 'confirm']);
    Route::delete('/plots/{plot}/rotations/plans/{rotationPlanDraft}', [RotationController::class, 'reject']);
    Route::get('/plots/{plot}/harvests', [HarvestController::class, 'index']);
    Route::post('/plots/{plot}/harvests', [HarvestController::class, 'store']);

    Route::get('/plots/{plot}/calendars', [CalendarController::class, 'index']);
    Route::post('/plots/{plot}/calendars', [CalendarController::class, 'store']);
    Route::get('/plots/{plot}/calendars/{calendar}', [CalendarController::class, 'show']);
    Route::post('/plots/{plot}/calendars/{calendar}/weather-refresh', [CalendarController::class, 'refreshWeather']);

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

    Route::prefix('v1')->group(function () {
        Route::get('/contexts', [ContextController::class, 'index']);

        Route::get('/communities', [YavaCommunityController::class, 'index']);
        Route::post('/communities', [YavaCommunityController::class, 'store']);
        Route::get('/communities/discover', [YavaCommunityController::class, 'discover']);
        Route::get('/communities/{community}', [YavaCommunityController::class, 'show']);
        Route::patch('/communities/{community}', [YavaCommunityController::class, 'update']);
        Route::delete('/communities/{community}', [YavaCommunityController::class, 'destroy']);
        Route::post('/communities/{community}/invitations', [YavaCommunityController::class, 'invite']);
        Route::get('/communities/{community}/invitations', [YavaCommunityController::class, 'invitations']);
        Route::get('/communities/{community}/members', [YavaCommunityController::class, 'members']);
        Route::post('/invitations/{code}/accept', [YavaCommunityController::class, 'acceptInvitation']);
        Route::post('/communities/{community}/join-requests', [YavaCommunityController::class, 'requestJoin']);
        Route::get('/communities/{community}/join-requests', [YavaCommunityController::class, 'joinRequests']);
        Route::patch('/communities/{community}/join-requests/{joinRequest}', [YavaCommunityController::class, 'decideJoin']);
        Route::post('/communities/{community}/join-requests/{joinRequest}/approve', [YavaCommunityController::class, 'approveJoin']);
        Route::post('/communities/{community}/join-requests/{joinRequest}/reject', [YavaCommunityController::class, 'rejectJoin']);
        Route::patch('/communities/{community}/members/{membership}', [YavaCommunityController::class, 'updateMember']);
        Route::get('/communities/{community}/analytics', [OperationsController::class, 'communityAnalytics']);

        Route::get('/farms', [FarmController::class, 'index']);
        Route::post('/farms', [FarmController::class, 'store']);
        Route::get('/farms/{farm}', [FarmController::class, 'show']);
        Route::patch('/farms/{farm}', [FarmController::class, 'update']);
        Route::delete('/farms/{farm}', [FarmController::class, 'destroy']);
        Route::get('/farms/{farm}/members', [FarmController::class, 'members']);
        Route::post('/farms/{farm}/members', [FarmController::class, 'addMember']);
        Route::patch('/farms/{farm}/members/{membership}', [FarmController::class, 'updateMember']);
        Route::post('/farms/{farm}/communities/{community}', [FarmController::class, 'linkCommunity']);
        Route::get('/farm-community-links', [FarmController::class, 'communityLinks']);
        Route::post('/farm-community-links/{link}/{decision}', [FarmController::class, 'decideCommunityLink'])->whereIn('decision', ['approve', 'reject']);
        Route::delete('/farms/{farm}/community-links/{link}', [FarmController::class, 'revokeCommunity']);
        Route::get('/farms/{farm}/analytics', [OperationsController::class, 'farmAnalytics']);

        Route::get('/fields', [FieldController::class, 'index']);
        Route::post('/fields', [FieldController::class, 'store']);
        Route::get('/fields/{field}', [FieldController::class, 'show']);
        Route::patch('/fields/{field}', [FieldController::class, 'update']);
        Route::delete('/fields/{field}', [FieldController::class, 'destroy']);
        Route::put('/fields/{field}/workspace', [FieldController::class, 'workspace']);
        Route::post('/fields/{field}/zones', [FieldController::class, 'storeZone']);
        Route::patch('/fields/{field}/zones/{zone}', [FieldController::class, 'updateZone']);
        Route::delete('/fields/{field}/zones/{zone}', [FieldController::class, 'destroyZone']);

        Route::get('/crops', [YavaCropController::class, 'index']);
        Route::post('/crops', [YavaCropController::class, 'store']);
        Route::get('/crops/{crop}', [YavaCropController::class, 'show']);
        Route::patch('/crops/{crop}', [YavaCropController::class, 'update']);
        Route::delete('/crops/{crop}', [YavaCropController::class, 'destroy']);
        Route::post('/crops/{crop}/varieties', [YavaCropController::class, 'storeVariety']);
        Route::get('/crop-seasons', [YavaCropController::class, 'seasons']);
        Route::post('/crop-seasons', [YavaCropController::class, 'storeSeason']);
        Route::get('/crop-seasons/{cropSeason}', [YavaCropController::class, 'showSeason']);
        Route::patch('/crop-seasons/{cropSeason}', [YavaCropController::class, 'updateSeason']);
        Route::delete('/crop-seasons/{cropSeason}', [YavaCropController::class, 'destroySeason']);
        Route::post('/crop-seasons/{cropSeason}/conditions', [YavaCropController::class, 'condition']);
        Route::post('/crop-seasons/{cropSeason}/harvests', [YavaCropController::class, 'harvest']);
        Route::get('/crop-seasons/{cropSeason}/rotation-warnings', [YavaCropController::class, 'rotationWarnings']);

        Route::get('/tasks', [OperationsController::class, 'tasks']);
        Route::post('/tasks', [OperationsController::class, 'storeTask']);
        Route::get('/tasks/{task}', [OperationsController::class, 'showTask']);
        Route::patch('/tasks/{task}', [OperationsController::class, 'updateTask']);
        Route::delete('/tasks/{task}', [OperationsController::class, 'destroyTask']);
        Route::post('/tasks/{task}/complete', [OperationsController::class, 'completeTask']);

        Route::get('/inventories', [OperationsController::class, 'inventories']);
        Route::post('/inventories', [OperationsController::class, 'storeInventory']);
        Route::get('/inventories/{inventory}', [OperationsController::class, 'showInventory']);
        Route::patch('/inventories/{inventory}', [OperationsController::class, 'updateInventory']);
        Route::delete('/inventories/{inventory}', [OperationsController::class, 'destroyInventory']);
        Route::post('/inventory-movements', [OperationsController::class, 'storeMovement']);

        Route::get('/resources', [OperationsController::class, 'resources']);
        Route::post('/resources', [OperationsController::class, 'storeResource']);
        Route::get('/resources/{resource}', [OperationsController::class, 'showResource']);
        Route::patch('/resources/{resource}', [OperationsController::class, 'updateResource']);
        Route::delete('/resources/{resource}', [OperationsController::class, 'destroyResource']);
        Route::get('/reservations', [OperationsController::class, 'reservations']);
        Route::post('/reservations', [OperationsController::class, 'storeReservation']);
        Route::get('/reservations/{reservation}', [OperationsController::class, 'showReservation']);
        Route::post('/reservations/{reservation}/{transition}', [OperationsController::class, 'reservationTransition'])->whereIn('transition', ['approve', 'reject', 'cancel', 'complete']);
        Route::get('/recommendations', [OperationsController::class, 'recommendations']);
        Route::get('/planning-history', [OperationsController::class, 'planningHistory']);

        Route::get('/onboarding', [OnboardingController::class, 'show']);
        Route::put('/onboarding', [OnboardingController::class, 'update']);
    });
});
