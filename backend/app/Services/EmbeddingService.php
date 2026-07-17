<?php

namespace App\Services;

class EmbeddingService
{
    protected GeminiService $geminiService;

    public function __construct(GeminiService $geminiService)
    {
        $this->geminiService = $geminiService;
    }

    /**
     * Get 768-dimension embedding vector for the given text.
     */
    public function getEmbedding(string $text): array
    {
        return $this->geminiService->generateEmbedding($text);
    }
}
