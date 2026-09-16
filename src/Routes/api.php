<?php

use App\Controllers\PlantController;
use App\Models\PlantAnalysisRepository;
use App\Services\AIPlantAnalyzer;
use App\Services\TrelloService;

$env = static function (string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? getenv($key);

    return is_string($value) && $value !== '' ? $value : $default;
};

$controller = new PlantController(
    new AIPlantAnalyzer(
        $env('GEMINI_API_KEY'),
        $env('GEMINI_MODEL', 'gemini-3.6-flash')
    ),
    new TrelloService(
        $env('TRELLO_KEY'),
        $env('TRELLO_TOKEN'),
        $env('TRELLO_LIST_ID')
    ),
    new PlantAnalysisRepository(
        $env('DATABASE_URL'),
        $env('DATABASE_TABLE', 'plant_analyses')
    )
);

$app->get('/', [$controller, 'health']);
$app->get('/health', [$controller, 'health']);
$app->post('/analyze-plant', [$controller, 'analyze']);
$app->get('/plants', [$controller, 'history']);
