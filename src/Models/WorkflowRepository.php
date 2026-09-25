<?php

namespace App\Models;

use PDO;
use PDOException;

class WorkflowRepository
{
    private string $databaseUrl;
    private ?PDO $database = null;

    public function __construct(string $databaseUrl = '')
    {
        $this->databaseUrl = trim($databaseUrl);
    }

    public function activeSteps(): array
    {
        if (!$this->canUseDatabase()) {
            return [];
        }

        try {
            $statement = $this->database()->query(
                "SELECT w.id AS workflow_id, ws.service_key, ws.step_order, ws.configuration
                 FROM workflows w
                 JOIN workflow_steps ws ON ws.workflow_id = w.id
                 JOIN orchestration_services os ON os.service_key = ws.service_key
                 WHERE w.enabled = true AND ws.enabled = true AND os.enabled = true
                 ORDER BY w.created_at DESC, ws.step_order ASC"
            );

            return $statement->fetchAll();
        } catch (PDOException $exception) {
            return [];
        }
    }

    public function startRun(?string $workflowId): ?string
    {
        if (!$this->canUseDatabase()) {
            return null;
        }

        try {
            $runId = 'run-' . bin2hex(random_bytes(8));
            $statement = $this->database()->prepare(
                'INSERT INTO workflow_runs (id, workflow_id, status) VALUES (:id, :workflow_id, :status)'
            );
            $statement->execute([
                'id' => $runId,
                'workflow_id' => $workflowId,
                'status' => 'running',
            ]);

            return $runId;
        } catch (PDOException $exception) {
            return null;
        }
    }

    public function finishRun(?string $runId, string $status, ?string $errorMessage = null): void
    {
        if ($runId === null || !$this->canUseDatabase()) {
            return;
        }

        try {
            $statement = $this->database()->prepare(
                'UPDATE workflow_runs SET status = :status, finished_at = now(), error_message = :error_message WHERE id = :id'
            );
            $statement->execute([
                'id' => $runId,
                'status' => $status,
                'error_message' => $errorMessage,
            ]);
        } catch (PDOException $exception) {
        }
    }

    public function logStep(?string $runId, string $serviceKey, int $stepOrder, string $status, ?string $message = null): void
    {
        if ($runId === null || !$this->canUseDatabase()) {
            return;
        }

        try {
            if ($status === 'running') {
                $statement = $this->database()->prepare(
                    'INSERT INTO workflow_logs (run_id, service_key, step_order, status, message) VALUES (:run_id, :service_key, :step_order, :status, :message)'
                );
                $statement->execute([
                    'run_id' => $runId,
                    'service_key' => $serviceKey,
                    'step_order' => $stepOrder,
                    'status' => $status,
                    'message' => $message,
                ]);
                return;
            }

            $statement = $this->database()->prepare(
                'UPDATE workflow_logs SET status = :status, message = :message, finished_at = now()
                 WHERE id = (SELECT id FROM workflow_logs WHERE run_id = :run_id AND service_key = :service_key AND step_order = :step_order ORDER BY id DESC LIMIT 1)'
            );
            $statement->execute([
                'run_id' => $runId,
                'service_key' => $serviceKey,
                'step_order' => $stepOrder,
                'status' => $status,
                'message' => $message,
            ]);
        } catch (PDOException $exception) {
        }
    }

    public function runs(): array
    {
        if (!$this->canUseDatabase()) {
            return [];
        }

        try {
            $statement = $this->database()->query(
                'SELECT id, workflow_id, status, started_at, finished_at, error_message FROM workflow_runs ORDER BY started_at DESC LIMIT 50'
            );

            return $statement->fetchAll();
        } catch (PDOException $exception) {
            return [];
        }
    }

    public function services(): array
    {
        if (!$this->canUseDatabase()) {
            return [];
        }

        try {
            return $this->database()->query(
                'SELECT id, service_key, display_name, enabled, created_at FROM orchestration_services ORDER BY created_at'
            )->fetchAll();
        } catch (PDOException $exception) {
            return [];
        }
    }

