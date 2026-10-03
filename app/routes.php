<?php
declare(strict_types=1);

/**
 * Application routes.
 *
 * Middleware values are permission codes checked server-side before the
 * controller runs. 'auth' means any signed-in user; 'guest' keeps signed-in
 * users away from the login screen.
 */

use Core\Router;
use Http\Controllers\HomeController;
use Http\Controllers\RegisterController;
use Http\Controllers\AuthController;
use Http\Controllers\DashboardController;
use Http\Controllers\FarmerController;
use Http\Controllers\GroupController;
use Http\Controllers\OfficerController;
use Http\Controllers\GardenController;
use Http\Controllers\MapController;
use Http\Controllers\CropController;
use Http\Controllers\VarietyController;
use Http\Controllers\SeasonController;
use Http\Controllers\PlantingController;
use Http\Controllers\ActivityController;
use Http\Controllers\InspectionController;
use Http\Controllers\VisitController;
use Http\Controllers\SprayingController;
use Http\Controllers\ProblemController;
use Http\Controllers\InputController;
use Http\Controllers\HarvestController;
use Http\Controllers\BatchController;
use Http\Controllers\BuyerController;
use Http\Controllers\MarketController;
use Http\Controllers\SaleController;
use Http\Controllers\ExportController;
use Http\Controllers\KnowledgeController;
use Http\Controllers\ReportController;
use Http\Controllers\CommandCentreController;
use Http\Controllers\NotificationController;
use Http\Controllers\ProfileController;
use Http\Controllers\UploadController;

use Http\Controllers\Admin\UserController;
use Http\Controllers\Admin\RoleController;
use Http\Controllers\Admin\SettingController;
use Http\Controllers\Admin\AuditLogController;
use Http\Controllers\Admin\ReferenceController;
use Http\Controllers\Api\V1\AuthApiController;
use Http\Controllers\Api\V1\FarmerApiController;
use Http\Controllers\Api\V1\GardenApiController;
use Http\Controllers\Api\V1\CropApiController;
use Http\Controllers\Api\V1\ActivityApiController;
use Http\Controllers\Api\V1\InspectionApiController;
use Http\Controllers\Api\V1\ProblemApiController;
use Http\Controllers\Api\V1\HarvestApiController;
use Http\Controllers\Api\V1\NotificationApiController;
use Http\Controllers\Api\V1\SyncApiController;
use Http\Controllers\Api\V1\InputApiController;
use Http\Controllers\Api\V1\ReferenceApiController;
use Http\Controllers\Api\V1\AttachmentApiController;


/*
 * @param array<string,mixed> $config Optional, so tools/check-routes.php can load
 *                                    the routes without booting the application.
 *                                    Only the upload prefix is read from it.
 */
