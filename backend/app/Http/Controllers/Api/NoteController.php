<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Note;
use App\Services\GeminiService;
use App\Services\EmbeddingService;
use App\Services\QdrantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class NoteController extends Controller
{
    protected GeminiService $gemini;
    protected EmbeddingService $embedding;
    protected QdrantService $qdrant;

    public function __construct(GeminiService $gemini, EmbeddingService $embedding, QdrantService $qdrant)
    {
        $this->gemini = $gemini;
        $this->embedding = $embedding;
        $this->qdrant = $qdrant;

        // Initialize Qdrant collection
        $this->qdrant->createCollection();
    }

    /**
     * Get notes list with pagination.
     * GET /api/notes?page=1&limit=10&category=work
     */
    public function index(Request $request): JsonResponse
    {
        $limit = intval($request->query('limit', 10));
        $limit = max(1, min(100, $limit));
        $category = $request->query('category', '');

        $user = $request->user();

        // Retrieve only this user's notes, sorted newest first
        $query = Note::where('user_id', $user->id)->latest('created_at');

        // Apply category filter if requested
        if (!empty($category) && in_array($category, ['work', 'personal', 'ideas'])) {
            $query->where('category', $category);
        }

        $paginated = $query->paginate($limit);

        // Convert note records to output format
        $items = collect($paginated->items())->map(function ($note) {
            return [
                'id' => $note->id,
                'title' => $note->title,
                'content' => $note->content,
                'summary' => $note->summary,
                'category' => $note->category,
                'vector_id' => $note->vector_id,
                'created_at' => $note->created_at->toIso8601String(),
                'updated_at' => $note->updated_at->toIso8601String(),
            ];
        })->toArray();

        return response()->json([
            'success' => true,
            'data' => $items,
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ]
        ], 200);
    }

    /**
     * Create Note.
     * POST /api/notes
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'category' => 'nullable|string|in:work,personal,ideas',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $user = $request->user();
            $category = $request->input('category', 'work');

            // Create note record first to get ID
            $note = Note::create([
                'user_id' => $user->id,
                'title' => $request->title,
                'content' => $request->content,
                'category' => $category,
            ]);

            // Generate AI embedding
            $textToEmbed = "Title: {$note->title}\nCategory: {$note->category}\nContent: {$note->content}";
            $vector = $this->embedding->getEmbedding($textToEmbed);

            // Store vector in Qdrant with user payload for isolated searching
            $vectorId = (string)$note->id; // Qdrant allows stringified integers or UUIDs
            $this->qdrant->storeVector((int)$vectorId, $vector, [
                'note_id' => $note->id,
                'user_id' => $user->id,
                'title' => $note->title
            ]);

            // Save the Qdrant reference back to MySQL
            $note->update(['vector_id' => $vectorId]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Note created successfully',
                'data' => [
                    'id' => $note->id,
                    'title' => $note->title,
                    'content' => $note->content,
                    'summary' => $note->summary,
                    'category' => $note->category,
                    'vector_id' => $note->vector_id,
                    'created_at' => $note->created_at->toIso8601String(),
                    'updated_at' => $note->updated_at->toIso8601String(),
                ]
            ], 210); // 201 Created

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create note',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Single Note.
     * GET /api/notes/{id}
     */
    public function show(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $note = Note::where('user_id', $user->id)->find($id);

        if (!$note) {
            return response()->json([
                'success' => false,
                'message' => 'Note not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $note->id,
                'title' => $note->title,
                'content' => $note->content,
                'summary' => $note->summary,
                'category' => $note->category,
                'vector_id' => $note->vector_id,
                'created_at' => $note->created_at->toIso8601String(),
                'updated_at' => $note->updated_at->toIso8601String(),
            ]
        ], 200);
    }

    /**
     * Update Note.
     * PUT /api/notes/{id}
     */
    public function update(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $note = Note::where('user_id', $user->id)->find($id);

        if (!$note) {
            return response()->json([
                'success' => false,
                'message' => 'Note not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'category' => 'nullable|string|in:work,personal,ideas',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();
            $category = $request->input('category', $note->category);

            $note->update([
                'title' => $request->title,
                'content' => $request->content,
                'category' => $category,
            ]);

            // Regenerate embedding vector
            $textToEmbed = "Title: {$note->title}\nCategory: {$note->category}\nContent: {$note->content}";
            $vector = $this->embedding->getEmbedding($textToEmbed);

            // Update Qdrant vector point
            $vectorId = $note->vector_id ?: (string)$note->id;
            $this->qdrant->storeVector((int)$vectorId, $vector, [
                'note_id' => $note->id,
                'user_id' => $user->id,
                'title' => $note->title
            ]);

            if (empty($note->vector_id)) {
                $note->update(['vector_id' => $vectorId]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Note updated successfully',
                'data' => [
                    'id' => $note->id,
                    'title' => $note->title,
                    'content' => $note->content,
                    'summary' => $note->summary,
                    'category' => $note->category,
                    'vector_id' => $note->vector_id,
                    'created_at' => $note->created_at->toIso8601String(),
                    'updated_at' => $note->updated_at->toIso8601String(),
                ]
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update note',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete Note.
     * DELETE /api/notes/{id}
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $note = Note::where('user_id', $user->id)->find($id);

        if (!$note) {
            return response()->json([
                'success' => false,
                'message' => 'Note not found'
            ], 404);
        }

        try {
            DB::beginTransaction();

            // Remove vector from Qdrant
            if ($note->vector_id) {
                $this->qdrant->deleteVector((int)$note->vector_id);
            }

            // Delete MySQL record
            $note->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Note deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete note',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate Note Summary.
     * POST /api/notes/{id}/summary
     */
    public function generateSummary(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $note = Note::where('user_id', $user->id)->find($id);

        if (!$note) {
            return response()->json([
                'success' => false,
                'message' => 'Note not found'
            ], 404);
        }

        try {
            $summary = $this->gemini->generateSummary($note->content);
            
            $note->update(['summary' => $summary]);

            return response()->json([
                'success' => true,
                'summary' => $summary
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * AI-powered Semantic Search.
     * GET /api/search?q=query
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->query('q', '');

        if (empty(trim($query))) {
            return response()->json([
                'success' => false,
                'message' => 'Search query parameter (q) is required'
            ], 400);
        }

        try {
            $user = $request->user();

            // 1. Generate query embedding vector
            $queryVector = $this->embedding->getEmbedding($query);

            // 2. Search similar vectors in Qdrant (filtered by user_id)
            $matches = $this->qdrant->searchVector($queryVector, $user->id, 15);

            // 3. Get note IDs
            $noteIds = array_column($matches, 'id');

            // 4. Fetch complete notes from MySQL, ordering them by matching relevance score
            if (empty($noteIds)) {
                return response()->json([], 200);
            }

            $notes = Note::where('user_id', $user->id)
                ->whereIn('id', $noteIds)
                ->get()
                ->keyBy('id');

            $sortedResults = [];
            foreach ($matches as $match) {
                $id = $match['id'];
                if (isset($notes[$id])) {
                    $note = $notes[$id];
                    $sortedResults[] = [
                        'id' => $note->id,
                        'title' => $note->title,
                        'content' => $note->content,
                        'summary' => $note->summary,
                        'category' => $note->category,
                        'vector_id' => $note->vector_id,
                        'score' => round($match['score'], 4),
                        'created_at' => $note->created_at->toIso8601String(),
                        'updated_at' => $note->updated_at->toIso8601String(),
                    ];
                }
            }

            return response()->json($sortedResults, 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Semantic search error',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
