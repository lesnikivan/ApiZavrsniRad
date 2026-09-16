<?php

namespace App\Services;

class TrelloService
{
    private string $key;
    private string $token;
    private string $listId;

    public function __construct(string $key = '', string $token = '', string $listId = '')
    {
        $this->key = $key;
        $this->token = $token;
        $this->listId = $listId;
    }

    public function createCards(array $analysis): array
    {
        if ($this->key === '' || $this->token === '' || $this->listId === '') {
            return $analysis;
        }

        foreach ($analysis['tasks'] as $index => $task) {
            $card = $this->createCard($analysis, $task);

            if (isset($card['id'])) {
                $analysis['tasks'][$index]['status'] = 'sent_to_trello';
                $analysis['tasks'][$index]['trelloCardId'] = $card['id'];
                $analysis['tasks'][$index]['trelloUrl'] = $card['shortUrl'] ?? null;
            }
        }

        return $analysis;
    }

    private function createCard(array $analysis, array $task): array
    {
        $description = implode("\n\n", [
            $task['description'] ?? '',
            'Ucestalost: ' . ($task['frequency'] ?? 'Po potrebi'),
            'Biljka: ' . ($analysis['plantName'] ?? 'Nepoznata biljka'),
            'Sažetak: ' . ($analysis['summary'] ?? ''),
        ]);

        $payload = http_build_query([
            'key' => $this->key,
            'token' => $this->token,
            'idList' => $this->listId,
            'name' => ($analysis['plantName'] ?? 'Biljka') . ': ' . ($task['title'] ?? 'Zadatak njege'),
            'desc' => $description,
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $payload,
                'ignore_errors' => true,
                'timeout' => 20,
            ],
        ]);

        $body = file_get_contents('https://api.trello.com/1/cards', false, $context);

        if ($body === false) {
            return [];
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
