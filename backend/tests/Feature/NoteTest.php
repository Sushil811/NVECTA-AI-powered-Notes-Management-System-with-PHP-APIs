<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Note;
use App\Models\User;
use App\Services\EmbeddingService;
use App\Services\GeminiService;
use App\Services\VectorDatabaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class NoteTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock external AI/vector services so tests don't require network access
        $mockEmbedding = Mockery::mock(EmbeddingService::class);
        $mockEmbedding->shouldReceive('getEmbedding')
            ->andReturn(array_fill(0, 768, 0.1));
        $this->app->instance(EmbeddingService::class, $mockEmbedding);

        $mockVectorDb = Mockery::mock(VectorDatabaseService::class);
        $mockVectorDb->shouldReceive('storeVector')->andReturn(true);
        $mockVectorDb->shouldReceive('deleteVector')->andReturn(true);
        $mockVectorDb->shouldReceive('searchVector')->andReturn([]);
        $this->app->instance(VectorDatabaseService::class, $mockVectorDb);

        $mockGemini = Mockery::mock(GeminiService::class);
        $mockGemini->shouldReceive('generateSummary')
            ->andReturn('This is a mock AI summary.');
        $mockGemini->shouldReceive('generateEmbedding')
            ->andReturn(array_fill(0, 768, 0.1));
        $this->app->instance(GeminiService::class, $mockGemini);

        // Create test user and auth token
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test_token')->plainTextToken;
    }

    /**
     * Helper to make authenticated requests.
     */
    protected function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    // ─── CREATE ────────────────────────────────────────────

    public function test_user_can_create_a_note(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/notes', [
                'title' => 'My First Note',
                'content' => 'This is the content of my first note.',
                'category' => 'work',
            ]);

        $response->assertStatus(210)
            ->assertJson([
                'success' => true,
                'message' => 'Note created successfully',
            ])
            ->assertJsonStructure([
                'success', 'message',
                'data' => ['id', 'title', 'content', 'summary', 'category', 'vector_id', 'created_at', 'updated_at'],
            ]);

        $this->assertDatabaseHas('notes', [
            'title' => 'My First Note',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_create_note_fails_without_title(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/notes', [
                'content' => 'Some content without a title.',
            ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_create_note_fails_without_content(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/notes', [
                'title' => 'Title only',
            ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_create_note_with_invalid_category_fails(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/notes', [
                'title' => 'Bad Category Note',
                'content' => 'Content here.',
                'category' => 'invalid_category',
            ]);

        $response->assertStatus(422);
    }

    // ─── READ (INDEX) ──────────────────────────────────────

    public function test_user_can_list_their_notes(): void
    {
        Note::factory()->count(3)->create(['user_id' => $this->user->id]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/notes');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data',
                'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);

        $this->assertCount(3, $response->json('data'));
    }

    public function test_user_cannot_see_other_users_notes(): void
    {
        $otherUser = User::factory()->create();
        Note::factory()->count(2)->create(['user_id' => $otherUser->id]);
        Note::factory()->count(1)->create(['user_id' => $this->user->id]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/notes');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_notes_can_be_filtered_by_category(): void
    {
        Note::factory()->create(['user_id' => $this->user->id, 'category' => 'work']);
        Note::factory()->create(['user_id' => $this->user->id, 'category' => 'personal']);
        Note::factory()->create(['user_id' => $this->user->id, 'category' => 'ideas']);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/notes?category=work');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    // ─── READ (SHOW) ───────────────────────────────────────

    public function test_user_can_view_single_note(): void
    {
        $note = Note::factory()->create(['user_id' => $this->user->id]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/notes/{$note->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $note->id,
                    'title' => $note->title,
                ],
            ]);
    }

    public function test_user_cannot_view_another_users_note(): void
    {
        $otherUser = User::factory()->create();
        $note = Note::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/notes/{$note->id}");

        $response->assertStatus(404);
    }

    public function test_viewing_nonexistent_note_returns_404(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/notes/99999');

        $response->assertStatus(404);
    }

    // ─── UPDATE ────────────────────────────────────────────

    public function test_user_can_update_their_note(): void
    {
        $note = Note::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Original Title',
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->putJson("/api/notes/{$note->id}", [
                'title' => 'Updated Title',
                'content' => 'Updated content.',
                'category' => 'personal',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Note updated successfully',
                'data' => [
                    'title' => 'Updated Title',
                    'category' => 'personal',
                ],
            ]);

        $this->assertDatabaseHas('notes', [
            'id' => $note->id,
            'title' => 'Updated Title',
        ]);
    }

    public function test_user_cannot_update_another_users_note(): void
    {
        $otherUser = User::factory()->create();
        $note = Note::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->withHeaders($this->authHeaders())
            ->putJson("/api/notes/{$note->id}", [
                'title' => 'Hacked Title',
                'content' => 'Hacked content.',
            ]);

        $response->assertStatus(404);
    }

    // ─── DELETE ────────────────────────────────────────────

    public function test_user_can_delete_their_note(): void
    {
        $note = Note::factory()->create(['user_id' => $this->user->id]);

        $response = $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/notes/{$note->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Note deleted successfully',
            ]);

        $this->assertDatabaseMissing('notes', ['id' => $note->id]);
    }

    public function test_user_cannot_delete_another_users_note(): void
    {
        $otherUser = User::factory()->create();
        $note = Note::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/notes/{$note->id}");

        $response->assertStatus(404);

        $this->assertDatabaseHas('notes', ['id' => $note->id]);
    }

    // ─── SUMMARY ───────────────────────────────────────────

    public function test_user_can_generate_note_summary(): void
    {
        $note = Note::factory()->create([
            'user_id' => $this->user->id,
            'summary' => null,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/notes/{$note->id}/summary");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'summary' => 'This is a mock AI summary.',
            ]);

        $this->assertDatabaseHas('notes', [
            'id' => $note->id,
            'summary' => 'This is a mock AI summary.',
        ]);
    }

    // ─── SEARCH ────────────────────────────────────────────

    public function test_search_requires_query_parameter(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/search');

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Search query parameter (q) is required',
            ]);
    }

    public function test_search_returns_results(): void
    {
        // The mock returns an empty array for searchVector,
        // so we expect an empty JSON array back
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/search?q=test+query');

        $response->assertStatus(200);
    }
}