return static function (Router $router, array $config = []): void {

    /* ------------------------------------------------------------------ */
    /* Uploaded files                                                      */
    /* ------------------------------------------------------------------ */

    /*
     * Uploaded files are served by PHP rather than by the web server, for two
     * reasons.
     *
     * The obvious one: the document root is public/ and the files live in
     * storage/, which is refused, so nothing under /storage/uploads would be
     * reachable and every stored path would be a broken link.
     *
     * The better one: the files are farmer identity documents, consent forms
     * and photographs of their fields. A static URL is a permanent, shareable,
     * unauthenticated link to exactly the documents a cooperative most needs to
     * keep private. Going through a route means the file is only handed to a
     * signed-in user.
     *
     * The prefix comes from configuration so that changing UPLOAD_URL moves the
     * route with it. Anything that changes uploads.url but not this argument
     * would leave the old prefix as a 404, which is the safe direction to fail.
     */
    $uploadPrefix = (string) ($config['uploads']['url'] ?? '/storage/uploads');
    $uploadPrefix = '/' . trim($uploadPrefix, '/');

    $router->get($uploadPrefix . '/{path:.+}', [UploadController::class, 'serve'], ['auth']);

    /* ------------------------------------------------------------------ */
    /* Authentication                                                       */
    /* ------------------------------------------------------------------ */

    $router->get('/', [HomeController::class, 'index']);

    $router->get('/register', [RegisterController::class, 'showRegistration'], ['guest']);
    $router->post('/register', [RegisterController::class, 'register'], ['guest']);
    $router->get('/login',  [AuthController::class, 'showLogin'],  ['guest']);
    $router->post('/login', [AuthController::class, 'login'],      ['guest']);
    $router->post('/logout', [AuthController::class, 'logout'],    ['auth']);
    $router->get('/logout',  [AuthController::class, 'logout'],    ['auth']);

    $router->get('/forgot-password',  [AuthController::class, 'showForgotPassword'], ['guest']);
    $router->post('/forgot-password', [AuthController::class, 'sendResetLink'],      ['guest']);
    $router->get('/reset-password/{token}',  [AuthController::class, 'showResetPassword'], ['guest']);
    $router->post('/reset-password/{token}', [AuthController::class, 'resetPassword'],     ['guest']);

    /* ------------------------------------------------------------------ */
    /* Dashboards                                                           */
    /* ------------------------------------------------------------------ */

    /*
     * "/" is registered once, above, by HomeController, which forwards to
     * /dashboard for a signed-in user and to the landing page otherwise. A
     * second "/" route here would never run: Core\Router returns the first
     * match, so the auth-guarded duplicate was dead code that looked like it
     * was protecting the dashboard.
     */
    $router->get('/dashboard', [DashboardController::class, 'index'],  ['auth']);

    $router->get('/command-centre', [CommandCentreController::class, 'index'], ['reports.view']);
    $router->get('/command-centre/data', [CommandCentreController::class, 'data'], ['reports.view']);

    /* ------------------------------------------------------------------ */
    /* Farmers                                                              */
    /* ------------------------------------------------------------------ */

    $router->get('/farmers',                    [FarmerController::class, 'index'],    ['farmers.view']);
    $router->get('/farmers/create',             [FarmerController::class, 'create'],   ['farmers.create']);
    $router->post('/farmers',                   [FarmerController::class, 'store'],    ['farmers.create']);
    $router->get('/farmers/{code}',             [FarmerController::class, 'show'],     ['farmers.view']);
    $router->get('/farmers/{code}/edit',        [FarmerController::class, 'edit'],     ['farmers.edit']);
    $router->post('/farmers/{code}',            [FarmerController::class, 'update'],   ['farmers.edit']);
    $router->post('/farmers/{code}/delete',     [FarmerController::class, 'destroy'],  ['farmers.delete']);
    $router->get('/farmers/{code}/print',       [FarmerController::class, 'printView'], ['farmers.view']);
    $router->get('/farmers/export/csv',         [FarmerController::class, 'export'],   ['farmers.view']);
    $router->get('/farmers/{code}/documents',   [FarmerController::class, 'documents'], ['farmers.view']);
    $router->post('/farmers/{code}/documents',  [FarmerController::class, 'storeDocument'], ['farmers.edit']);
    $router->post('/documents/{id}/delete',     [FarmerController::class, 'deleteDocument'], ['farmers.edit']);

    /* ------------------------------------------------------------------ */
    /* Groups and officers                                                  */
    /* ------------------------------------------------------------------ */

    $router->get('/groups',            [GroupController::class, 'index'],   ['groups.view']);
    $router->get('/groups/create',     [GroupController::class, 'create'],  ['groups.manage']);
    $router->post('/groups',           [GroupController::class, 'store'],   ['groups.manage']);
    $router->get('/groups/{id}',       [GroupController::class, 'show'],    ['groups.view']);
    $router->get('/groups/{id}/edit',  [GroupController::class, 'edit'],    ['groups.manage']);
    $router->post('/groups/{id}',      [GroupController::class, 'update'],  ['groups.manage']);
    $router->post('/groups/{id}/delete', [GroupController::class, 'destroy'], ['groups.manage']);
    $router->post('/groups/{id}/members', [GroupController::class, 'addMember'], ['groups.manage']);
    $router->post('/groups/{id}/members/remove', [GroupController::class, 'removeMember'], ['groups.manage']);

    $router->get('/officers',            [OfficerController::class, 'index'],   ['officers.view']);
    $router->get('/officers/create',     [OfficerController::class, 'create'],  ['officers.manage']);
    $router->post('/officers',           [OfficerController::class, 'store'],   ['officers.manage']);
    $router->get('/officers/{id}',       [OfficerController::class, 'show'],    ['officers.view']);
    $router->get('/officers/{id}/edit',  [OfficerController::class, 'edit'],    ['officers.manage']);
    $router->post('/officers/{id}',      [OfficerController::class, 'update'],  ['officers.manage']);
    $router->post('/officers/{id}/delete', [OfficerController::class, 'destroy'], ['officers.manage']);
    $router->post('/officers/{id}/assign', [OfficerController::class, 'assign'], ['officers.manage']);
    $router->post('/officers/{id}/unassign', [OfficerController::class, 'unassign'], ['officers.manage']);

    /* ------------------------------------------------------------------ */
    /* Gardens and GIS                                                      */
    /* ------------------------------------------------------------------ */

    $router->get('/gardens',             [GardenController::class, 'index'],   ['gardens.view']);
    $router->get('/gardens/create',      [GardenController::class, 'create'],  ['gardens.manage']);
    $router->post('/gardens',            [GardenController::class, 'store'],   ['gardens.manage']);
    $router->get('/gardens/{code}',      [GardenController::class, 'show'],    ['gardens.view']);
    $router->get('/gardens/{code}/edit', [GardenController::class, 'edit'],    ['gardens.manage']);
    $router->post('/gardens/{code}',     [GardenController::class, 'update'],  ['gardens.manage']);
    $router->post('/gardens/{code}/delete', [GardenController::class, 'destroy'], ['gardens.manage']);
    $router->get('/gardens/{code}/history', [GardenController::class, 'history'], ['gardens.view']);

    $router->get('/map',       [MapController::class, 'index'], ['map.view']);
    $router->get('/map/data',  [MapController::class, 'data'],  ['map.view']);

    /* ------------------------------------------------------------------ */
    /* Crops, varieties, seasons, planting                                  */
    /* ------------------------------------------------------------------ */

    $router->get('/crops',            [CropController::class, 'index'],   ['crops.view']);
    $router->get('/crops/create',     [CropController::class, 'create'],  ['crops.manage']);
    $router->post('/crops',           [CropController::class, 'store'],   ['crops.manage']);
    $router->get('/crops/{id}/edit',  [CropController::class, 'edit'],    ['crops.manage']);
    $router->post('/crops/{id}',      [CropController::class, 'update'],  ['crops.manage']);
    $router->post('/crops/{id}/delete', [CropController::class, 'destroy'], ['crops.manage']);
    $router->get('/crops/{id}/lifecycle',   [CropController::class, 'lifecycle'], ['crops.manage']);
    $router->post('/crops/{id}/lifecycle',  [CropController::class, 'storeLifecycle'], ['crops.manage']);
    $router->post('/lifecycle/{id}/delete', [CropController::class, 'destroyLifecycle'], ['crops.manage']);

    $router->get('/varieties',            [VarietyController::class, 'index'],   ['crops.view']);
    $router->get('/varieties/create',     [VarietyController::class, 'create'],  ['crops.manage']);
    $router->post('/varieties',           [VarietyController::class, 'store'],   ['crops.manage']);
    $router->get('/varieties/{id}/edit',  [VarietyController::class, 'edit'],    ['crops.manage']);
    $router->post('/varieties/{id}',      [VarietyController::class, 'update'],  ['crops.manage']);
    $router->post('/varieties/{id}/delete', [VarietyController::class, 'destroy'], ['crops.manage']);

    $router->get('/seasons',            [SeasonController::class, 'index'],   ['seasons.view']);
    $router->get('/seasons/create',     [SeasonController::class, 'create'],  ['seasons.manage']);
    $router->post('/seasons',           [SeasonController::class, 'store'],   ['seasons.manage']);
    $router->get('/seasons/{id}/edit',  [SeasonController::class, 'edit'],    ['seasons.manage']);
    $router->post('/seasons/{id}',      [SeasonController::class, 'update'],  ['seasons.manage']);
    $router->post('/seasons/{id}/delete', [SeasonController::class, 'destroy'], ['seasons.manage']);

    $router->get('/plantings',            [PlantingController::class, 'index'],   ['plantings.view']);
    $router->get('/plantings/create',     [PlantingController::class, 'create'],  ['plantings.manage']);
    $router->post('/plantings',           [PlantingController::class, 'store'],   ['plantings.manage']);
    $router->get('/plantings/{code}',     [PlantingController::class, 'show'],    ['plantings.view']);
    $router->get('/plantings/{code}/edit',[PlantingController::class, 'edit'],    ['plantings.manage']);
    $router->post('/plantings/{code}',    [PlantingController::class, 'update'],  ['plantings.manage']);
    $router->post('/plantings/{code}/delete', [PlantingController::class, 'destroy'], ['plantings.manage']);
    $router->get('/plantings/{code}/schedule', [PlantingController::class, 'schedule'], ['plantings.view']);

    /* ------------------------------------------------------------------ */
    /* Activities                                                           */
    /* ------------------------------------------------------------------ */

    $router->get('/activities',                 [ActivityController::class, 'index'],   ['activities.view']);
    $router->get('/activities/calendar',        [ActivityController::class, 'calendar'], ['activities.view']);
    $router->get('/activities/calendar/data',   [ActivityController::class, 'calendarData'], ['activities.view']);
    $router->get('/activities/create',          [ActivityController::class, 'create'],  ['activities.manage']);
    $router->post('/activities',                [ActivityController::class, 'store'],   ['activities.manage']);
    $router->get('/activities/{code}',          [ActivityController::class, 'show'],    ['activities.view']);
    $router->get('/activities/{code}/edit',     [ActivityController::class, 'edit'],    ['activities.manage']);
    $router->post('/activities/{code}',         [ActivityController::class, 'update'],  ['activities.manage']);
    $router->post('/activities/{code}/complete',[ActivityController::class, 'complete'], ['activities.view']);
    $router->post('/activities/{code}/cancel',  [ActivityController::class, 'cancel'],  ['activities.manage']);
    $router->post('/activities/{code}/delete',  [ActivityController::class, 'destroy'], ['activities.manage']);

    /* ------------------------------------------------------------------ */
    /* Field operations                                                     */
    /* ------------------------------------------------------------------ */

    $router->get('/visits',              [VisitController::class, 'index'],   ['visits.view']);
    $router->get('/visits/create',       [VisitController::class, 'create'],  ['visits.manage']);
    $router->post('/visits',             [VisitController::class, 'store'],   ['visits.manage']);
    $router->get('/visits/{code}',       [VisitController::class, 'show'],    ['visits.view']);
    $router->post('/visits/{code}/delete', [VisitController::class, 'destroy'], ['visits.manage']);

    $router->get('/inspections',              [InspectionController::class, 'index'],   ['inspections.view']);
    $router->get('/inspections/create',       [InspectionController::class, 'create'],  ['inspections.manage']);
    $router->post('/inspections',             [InspectionController::class, 'store'],   ['inspections.manage']);
    $router->get('/inspections/{code}',       [InspectionController::class, 'show'],    ['inspections.view']);
    $router->get('/inspections/{code}/edit',  [InspectionController::class, 'edit'],    ['inspections.manage']);
    $router->post('/inspections/{code}',      [InspectionController::class, 'update'],  ['inspections.manage']);
    $router->post('/inspections/{code}/delete', [InspectionController::class, 'destroy'], ['inspections.manage']);

    $router->get('/problems',              [ProblemController::class, 'index'],   ['problems.view']);
    $router->get('/problems/create',       [ProblemController::class, 'create'],  ['problems.manage']);
    $router->post('/problems',             [ProblemController::class, 'store'],   ['problems.manage']);
    $router->get('/problems/{code}',       [ProblemController::class, 'show'],    ['problems.view']);
    $router->get('/problems/{code}/edit',  [ProblemController::class, 'edit'],    ['problems.manage']);
    $router->post('/problems/{code}',      [ProblemController::class, 'update'],  ['problems.manage']);
    $router->post('/problems/{code}/status', [ProblemController::class, 'updateStatus'], ['problems.manage']);
    $router->post('/problems/{code}/delete', [ProblemController::class, 'destroy'], ['problems.manage']);

    $router->get('/spraying',              [SprayingController::class, 'index'],   ['spraying.view']);
    $router->get('/spraying/create',       [SprayingController::class, 'create'],  ['spraying.manage']);
    $router->post('/spraying',             [SprayingController::class, 'store'],   ['spraying.manage']);
    $router->get('/spraying/{code}',       [SprayingController::class, 'show'],    ['spraying.view']);
    $router->get('/spraying/{code}/edit',  [SprayingController::class, 'edit'],    ['spraying.manage']);
    $router->post('/spraying/{code}',      [SprayingController::class, 'update'],  ['spraying.manage']);
    $router->post('/spraying/{code}/delete', [SprayingController::class, 'destroy'], ['spraying.manage']);

    /* ------------------------------------------------------------------ */
    /* Inputs                                                               */
    /* ------------------------------------------------------------------ */

    $router->get('/inputs',            [InputController::class, 'index'],   ['inputs.view']);
    $router->get('/inputs/create',     [InputController::class, 'create'],  ['inputs.manage']);
    $router->post('/inputs',           [InputController::class, 'store'],   ['inputs.manage']);
    $router->get('/inputs/{code}',     [InputController::class, 'show'],    ['inputs.view']);
    $router->get('/inputs/{code}/edit',[InputController::class, 'edit'],    ['inputs.manage']);
    $router->post('/inputs/{code}',    [InputController::class, 'update'],  ['inputs.manage']);
    $router->post('/inputs/{code}/delete', [InputController::class, 'destroy'], ['inputs.manage']);
    $router->get('/inputs/{code}/stock', [InputController::class, 'stock'],  ['inputs.view']);
    $router->post('/inputs/{code}/stock', [InputController::class, 'adjustStock'], ['inputs.manage']);

    $router->get('/input-issues',            [InputController::class, 'issueIndex'],  ['inputs.view']);
    $router->get('/input-issues/create',     [InputController::class, 'issueCreate'], ['inputs.issue']);
    $router->post('/input-issues',           [InputController::class, 'issueStore'],  ['inputs.issue']);
    $router->get('/input-issues/{code}',     [InputController::class, 'issueShow'],   ['inputs.view']);

    $router->get('/input-usage',            [InputController::class, 'usageIndex'],  ['inputs.view']);
    $router->get('/input-usage/create',     [InputController::class, 'usageCreate'], ['inputs.view']);
    $router->post('/input-usage',           [InputController::class, 'usageStore'],  ['inputs.view']);
    $router->get('/input-usage/{code}/edit',[InputController::class, 'usageEdit'],   ['inputs.view']);
    $router->post('/input-usage/{code}',    [InputController::class, 'usageUpdate'], ['inputs.view']);
    $router->post('/input-usage/{code}/delete', [InputController::class, 'usageDelete'], ['inputs.manage']);

    /* ------------------------------------------------------------------ */
    /* Harvest and batches                                                  */
    /* ------------------------------------------------------------------ */

    $router->get('/harvest',              [HarvestController::class, 'index'],   ['harvest.view']);
    $router->get('/harvest/create',       [HarvestController::class, 'create'],  ['harvest.manage']);
    $router->post('/harvest',             [HarvestController::class, 'store'],   ['harvest.manage']);
    $router->get('/harvest/{code}',       [HarvestController::class, 'show'],    ['harvest.view']);
    $router->get('/harvest/{code}/edit',  [HarvestController::class, 'edit'],    ['harvest.manage']);
    $router->post('/harvest/{code}',      [HarvestController::class, 'update'],  ['harvest.manage']);
    $router->post('/harvest/{code}/delete', [HarvestController::class, 'destroy'], ['harvest.manage']);

    $router->get('/forecast',            [HarvestController::class, 'forecastIndex'], ['harvest.view']);
    $router->get('/forecast/generate',   [HarvestController::class, 'generateForecast'], ['harvest.manage']);
    $router->post('/forecast/{id}/revise', [HarvestController::class, 'reviseForecast'], ['harvest.manage']);

    $router->get('/batches',             [BatchController::class, 'index'],   ['harvest.view']);
    $router->get('/batches/create',      [BatchController::class, 'create'],  ['batches.manage']);
    $router->post('/batches',            [BatchController::class, 'store'],   ['batches.manage']);
    $router->get('/batches/{code}',      [BatchController::class, 'show'],    ['harvest.view']);
    $router->post('/batches/{id}/items', [BatchController::class, 'addItem'], ['batches.manage']);
    $router->post('/batches/{id}/items/remove', [BatchController::class, 'removeItem'], ['batches.manage']);
    $router->post('/batches/{code}/close', [BatchController::class, 'close'], ['batches.manage']);
    $router->get('/batches/{code}/trace', [BatchController::class, 'trace'],  ['harvest.view']);

    /* ------------------------------------------------------------------ */
    /* Markets, sales, exports                                              */
    /* ------------------------------------------------------------------ */

    $router->get('/buyers',            [BuyerController::class, 'index'],   ['markets.view']);
    $router->get('/buyers/create',     [BuyerController::class, 'create'],  ['markets.manage']);
    $router->post('/buyers',           [BuyerController::class, 'store'],   ['markets.manage']);
    $router->get('/buyers/{code}',     [BuyerController::class, 'show'],    ['markets.view']);
    $router->get('/buyers/{code}/edit',[BuyerController::class, 'edit'],    ['markets.manage']);
    $router->post('/buyers/{code}',    [BuyerController::class, 'update'],  ['markets.manage']);
    $router->post('/buyers/{code}/delete', [BuyerController::class, 'destroy'], ['markets.manage']);

    $router->get('/markets',            [MarketController::class, 'index'],   ['markets.view']);
    $router->get('/markets/create',     [MarketController::class, 'create'],  ['markets.manage']);
    $router->post('/markets',           [MarketController::class, 'store'],   ['markets.manage']);
    $router->get('/markets/{code}/edit',[MarketController::class, 'edit'],    ['markets.manage']);
    $router->post('/markets/{code}',    [MarketController::class, 'update'],  ['markets.manage']);
    $router->post('/markets/{code}/delete', [MarketController::class, 'destroy'], ['markets.manage']);

    $router->get('/demand',             [MarketController::class, 'demandIndex'],   ['markets.view']);
    $router->get('/demand/create',      [MarketController::class, 'demandCreate'],  ['markets.manage']);
    $router->post('/demand',            [MarketController::class, 'demandStore'],   ['markets.manage']);
    $router->get('/demand/{code}/edit', [MarketController::class, 'demandEdit'],    ['markets.manage']);
    $router->post('/demand/{code}',     [MarketController::class, 'demandUpdate'],  ['markets.manage']);
    $router->post('/demand/{code}/delete', [MarketController::class, 'demandDestroy'], ['markets.manage']);
    $router->get('/demand/balance',     [MarketController::class, 'balance'], ['markets.view']);

    $router->get('/sales',              [SaleController::class, 'index'],   ['sales.manage']);
    $router->get('/sales/create',       [SaleController::class, 'create'],  ['sales.manage']);
    $router->post('/sales',             [SaleController::class, 'store'],   ['sales.manage']);
    $router->get('/sales/{code}',       [SaleController::class, 'show'],    ['sales.manage']);
    $router->get('/sales/{code}/edit',  [SaleController::class, 'edit'],    ['sales.manage']);
    $router->post('/sales/{code}',      [SaleController::class, 'update'],  ['sales.manage']);
    $router->post('/sales/{code}/delete', [SaleController::class, 'destroy'], ['sales.manage']);
    $router->post('/sales/{code}/payment', [SaleController::class, 'recordPayment'], ['sales.manage']);
    $router->post('/sales/{code}/delivery', [SaleController::class, 'dispatch'], ['sales.manage']);
    $router->get('/deliveries',         [SaleController::class, 'deliveries'], ['sales.manage']);
    $router->get('/deliveries/{code}/edit', [SaleController::class, 'deliveryEdit'], ['sales.manage']);
    $router->post('/deliveries/{code}', [SaleController::class, 'deliveryUpdate'], ['sales.manage']);

    $router->get('/exports',            [ExportController::class, 'index'],   ['exports.manage']);
    $router->get('/exports/create',     [ExportController::class, 'create'],  ['exports.manage']);
    $router->post('/exports',           [ExportController::class, 'store'],   ['exports.manage']);
    $router->get('/exports/{code}',     [ExportController::class, 'show'],    ['exports.manage']);
    $router->post('/exports/{code}',    [ExportController::class, 'update'],  ['exports.manage']);
    $router->get('/exports/{code}/trace', [ExportController::class, 'trace'], ['exports.manage']);

    /* ------------------------------------------------------------------ */
    /* Knowledge centre                                                     */
    /* ------------------------------------------------------------------ */

    $router->get('/knowledge',             [KnowledgeController::class, 'index'],   ['knowledge.view']);
    $router->get('/knowledge/create',      [KnowledgeController::class, 'create'],  ['knowledge.manage']);
    $router->post('/knowledge',            [KnowledgeController::class, 'store'],   ['knowledge.manage']);
    $router->get('/knowledge/{slug}',      [KnowledgeController::class, 'show'],    ['knowledge.view']);
    $router->get('/knowledge/{slug}/edit', [KnowledgeController::class, 'edit'],    ['knowledge.manage']);
    $router->post('/knowledge/{slug}',     [KnowledgeController::class, 'update'],  ['knowledge.manage']);
    $router->post('/knowledge/{slug}/delete', [KnowledgeController::class, 'destroy'], ['knowledge.manage']);

    /* ------------------------------------------------------------------ */
    /* Reports, notifications, profile                                      */
    /* ------------------------------------------------------------------ */

    $router->get('/reports',                [ReportController::class, 'index'],   ['reports.view']);
    $router->get('/reports/{report}',       [ReportController::class, 'show'],    ['reports.view']);
    $router->get('/reports/{report}/csv',   [ReportController::class, 'csv'],     ['reports.view']);
    $router->get('/reports/{report}/print', [ReportController::class, 'printView'], ['reports.view']);

    $router->get('/notifications',            [NotificationController::class, 'index'],  ['auth']);
    $router->get('/notifications/data',       [NotificationController::class, 'data'],   ['auth']);
    $router->post('/notifications/{id}/read', [NotificationController::class, 'read'],   ['auth']);
    $router->post('/notifications/read-all',  [NotificationController::class, 'readAll'], ['auth']);

    $router->get('/profile',            [ProfileController::class, 'show'],   ['auth']);
    $router->post('/profile',           [ProfileController::class, 'update'], ['auth']);
    $router->get('/profile/password',   [ProfileController::class, 'passwordForm'], ['auth']);
    $router->post('/profile/password',  [ProfileController::class, 'changePassword'], ['auth']);

    $router->get('/search', [DashboardController::class, 'search'], ['auth']);

    /* ------------------------------------------------------------------ */
    /* Administration                                                       */
    /* ------------------------------------------------------------------ */

    $router->get('/admin',                    [SettingController::class, 'index'],   ['admin.settings']);
    $router->post('/admin/settings',          [SettingController::class, 'update'], ['admin.settings']);

    $router->get('/admin/users',            [UserController::class, 'index'],   ['admin.users']);
    $router->get('/admin/users/create',     [UserController::class, 'create'],  ['admin.users']);
    $router->post('/admin/users',           [UserController::class, 'store'],   ['admin.users']);
    $router->get('/admin/users/{id}/edit',  [UserController::class, 'edit'],    ['admin.users']);
    $router->post('/admin/users/{id}',      [UserController::class, 'update'],  ['admin.users']);
    $router->post('/admin/users/{id}/delete', [UserController::class, 'destroy'], ['admin.users']);
    $router->post('/admin/users/{id}/reset-password', [UserController::class, 'resetPassword'], ['admin.users']);

    $router->get('/admin/roles',            [RoleController::class, 'index'],   ['admin.roles']);
    $router->get('/admin/roles/{id}/edit',  [RoleController::class, 'edit'],    ['admin.roles']);
    $router->post('/admin/roles/{id}',      [RoleController::class, 'update'],  ['admin.roles']);
    $router->post('/admin/roles',           [RoleController::class, 'store'],   ['admin.roles']);
    $router->post('/admin/roles/{id}/delete', [RoleController::class, 'destroy'], ['admin.roles']);

    $router->get('/admin/audit',            [AuditLogController::class, 'index'], ['admin.audit']);
    $router->get('/admin/audit/export',     [AuditLogController::class, 'export'], ['admin.audit']);

    $router->get('/admin/reference',        [ReferenceController::class, 'index'], ['admin.settings']);
    $router->post('/admin/reference/locations', [ReferenceController::class, 'storeLocation'], ['admin.settings']);
    $router->post('/admin/reference/activity-types', [ReferenceController::class, 'storeActivityType'], ['admin.settings']);
    $router->post('/admin/reference/problem-types', [ReferenceController::class, 'storeProblemType'], ['admin.settings']);
    $router->post('/admin/reference/input-categories', [ReferenceController::class, 'storeInputCategory'], ['admin.settings']);

    /* ------------------------------------------------------------------ */
    /* REST API v1                                                          */
    /* ------------------------------------------------------------------ */

    $api = '/api/v1';

    $router->post($api . '/auth/login',  [AuthApiController::class, 'login'],    ['guest']);
    $router->post($api . '/auth/logout', [AuthApiController::class, 'logout'],   ['api']);

    /* Self-registration from the handset. Guest, and it issues no token: the
       account is pending until an administrator approves it. */
    $router->post($api . '/auth/register', [AuthApiController::class, 'register'], ['guest']);

    $router->get($api . '/auth/me',       [AuthApiController::class, 'me'],       ['api']);

    /* Every seeded demo account is flagged must_change_password, so the app
       needs somewhere to clear it. Changing the password also revokes the
       account's other device tokens. */
    $router->post($api . '/auth/password', [AuthApiController::class, 'changePassword'], ['api']);


    $router->get($api . '/farmers',            [FarmerApiController::class, 'index'],  ['api', 'farmers.view']);
    $router->post($api . '/farmers',           [FarmerApiController::class, 'store'],  ['api', 'farmers.create']);
    $router->get($api . '/farmers/{code}',     [FarmerApiController::class, 'show'],   ['api', 'farmers.view']);
    $router->post($api . '/farmers/{code}',    [FarmerApiController::class, 'update'], ['api', 'farmers.edit']);
    $router->get($api . '/farmers/{code}/gardens', [FarmerApiController::class, 'gardens'], ['api', 'farmers.view']);

    $router->get($api . '/gardens',        [GardenApiController::class, 'index'],  ['api', 'gardens.view']);
    $router->post($api . '/gardens',       [GardenApiController::class, 'store'],  ['api', 'gardens.manage']);
    $router->get($api . '/gardens/{code}', [GardenApiController::class, 'show'],   ['api', 'gardens.view']);
    $router->post($api . '/gardens/{code}',[GardenApiController::class, 'update'], ['api', 'gardens.manage']);

    $router->get($api . '/crops',      [CropApiController::class, 'index'],  ['api', 'crops.view']);
    $router->get($api . '/crops/{id}', [CropApiController::class, 'show'],   ['api', 'crops.view']);

    $router->get($api . '/activities',        [ActivityApiController::class, 'index'],  ['api', 'activities.view']);
    $router->post($api . '/activities',       [ActivityApiController::class, 'store'],  ['api', 'activities.manage']);
    $router->post($api . '/activities/{code}/complete', [ActivityApiController::class, 'complete'], ['api', 'activities.view']);

    $router->get($api . '/inspections',   [InspectionApiController::class, 'index'], ['api', 'inspections.view']);
    $router->post($api . '/inspections',  [InspectionApiController::class, 'store'], ['api', 'inspections.manage']);

    $router->get($api . '/problems',  [ProblemApiController::class, 'index'], ['api', 'problems.view']);
    $router->post($api . '/problems', [ProblemApiController::class, 'store'], ['api', 'problems.manage']);

    $router->get($api . '/harvests',   [HarvestApiController::class, 'index'], ['api', 'harvest.view']);
    $router->post($api . '/harvests',  [HarvestApiController::class, 'store'], ['api', 'harvest.manage']);

    $router->get($api . '/notifications',         [NotificationApiController::class, 'index'], ['api']);
    $router->post($api . '/notifications/{id}/read', [NotificationApiController::class, 'read'], ['api']);

    $router->post($api . '/sync/push', [SyncApiController::class, 'push'], ['api']);
    $router->get($api . '/inputs',    [InputApiController::class, 'index'],   ['api', 'inputs.view']);
    $router->get($api . '/sync/pull',  [SyncApiController::class, 'pull'], ['api']);

    /* Pick lists the offline capture forms need to hold locally. Guarded on the
       token alone: the farmer role holds no permissions yet must still be able
       to name a problem type when reporting one. */
    $router->get($api . '/reference', [ReferenceApiController::class, 'index'], ['api']);

    /* Photo evidence. The base64-in-JSON body is deliberate so a photo retries
       through the same offline queue as the record it belongs to. */
    $router->get($api . '/attachments',  [AttachmentApiController::class, 'index'], ['api']);
    $router->post($api . '/attachments', [AttachmentApiController::class, 'store'], ['api']);


    /* ------------------------------------------------------------------ */
    /* Farmer / officer mobile portal                                        */
    /* ------------------------------------------------------------------ */

    $router->get('/portal',      [DashboardController::class, 'portal'],   ['auth']);
    $router->get('/portal/activity/{code}',  [DashboardController::class, 'portalActivity'],         ['auth']);
    $router->post('/portal/activity/{code}', [DashboardController::class, 'completePortalActivity'],  ['auth']);
};

