<?php

namespace App\Services;

use App\Models\PlantAnalysisRepository;
use App\Models\WorkflowRepository;
use RuntimeException;

class Orchestrator
{
    private AIPlantAnalyzer $analyzer;
    private TrelloService $trello;
    private PlantAnalysisRepository $analysisRepository;
    private WorkflowRepository $workflowRepository;

    public function __construct(
        AIPlantAnalyzer $analyzer,
        TrelloService $trello,
        PlantAnalysisRepository $analysisRepository,
        WorkflowRepository $workflowRepository
    ) {
        $this->analyzer = $analyzer;
        $this->trello = $trello;
        $this->analysisRepository = $analysisRepository;
        $this->workflowRepository = $workflowRepository;
    }

    public function run(string $imageBase64, string $mimeType, string $imageUri = ''): array
    {
        $steps = $this->workflowRepository->activeSteps();

        if (count($steps) === 0) {
            $steps = [
                ['workflow_id' => null, 'service_key' => 'ai_analysis', 'step_order' => 1],
                ['workflow_id' => null, 'service_key' => 'trello_cards', 'step_order' => 2],
                ['workflow_id' => null, 'service_key' => 'save_analysis', 'step_order' => 3],
            ];
        }

        $workflowId = $steps[0]['workflow_id'] ?? null;
        $runId = $this->workflowRepository->startRun($workflowId);
        $analysis = null;
        $currentServiceKey = null;
        $currentStepOrder = 0;

        try {
            foreach ($steps as $step) {
                $serviceKey = (string)$step['service_key'];
                $stepOrder = (int)$step['step_order'];
                $currentServiceKey = $serviceKey;
                $currentStepOrder = $stepOrder;
                $this->workflowRepository->logStep($runId, $serviceKey, $stepOrder, 'running');

                if ($serviceKey === 'ai_analysis') {
                    $analysis = $this->analyzer->analyze($imageBase64, $mimeType, $imageUri);
                } elseif ($serviceKey === 'trello_cards') {
                    $this->requireAnalysis($analysis, $serviceKey);
                    $analysis = $this->trello->createCards($analysis);
                } elseif ($serviceKey === 'save_analysis') {
                    $this->requireAnalysis($analysis, $serviceKey);
                    $analysis = $this->analysisRepository->save($analysis);
                } else {
                    throw new RuntimeException('Nepoznat orkestracijski servis: ' . $serviceKey);
                }

                $this->workflowRepository->logStep($runId, $serviceKey, $stepOrder, 'completed');
            }

            if (!is_array($analysis)) {
                throw new RuntimeException('Workflow nije proizveo analizu biljke.');
            }

            $this->workflowRepository->finishRun($runId, 'completed');
            $analysis['workflowRunId'] = $runId;

            return $analysis;
        } catch (\Throwable $exception) {
            if ($currentServiceKey !== null) {
                $this->workflowRepository->logStep(
                    $runId,
                    $currentServiceKey,
                    $currentStepOrder,
                    'failed',
                    $exception->getMessage()
                );
            }
            $this->workflowRepository->finishRun($runId, 'failed', $exception->getMessage());
            throw new RuntimeException(
                sprintf('Orkestracija nije uspjela%s: %s', $runId ? ' (run ' . $runId . ')' : '', $exception->getMessage()),
                0,
                $exception
            );
        }
    }

    private function requireAnalysis(?array $analysis, string $serviceKey): void
    {
        if ($analysis === null) {
            throw new RuntimeException(sprintf('Servis %s zahtijeva prethodni AI korak.', $serviceKey));
        }
    }
}
