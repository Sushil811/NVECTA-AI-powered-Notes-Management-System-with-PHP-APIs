<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    protected ?string $apiKey;

    public function __construct()
    {
        $this->apiKey = env('GEMINI_API_KEY');
    }

    /**
     * Generate summary of note contents.
     */
    public function generateSummary(string $content): string
    {
        if (empty(trim($content))) {
            return 'Empty note. Nothing to summarize.';
        }

        if ($this->apiKey && $this->apiKey !== 'your_api_key_here') {
            try {
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                ])->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$this->apiKey}", [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => "Summarize the following note content in 2-3 short, bulleted sentences. Be direct, clear, and concise. Do not add introductory remarks:\n\n" . $content]
                            ]
                        ]
                    ]
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                        return trim($data['candidates'][0]['content']['parts'][0]['text']);
                    }
                }
                Log::warning('Gemini API summarization failed: ' . $response->body());
            } catch (\Exception $e) {
                Log::error('Error calling Gemini API for summary: ' . $e->getMessage());
            }
        }

        return $this->generateLocalSummary($content);
    }

    /**
     * Generate text embedding vector (768 dimensions).
     */
    public function generateEmbedding(string $text): array
    {
        if (empty(trim($text))) {
            return array_fill(0, 768, 0.0);
        }

        if ($this->apiKey && $this->apiKey !== 'your_api_key_here') {
            try {
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                ])->post("https://generativelanguage.googleapis.com/v1beta/models/text-embedding-004:embedContent?key={$this->apiKey}", [
                    'content' => [
                        'parts' => [
                            ['text' => $text]
                        ]
                    ]
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    if (isset($data['embedding']['values'])) {
                        return $data['embedding']['values'];
                    }
                }
                Log::warning('Gemini API embedding failed: ' . $response->body());
            } catch (\Exception $e) {
                Log::error('Error calling Gemini API for embedding: ' . $e->getMessage());
            }
        }

        return $this->generateLocalEmbedding($text);
    }

    /**
     * Local fallback summarization using simple sentence parsing.
     */
    protected function generateLocalSummary(string $content): string
    {
        $sentences = preg_split('/(?<=[.?!])\s+(?=[A-Z])/i', trim($content));
        $bullets = [];
        for ($i = 0; $i < min(2, count($sentences)); $i++) {
            if (!empty(trim($sentences[$i]))) {
                $bullets[] = '• ' . trim($sentences[$i]);
            }
        }
        return empty($bullets) ? '• ' . mb_strimwidth($content, 0, 100, '...') : implode("\n", $bullets);
    }

    /**
     * Local fallback embedding vector generation (768 dimensions).
     */
    protected function generateLocalEmbedding(string $text): array
    {
        // Simple hash-deterministic pseudo vector generation for 768 float coordinates
        $vector = [];
        $text = strtolower($text);
        $len = strlen($text);
        
        for ($i = 0; $i < 768; $i++) {
            // Seed a value using trig functions and text length/character codes
            $seed = ($i * 0.13) + $len;
            if ($len > 0) {
                $charIdx = $i % $len;
                $seed += ord($text[$charIdx]) * 0.77;
            }
            $val = sin($seed) * cos($seed * 0.5);
            $vector[] = (float)round($val, 6);
        }

        // Normalise vector
        $sumSquare = array_sum(array_map(fn($v) => $v * $v, $vector));
        $magnitude = sqrt($sumSquare);
        if ($magnitude > 0) {
            $vector = array_map(fn($v) => $v / $magnitude, $vector);
        }

        return $vector;
    }

    /**
     * Calculate cosine similarity between two float vectors.
     */
    public static function calculateCosineSimilarity(array $vec1, array $vec2): float
    {
        $dotProduct = 0.0;
        $mag1 = 0.0;
        $mag2 = 0.0;

        $len = min(count($vec1), count($vec2));
        if ($len === 0) return 0.0;

        for ($i = 0; $i < $len; $i++) {
            $dotProduct += $vec1[$i] * $vec2[$i];
            $mag1 += $vec1[$i] * $vec1[$i];
            $mag2 += $vec2[$i] * $vec2[$i];
        }

        $mag1 = sqrt($mag1);
        $mag2 = sqrt($mag2);

        if ($mag1 == 0.0 || $mag2 == 0.0) {
            return 0.0;
        }

        return $dotProduct / ($mag1 * $mag2);
    }
}

