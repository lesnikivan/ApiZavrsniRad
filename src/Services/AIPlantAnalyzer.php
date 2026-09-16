<?php

namespace App\Services;

class AIPlantAnalyzer
{
    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey = '', string $model = 'gemini-3.6-flash')
    {
        $this->apiKey = $apiKey;
        $this->model = $model ?: 'gemini-3.6-flash';
    }

    public function analyze(string $imageBase64, string $mimeType, string $imageUri = ''): array
    {
        if ($this->apiKey === '') {
            return $this->demoAnalysis($imageUri);
        }

        $prompt = implode("\n", [
            'Identificiraj biljku na fotografiji.',
            'Vrati iskljucivo JSON bez markdowna u ovom obliku:',
            '{"plantName":"...","scientificName":"...","confidence":0.85,"summary":"...","tasks":[{"title":"...","description":"...","frequency":"..."}]}',
            'Zadaci trebaju biti konkretne radnje njege biljke prikladne za Trello kartice.',
            'Odgovori na hrvatskom jeziku.'
        ]);

        $payload = [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    [
                        'inline_data' => [
                            'mime_type' => $mimeType,
                            'data' => $imageBase64,
                        ],
                    ],
                ],
            ]],
            'generationConfig' => [
                'response_mime_type' => 'application/json',
                'temperature' => 0.2,
            ],
        ];

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            rawurlencode($this->model),
            rawurlencode($this->apiKey)
        );

        $response = $this->postJson($url, $payload);
        $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $decoded = $this->decodeModelJson($text);

        if ($decoded === null) {
            return $this->demoAnalysis($imageUri);
        }

        return $this->normalizeAnalysis($decoded, $imageUri);
    }

    private function normalizeAnalysis(array $data, string $imageUri): array
    {
        $tasks = [];

        foreach (($data['tasks'] ?? []) as $index => $task) {
            $tasks[] = [
                'id' => 'task-' . ($index + 1),
                'title' => (string)($task['title'] ?? 'Zadatak njege'),
                'description' => (string)($task['description'] ?? 'Provjeri stanje biljke i prilagodi njegu.'),
                'frequency' => (string)($task['frequency'] ?? 'Po potrebi'),
                'status' => 'planned',
            ];
        }

        if (count($tasks) === 0) {
            $tasks = $this->demoAnalysis($imageUri)['tasks'];
        }

        return [
            'id' => 'analysis-' . bin2hex(random_bytes(8)),
            'plantName' => (string)($data['plantName'] ?? 'Nepoznata biljka'),
            'scientificName' => isset($data['scientificName']) ? (string)$data['scientificName'] : null,
            'confidence' => max(0, min(1, (float)($data['confidence'] ?? 0.7))),
            'imageUri' => $imageUri,
            'summary' => (string)($data['summary'] ?? 'AI je pripremio osnovne upute za njegu.'),
            'tasks' => $tasks,
            'createdAt' => gmdate('c'),
        ];
    }

    private function decodeModelJson(string $text): ?array
    {
        $clean = trim($text);
        $clean = preg_replace('/^```json\s*|^```\s*|```$/m', '', $clean) ?? $clean;
        $decoded = json_decode(trim($clean), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function postJson(string $url, array $payload): array
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => json_encode($payload),
                    'ignore_errors' => true,
                    'timeout' => 45,
                ],
            ]);

            $body = file_get_contents($url, false, $context);

            if ($body === false) {
                throw new \RuntimeException('Gemini API request failed.');
            }

            $decoded = json_decode($body, true);

            if (!is_array($decoded)) {
                throw new \RuntimeException('Gemini API returned invalid JSON.');
            }

            $statusLine = $http_response_header[0] ?? '';
            preg_match('/\s(\d{3})\s/', $statusLine, $statusMatch);
            $statusCode = isset($statusMatch[1]) ? (int)$statusMatch[1] : 0;

            if ($statusCode < 400 && !isset($decoded['error'])) {
                return $decoded;
            }

            $message = $decoded['error']['message'] ?? 'Gemini API request failed.';
            $isTransient = in_array($statusCode, [429, 500, 502, 503, 504], true);

            if (!$isTransient || $attempt === 2) {
                throw new \RuntimeException(sprintf('Gemini API error (%d): %s', $statusCode, $message));
            }

            sleep($attempt + 1);
        }

        throw new \RuntimeException('Gemini API request failed after retries.');
    }

    private function demoAnalysis(string $imageUri): array
    {
        return [
            'id' => 'demo-' . time(),
            'plantName' => 'Monstera deliciosa',
            'scientificName' => 'Monstera deliciosa',
            'confidence' => 0.91,
            'imageUri' => $imageUri,
            'summary' => 'Biljka voli jako indirektno svjetlo, umjereno zalijevanje i propusnu zemlju.',
            'tasks' => [
                [
                    'id' => 'task-water',
                    'title' => 'Zaliti biljku',
                    'description' => 'Provjeri prvih nekoliko centimetara zemlje i zalij kada je suha.',
                    'frequency' => 'Svaki 7. dan',
                    'status' => 'planned',
                ],
                [
                    'id' => 'task-clean',
                    'title' => 'Obrisati listove',
                    'description' => 'Ukloni prasinu vlaznom krpom kako bi listovi bolje upijali svjetlo.',
                    'frequency' => 'Svaka 2 tjedna',
                    'status' => 'planned',
                ],
                [
                    'id' => 'task-fertilize',
                    'title' => 'Dodati gnojivo',
                    'description' => 'Koristi blago tekuce gnojivo u sezoni rasta.',
                    'frequency' => 'Jednom mjesecno',
                    'status' => 'planned',
                ],
            ],
            'createdAt' => gmdate('c'),
        ];
    }
}