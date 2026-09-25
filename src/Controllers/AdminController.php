<?php

namespace App\Controllers;

use App\Models\WorkflowRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    private WorkflowRepository $workflowRepository;
    private string $adminKey;

    public function __construct(WorkflowRepository $workflowRepository, string $adminKey = '')
    {
        $this->workflowRepository = $workflowRepository;
        $this->adminKey = trim($adminKey);
    }

    public function services(Request $request, Response $response): Response
    {
        if (($unauthorized = $this->authorize($request, $response)) !== null) {
            return $unauthorized;
        }

        return $this->json($response, $this->workflowRepository->services());
    }

    public function status(Request $request, Response $response): Response
    {
        if (($unauthorized = $this->authorize($request, $response)) !== null) {
            return $unauthorized;
        }

        return $this->json($response, $this->workflowRepository->status());
    }

    public function enableService(Request $request, Response $response): Response
    {
        if (($unauthorized = $this->authorize($request, $response)) !== null) {
            return $unauthorized;
        }

        $serviceKey = (string)($request->getAttribute('serviceKey') ?? '');
        $payload = (array)($request->getParsedBody() ?? []);
        if (!array_key_exists('enabled', $payload)) {
            return $this->json($response, ['error' => 'enabled is required.'], 422);
        }

        if (!$this->workflowRepository->setServiceEnabled($serviceKey, (bool)$payload['enabled'])) {
            return $this->json($response, ['error' => 'Service was not found or could not be updated.'], 404);
        }

        return $this->json($response, ['serviceKey' => $serviceKey, 'enabled' => (bool)$payload['enabled']]);
    }

    public function workflows(Request $request, Response $response): Response
    {
        if (($unauthorized = $this->authorize($request, $response)) !== null) {
            return $unauthorized;
        }

        return $this->json($response, $this->workflowRepository->workflows());
    }

    public function updateSteps(Request $request, Response $response): Response
    {
        if (($unauthorized = $this->authorize($request, $response)) !== null) {
            return $unauthorized;
        }

        $workflowId = (string)($request->getAttribute('workflowId') ?? '');
        $payload = (array)($request->getParsedBody() ?? []);
        $steps = $payload['steps'] ?? null;

        if (!is_array($steps) || count($steps) === 0) {
            return $this->json($response, ['error' => 'steps must be a non-empty array.'], 422);
        }

        if (!$this->workflowRepository->updateWorkflowSteps($workflowId, $steps)) {
            return $this->json($response, ['error' => 'Workflow or service configuration is invalid.'], 422);
        }

        return $this->json($response, [
            'workflowId' => $workflowId,
            'steps' => $steps,
            'message' => 'Workflow steps updated.',
        ]);
    }

    public function runs(Request $request, Response $response): Response
    {
        if (($unauthorized = $this->authorize($request, $response)) !== null) {
            return $unauthorized;
        }

        return $this->json($response, $this->workflowRepository->runs());
    }

    public function logs(Request $request, Response $response): Response
    {
        if (($unauthorized = $this->authorize($request, $response)) !== null) {
            return $unauthorized;
        }

        $runId = (string)($request->getAttribute('runId') ?? '');

        return $this->json($response, $this->workflowRepository->logs($runId));
    }

    private function authorize(Request $request, Response $response): ?Response
    {
        $providedKey = $request->getHeaderLine('X-Admin-Key');

        if ($providedKey === '' && preg_match('/^Bearer\s+(.+)$/i', $request->getHeaderLine('Authorization'), $matches)) {
            $providedKey = $matches[1];
        }

        if ($this->adminKey !== '' && hash_equals($this->adminKey, $providedKey)) {
            return null;
        }

        $response->getBody()->write(json_encode(['error' => 'Admin authorization required.']));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(401);
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
