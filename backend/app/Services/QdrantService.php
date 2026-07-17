<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QdrantService
{
    protected ?string $url;
    protected ?string $apiKey;
    protected string $collection = 'notes_collection';
    protected string $mockFilePath;

    public function __construct()
    {
        $this->url = env('QDRANT_URL') ?: 'http://localhost:6333';
        $this->apiKey = env('QDRANT_API_KEY');
        $this->mockFilePath = storage_path('app/qdrant_mock.json');
    }

    /**
     * Get headers for Qdrant API.
     */
    protected function getHeaders(): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if (!empty($this->apiKey)) {
            $headers['api-key'] = $this->apiKey;
        }

        return $headers;
    }

    /**
     * Create the notes collection in Qdrant.
     */
    public function createCollection(): bool
    {
        if ($this->isRealQdrantActive()) {
            try {
                // Check if collection exists
                $checkResponse = Http::withHeaders($this->getHeaders())
                    ->get("{$this->url}/collections/{$this->collection}");

                if ($checkResponse->successful()) {
                    return true;
                }

                // Create collection (768 dimensions for text-embedding-004, Cosine distance)
                $response = Http::withHeaders($this->getHeaders())
                    ->put("{$this->url}/collections/{$this->collection}", [
                        'vectors' => [
                            'size' => 768,
                            'distance' => 'Cosine'
                        ]
                    ]);

                if ($response->successful()) {
                    return true;
                }
                Log::warning('Qdrant collection creation failed: ' . $response->body());
            } catch (\Exception $e) {
                Log::error('Error creating Qdrant collection: ' . $e->getMessage());
            }
        }

        // Fallback collection initialization (make sure JSON file exists)
        if (!file_exists($this->mockFilePath)) {
            file_put_contents($this->mockFilePath, json_encode([]));
        }
        return true;
    }

    /**
     * Store (upsert) a vector in Qdrant.
     */
    public function storeVector(int $id, array $vector, array $payload): bool
    {
        if ($this->isRealQdrantActive()) {
            try {
                $response = Http::withHeaders($this->getHeaders())
                    ->put("{$this->url}/collections/{$this->collection}/points?wait=true", [
                        'points' => [
                            [
                                'id' => $id,
                                'vector' => $vector,
                                'payload' => $payload
                            ]
                        ]
                    ]);

                if ($response->successful()) {
                    return true;
                }
                Log::warning('Qdrant store vector failed: ' . $response->body());
            } catch (\Exception $e) {
                Log::error('Error saving Qdrant vector: ' . $e->getMessage());
            }
        }

        return $this->storeLocalVector($id, $vector, $payload);
    }

    /**
     * Delete a vector from Qdrant.
     */
    public function deleteVector(int $id): bool
    {
        if ($this->isRealQdrantActive()) {
            try {
                $response = Http::withHeaders($this->getHeaders())
                    ->post("{$this->url}/collections/{$this->collection}/points/delete?wait=true", [
                        'points' => [$id]
                    ]);

                if ($response->successful()) {
                    return true;
                }
                Log::warning('Qdrant delete vector failed: ' . $response->body());
            } catch (\Exception $e) {
                Log::error('Error deleting Qdrant vector: ' . $e->getMessage());
            }
        }

        return $this->deleteLocalVector($id);
    }

    /**
     * Search similar vectors in Qdrant. Returns array of match structures containing payload and score.
     */
    public function searchVector(array $vector, ?int $userId = null, int $limit = 10): array
    {
        if ($this->isRealQdrantActive()) {
            try {
                $body = [
                    'vector' => $vector,
                    'limit' => $limit,
                    'with_payload' => true
                ];

                // Filter search results by user_id payload if provided
                if ($userId !== null) {
                    $body['filter'] = [
                        'must' => [
                            [
                                'key' => 'user_id',
                                'match' => ['value' => $userId]
                            ]
                        ]
                    ];
                }

                $response = Http::withHeaders($this->getHeaders())
                    ->post("{$this->url}/collections/{$this->collection}/points/search", $body);

                if ($response->successful()) {
                    $data = $response->json();
                    $matches = [];
                    foreach ($data['result'] ?? [] as $match) {
                        $matches[] = [
                            'id' => $match['id'],
                            'score' => $match['score'],
                            'payload' => $match['payload'] ?? []
                        ];
                    }
                    return $matches;
                }
                Log::warning('Qdrant search vector failed: ' . $response->body());
            } catch (\Exception $e) {
                Log::error('Error searching Qdrant: ' . $e->getMessage());
            }
        }

        return $this->searchLocalVector($vector, $userId, $limit);
    }

    /**
     * Helper to verify if Qdrant credentials are active and online.
     */
    protected function isRealQdrantActive(): bool
    {
        // If user explicitly configured cloud or localhost other than default fallback
        if (env('QDRANT_URL') || env('QDRANT_API_KEY')) {
            return true;
        }

        // Quick ping to check if local Docker Qdrant is listening on port 6333
        try {
            $response = Http::timeout(1)->get("{$this->url}/readyz");
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    // ==========================================
    // LOCAL FLAT-FILE FALLBACK WORKFLOW
    // ==========================================

    protected function getLocalDatabase(): array
    {
        if (!file_exists($this->mockFilePath)) {
            // Ensure folder exists
            @mkdir(dirname($this->mockFilePath), 0755, true);
            file_put_contents($this->mockFilePath, json_encode([]));
            return [];
        }
        $content = file_get_contents($this->mockFilePath);
        return json_decode($content, true) ?: [];
    }

    protected function saveLocalDatabase(array $db): void
    {
        @mkdir(dirname($this->mockFilePath), 0755, true);
        file_put_contents($this->mockFilePath, json_encode($db, JSON_PRETTY_PRINT));
    }

    protected function storeLocalVector(int $id, array $vector, array $payload): bool
    {
        $db = $this->getLocalDatabase();
        $db[$id] = [
            'id' => $id,
            'vector' => $vector,
            'payload' => $payload
        ];
        $this->saveLocalDatabase($db);
        return true;
    }

    protected function deleteLocalVector(int $id): bool
    {
        $db = $this->getLocalDatabase();
        if (isset($db[$id])) {
            unset($db[$id]);
            $this->saveLocalDatabase($db);
        }
        return true;
    }

    protected function searchLocalVector(array $vector, ?int $userId = null, int $limit = 10): array
    {
        $db = $this->getLocalDatabase();
        $results = [];

        foreach ($db as $point) {
            // User ID filter if provided
            if ($userId !== null && (!isset($point['payload']['user_id']) || $point['payload']['user_id'] !== $userId)) {
                continue;
            }

            $score = GeminiService::calculateCosineSimilarity($vector, $point['vector']);
            $results[] = [
                'id' => $point['id'],
                'score' => $score,
                'payload' => $point['payload']
            ];
        }

        // Sort descending by score
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $limit);
    }
}
