<?php

use App\Controllers\AdminController;
use App\Controllers\PlantController;
use App\Models\PlantAnalysisRepository;
use App\Models\WorkflowRepository;
use App\Services\AIPlantAnalyzer;
use App\Services\Orchestrator;
use App\Services\TrelloService;

$env = static function (string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? getenv($key);

    return is_string($value) && $value !== '' ? $value : $default;
};

$analysisRepository = new PlantAnalysisRepository(
    $env('DATABASE_URL'),
    $env('DATABASE_TABLE', 'plant_analyses')
);
$workflowRepository = new WorkflowRepository($env('DATABASE_URL'));

$controller = new PlantController(
    new Orchestrator(
        new AIPlantAnalyzer(
            $env('GEMINI_API_KEY'),
            $env('GEMINI_MODEL', 'gemini-3.6-flash')
        ),
        new TrelloService(
            $env('TRELLO_KEY'),
            $env('TRELLO_TOKEN'),
            $env('TRELLO_LIST_ID')
        ),
        $analysisRepository,
        $workflowRepository
    ),
    $analysisRepository,
    $workflowRepository
);
$adminController = new AdminController($workflowRepository, $env('ADMIN_API_KEY'));

$app->get('/', [$controller, 'health']);
$app->get('/health', [$controller, 'health']);
$app->post('/analyze-plant', [$controller, 'analyze']);
$app->get('/plants', [$controller, 'history']);
$app->get('/admin/services', [$adminController, 'services']);
$app->put('/admin/services/{serviceKey}', [$adminController, 'enableService']);
$app->get('/admin/workflows', [$adminController, 'workflows']);
$app->put('/admin/workflows/{workflowId}/steps', [$adminController, 'updateSteps']);
$app->get('/admin/workflow-runs', [$adminController, 'runs']);
$app->get('/admin/workflow-runs/{runId}/logs', [$adminController, 'logs']);
