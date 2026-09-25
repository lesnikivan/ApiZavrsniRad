<?php

namespace App\Controllers;

use App\Models\PlantAnalysisRepository;
use App\Models\WorkflowRepository;
use App\Services\Orchestrator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PlantController
{
    private Orchestrator $orchestrator;
    private PlantAnalysisRepository $repository;
    private WorkflowRepository $workflowRepository;

    public function __construct(
        Orchestrator $orchestrator,
        PlantAnalysisRepository $repository,
        WorkflowRepository $workflowRepository
    ) {
        $this->orchestrator = $orchestrator;
        $this->repository = $repository;
        $this->workflowRepository = $workflowRepository;
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
            $analysis = $this->orchestrator->run($imageBase64, $mimeType, $imageUri);
        } catch (\Throwable $exception) {
            return $this->json($response, [
                'error' => $exception->getMessage(),
            ], 502);
        }

        return $this->json($response, $analysis, 201);
    }

    public function history(Request $request, Response $response): Response
    {
        return $this->json($response, $this->repository->all());
    }

    public function workflowRuns(Request $request, Response $response): Response
    {
        return $this->json($response, $this->workflowRepository->runs());
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
