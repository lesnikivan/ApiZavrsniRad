<?php

namespace App\Models;

use PDO;
use PDOException;

class PlantAnalysisRepository
{
    private string $databaseUrl;
    private string $table;
    private string $fallbackFile;
    private ?PDO $database = null;

    public function __construct(string $databaseUrl = '', string $table = 'plant_analyses')
    {
        $this->databaseUrl = trim($databaseUrl);
        $this->table = $table ?: 'plant_analyses';
        $this->fallbackFile = __DIR__ . '/../../storage/plant_analyses.json';
    }

    public function save(array $analysis): array
    {
        if ($this->canUseDatabase()) {
            try {
                $statement = $this->database()->prepare(sprintf(
                    'INSERT INTO %s (id, plant_name, scientific_name, confidence, image_uri, summary, tasks, created_at)
                     VALUES (:id, :plant_name, :scientific_name, :confidence, :image_uri, :summary, CAST(:tasks AS jsonb), :created_at)
                     RETURNING id, plant_name, scientific_name, confidence, image_uri, summary, tasks, created_at',
                    $this->table
                ));
                $statement->execute($this->toDatabaseRow($analysis));

                return $this->fromDatabaseRow((array)$statement->fetch());
            } catch (PDOException $exception) {
                // Keep local development usable when the remote database is unavailable.
            }
        }

        return $this->saveToFile($analysis);
    }

    public function all(): array
    {
        if ($this->canUseDatabase()) {
            try {
                $statement = $this->database()->query(sprintf(
                    'SELECT id, plant_name, scientific_name, confidence, image_uri, summary, tasks, created_at
                     FROM %s ORDER BY created_at DESC',
                    $this->table
                ));

                return array_map([$this, 'fromDatabaseRow'], $statement->fetchAll());
            } catch (PDOException $exception) {
                // Keep local development usable when the remote database is unavailable.
            }
        }

        return array_reverse($this->readFallbackRows());
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

        $this->database = new PDO(
            $dsn,
            $parts['user'] ?? '',
            $parts['pass'] ?? '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        return $this->database;
    }

    private function toDatabaseRow(array $analysis): array
    {
        return [
            'id' => $analysis['id'],
            'plant_name' => $analysis['plantName'],
            'scientific_name' => $analysis['scientificName'] ?? null,
            'confidence' => $analysis['confidence'],
            'image_uri' => $analysis['imageUri'],
            'summary' => $analysis['summary'],
            'tasks' => json_encode($analysis['tasks']),
            'created_at' => $analysis['createdAt'],
        ];
    }

    private function saveToFile(array $analysis): array
    {
        $rows = $this->readFallbackRows();
        $rows[] = $analysis;

        $directory = dirname($this->fallbackFile);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($this->fallbackFile, json_encode($rows, JSON_PRETTY_PRINT));

        return $analysis;
    }

    private function readFallbackRows(): array
    {
        if (!file_exists($this->fallbackFile)) {
            return [];
        }

        $decoded = json_decode((string)file_get_contents($this->fallbackFile), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function fromDatabaseRow(array $row): array
    {
        return [
            'id' => $row['id'],
            'plantName' => $row['plant_name'],
            'scientificName' => $row['scientific_name'] ?? null,
            'confidence' => (float)$row['confidence'],
            'imageUri' => $row['image_uri'] ?? '',
            'summary' => $row['summary'],
            'tasks' => is_string($row['tasks']) ? json_decode($row['tasks'], true) : ($row['tasks'] ?? []),
            'createdAt' => $row['created_at'],
        ];
    }
}