    public function workflows(): array
    {
        if (!$this->canUseDatabase()) {
            return [];
        }

        try {
            $workflows = $this->database()->query(
                'SELECT id, name, enabled, created_at FROM workflows ORDER BY created_at DESC'
            )->fetchAll();
            $steps = $this->database()->query(
                'SELECT workflow_id, service_key, step_order, configuration, enabled FROM workflow_steps ORDER BY workflow_id, step_order'
            )->fetchAll();

            foreach ($workflows as &$workflow) {
                $workflow['steps'] = array_values(array_filter($steps, static function (array $step) use ($workflow): bool {
                    return $step['workflow_id'] === $workflow['id'];
                }));
            }

            return $workflows;
        } catch (PDOException $exception) {
            return [];
        }
    }

    public function setServiceEnabled(string $serviceKey, bool $enabled): bool
    {
        if (!$this->canUseDatabase()) {
            return false;
        }

        try {
            $statement = $this->database()->prepare(
                'UPDATE orchestration_services SET enabled = :enabled WHERE service_key = :service_key'
            );
            $statement->execute([
                'service_key' => $serviceKey,
                'enabled' => $enabled,
            ]);

            return $statement->rowCount() > 0;
        } catch (PDOException $exception) {
            return false;
        }
    }

    public function updateWorkflowSteps(string $workflowId, array $steps): bool
    {
        if (!$this->canUseDatabase() || count($steps) === 0) {
            return false;
        }

        $database = $this->database();

        try {
            $database->beginTransaction();
            $workflowStatement = $database->prepare('SELECT id FROM workflows WHERE id = :id FOR UPDATE');
            $workflowStatement->execute(['id' => $workflowId]);

            if ($workflowStatement->fetch() === false) {
                $database->rollBack();
                return false;
            }

            $database->prepare('DELETE FROM workflow_steps WHERE workflow_id = :workflow_id')
                ->execute(['workflow_id' => $workflowId]);
            $statement = $database->prepare(
                'INSERT INTO workflow_steps (workflow_id, service_key, step_order, configuration, enabled)
                 VALUES (:workflow_id, :service_key, :step_order, CAST(:configuration AS jsonb), :enabled)'
            );

            foreach (array_values($steps) as $index => $step) {
                $serviceKey = (string)($step['serviceKey'] ?? $step['service_key'] ?? '');

                if ($serviceKey === '') {
                    throw new PDOException('Each workflow step requires a serviceKey.');
                }

                $statement->execute([
                    'workflow_id' => $workflowId,
                    'service_key' => $serviceKey,
                    'step_order' => $index + 1,
                    'configuration' => json_encode($step['configuration'] ?? []),
                    'enabled' => (bool)($step['enabled'] ?? true),
                ]);
            }

            $database->commit();
            return true;
        } catch (PDOException $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }

            return false;
        }
    }

    public function logs(string $runId): array
    {
        if (!$this->canUseDatabase()) {
            return [];
        }

        try {
            $statement = $this->database()->prepare(
                'SELECT id, run_id, service_key, step_order, status, message, started_at, finished_at
                 FROM workflow_logs WHERE run_id = :run_id ORDER BY step_order, id'
            );
            $statement->execute(['run_id' => $runId]);

            return $statement->fetchAll();
        } catch (PDOException $exception) {
            return [];
        }
    }

    private function canUseDatabase(): bool
    {
        return $this->databaseUrl !== '' && $this->databaseUrl !== 'postgresql://';
    }

    private function database(): PDO
    {
        if ($this->database instanceof PDO) {
            return $this->database;
        }

        $parts = parse_url($this->databaseUrl);

        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['postgres', 'postgresql'], true)) {
            throw new PDOException('DATABASE_URL must be a PostgreSQL connection string.');
        }

        $query = [];
        parse_str($parts['query'] ?? '', $query);
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
            $parts['host'] ?? 'localhost',
            $parts['port'] ?? 5432,
            ltrim($parts['path'] ?? '', '/') ?: 'postgres',
            $query['sslmode'] ?? 'prefer'
        );

        $this->database = new PDO($dsn, $parts['user'] ?? '', $parts['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        return $this->database;
    }
}
