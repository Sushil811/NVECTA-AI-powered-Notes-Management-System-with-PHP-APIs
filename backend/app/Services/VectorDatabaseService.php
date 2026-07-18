<?php

namespace App\Services;

class VectorDatabaseService
{
    protected string $storagePath;

    public function __construct()
    {
        $this->storagePath = storage_path('app/vector_db.json');
        $this->initializeDatabase();
    }

    /**
     * Ensure the local flat-file database exists.
     */
    protected function initializeDatabase(): void
    {
        if (!file_exists($this->storagePath)) {
            @mkdir(dirname($this->storagePath), 0755, true);
            file_put_contents($this->storagePath, json_encode([]));
        }
    }

    /**
     * Retrieve the full local database array.
     */
    protected function getLocalDatabase(): array
    {
        if (!file_exists($this->storagePath)) {
            return [];
        }
        $content = file_get_contents($this->storagePath);
        return json_decode($content, true) ?: [];
    }

    /**
     * Save the local database array back to disk.
     */
    protected function saveLocalDatabase(array $db): void
    {
        @mkdir(dirname($this->storagePath), 0755, true);
        file_put_contents($this->storagePath, json_encode($db, JSON_PRETTY_PRINT));
    }

    /**
     * Store (upsert) a vector in the local database.
     */
    public function storeVector(int $id, array $vector, array $payload): bool
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

    /**
     * Delete a vector from the local database.
     */
    public function deleteVector(int $id): bool
    {
        $db = $this->getLocalDatabase();
        if (isset($db[$id])) {
            unset($db[$id]);
            $this->saveLocalDatabase($db);
        }
        return true;
    }

    /**
     * Search similar vectors in the local database using Cosine Similarity.
     */
    public function searchVector(array $vector, ?int $userId = null, int $limit = 10): array
    {
        $db = $this->getLocalDatabase();
        $results = [];

        foreach ($db as $point) {
            // User ID filter to ensure data isolation
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
