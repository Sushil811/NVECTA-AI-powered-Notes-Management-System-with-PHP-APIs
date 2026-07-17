<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NoteApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test user registration.
     */
    public function test_user_can_register()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(210) // 210 custom status
            ->assertJsonStructure(['success', 'message', 'token', 'user'])
            ->assertJsonFragment(['email' => 'john@example.com']);

        $this->assertDatabaseHas('users', ['email' => 'john@example.com']);
    }

    /**
     * Test user login.
     */
    public function test_user_can_login()
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'john@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'message', 'token', 'user']);
    }

    /**
     * Test user logout.
     */
    public function test_user_can_logout()
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => bcrypt('password123'),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/logout');

        $response->assertStatus(200)
            ->assertJsonFragment(['success' => true, 'message' => 'Logged out successfully']);
    }

    /**
     * Test creating a note under auth protection.
     */
    public function test_authenticated_user_can_create_note()
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => bcrypt('password123'),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/notes', [
            'title' => 'My First Note',
            'content' => 'This is the note content.'
        ]);

        $response->assertStatus(210)
            ->assertJsonStructure(['success', 'message', 'data'])
            ->assertJsonFragment(['title' => 'My First Note']);

        $this->assertDatabaseHas('notes', [
            'user_id' => $user->id,
            'title' => 'My First Note'
        ]);
    }

    /**
     * Test that user notes are isolated (access protection).
     */
    public function test_user_cannot_access_other_users_notes()
    {
        $user1 = User::create([
            'name' => 'User One',
            'email' => 'user1@example.com',
            'password' => bcrypt('password123'),
        ]);

        $user2 = User::create([
            'name' => 'User Two',
            'email' => 'user2@example.com',
            'password' => bcrypt('password123'),
        ]);

        $note = Note::create([
            'user_id' => $user1->id,
            'title' => 'Private Note',
            'content' => 'Secret content'
        ]);

        // Access as User Two
        Sanctum::actingAs($user2);

        $response = $this->getJson("/api/notes/{$note->id}");
        $response->assertStatus(404); // Returns 404 Not Found since it does not belong to user2

        $updateResponse = $this->putJson("/api/notes/{$note->id}", [
            'title' => 'Hacked Title',
            'content' => 'Hacked content'
        ]);
        $updateResponse->assertStatus(404);
    }

    /**
     * Test note updating.
     */
    public function test_user_can_update_note()
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => bcrypt('password123'),
        ]);

        Sanctum::actingAs($user);

        $note = Note::create([
            'user_id' => $user->id,
            'title' => 'Old Title',
            'content' => 'Old Content'
        ]);

        $response = $this->putJson("/api/notes/{$note->id}", [
            'title' => 'New Title',
            'content' => 'New Content'
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['title' => 'New Title']);

        $this->assertDatabaseHas('notes', ['title' => 'New Title']);
    }

    /**
     * Test note deleting.
     */
    public function test_user_can_delete_note()
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => bcrypt('password123'),
        ]);

        Sanctum::actingAs($user);

        $note = Note::create([
            'user_id' => $user->id,
            'title' => 'Delete Me',
            'content' => 'Some content'
        ]);

        $response = $this->deleteJson("/api/notes/{$note->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('notes', ['id' => $note->id]);
    }

    /**
     * Test pagination works as expected.
     */
    public function test_pagination_works()
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => bcrypt('password123'),
        ]);

        Sanctum::actingAs($user);

        for ($i = 1; $i <= 15; $i++) {
            Note::create([
                'user_id' => $user->id,
                'title' => "Note {$i}",
                'content' => "Content {$i}"
            ]);
        }

        $response = $this->getJson('/api/notes?page=1&limit=10');

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data', 'pagination'])
            ->assertJsonCount(10, 'data')
            ->assertJsonFragment(['total' => 15]);
    }

    /**
     * Test summarization endpoint works.
     */
    public function test_summary_api_works()
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => bcrypt('password123'),
        ]);

        Sanctum::actingAs($user);

        $note = Note::create([
            'user_id' => $user->id,
            'title' => 'To Summarize',
            'content' => 'First sentence is key. Second sentence is also descriptive.'
        ]);

        $response = $this->postJson("/api/notes/{$note->id}/summary");

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'summary']);

        $note->refresh();
        $this->assertNotEmpty($note->summary);
    }

    /**
     * Test AI Semantic Search.
     */
    public function test_semantic_search_works()
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => bcrypt('password123'),
        ]);

        Sanctum::actingAs($user);

        Note::create([
            'user_id' => $user->id,
            'title' => 'Laravel Framework',
            'content' => 'An MVC based PHP web development framework.',
            'vector_id' => '1',
            'embedding' => array_fill(0, 768, 0.5)
        ]);

        $response = $this->getJson('/api/search?q=framework');

        $response->assertStatus(200)
            ->assertJsonStructure([
                '*' => ['id', 'title', 'content', 'summary', 'vector_id', 'score']
            ]);
    }
}
