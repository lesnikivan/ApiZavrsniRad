<?php

namespace App\Controllers;

use App\Models\PlantAnalysisRepository;
use App\Services\AIPlantAnalyzer;
use App\Services\TrelloService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PlantController
{
    private AIPlantAnalyzer $analyzer;
    private TrelloService $trello;
    private PlantAnalysisRepository $repository;

    public function __construct(
        AIPlantAnalyzer $analyzer,
        TrelloService $trello,
        PlantAnalysisRepository $repository
    ) {
        $this->analyzer = $analyzer;
        $this->trello = $trello;
        $this->repository = $repository;
    }

    public function health(Request $request, Response $response): Response
    {
        return $this->json($response, [
            'status' => 'ok',
            'service' => 'plant-care-backend',
        ]);
    }

    public function analyze(Request $request, Response $response): Response
    {
        $payload = (array)($request->getParsedBody() ?? []);
        $imageBase64 = trim((string)($payload['imageBase64'] ?? ''));
        $imageUri = (string)($payload['imageUri'] ?? '');
        $mimeType = (string)($payload['mimeType'] ?? 'image/jpeg');

        if ($imageBase64 === '') {
            return $this->json($response, [
                'error' => 'imageBase64 is required.',
            ], 422);
        }

        try {
            $analysis = $this->analyzer->analyze($imageBase64, $mimeType, $imageUri);
        } catch (\Throwable $exception) {
            return $this->json($response, [
                'error' => $exception->getMessage(),
            ], 502);
        }

        $analysis = $this->trello->createCards($analysis);
        $analysis = $this->repository->save($analysis);

        return $this->json($response, $analysis, 201);
    }

    public function history(Request $request, Response $response): Response
    {
        return $this->json($response, $this->repository->all());
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
