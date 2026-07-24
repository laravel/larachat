<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatStreamingTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_user_can_stream_chat_without_saving(): void
    {
        $response = $this->post('/chat/stream', [
            'messages' => [
                ['type' => 'prompt', 'content' => 'Hello AI'],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');

        // Verify no messages were saved
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('chats', 0);
    }

    public function test_authenticated_user_streams_to_existing_chat(): void
    {
        $user = User::factory()->create();
        $chat = Chat::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->post("/chat/{$chat->id}/stream", [
            'prompt' => 'Hello AI',
        ]);

        $response->assertStatus(200);

        // Get the streaming content
        $response->streamedContent();

        // Verify messages were saved
        $this->assertDatabaseHas('messages', [
            'chat_id' => $chat->id,
            'type' => 'prompt',
            'content' => 'Hello AI',
        ]);

        // Verify AI response was saved
        $this->assertDatabaseHas('messages', [
            'chat_id' => $chat->id,
            'type' => 'response',
            'content' => 'This is a test response.',
        ]);
    }

    public function test_empty_messages_returns_early(): void
    {
        $response = $this->post('/chat/stream', [
            'messages' => [],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_chat_not_created_for_anonymous_users(): void
    {
        $response = $this->post('/chat/stream', [
            'messages' => [
                ['type' => 'prompt', 'content' => 'Test message'],
            ],
        ]);

        $response->assertStatus(200);

        // Verify no chat was created
        $this->assertDatabaseCount('chats', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_authenticated_user_without_chat_does_not_save_messages(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/chat/stream', [
            'messages' => [
                ['type' => 'prompt', 'content' => 'Hello, this is my first message'],
            ],
        ]);

        $response->assertStatus(200);

        // Get the streaming content to ensure all processing completes
        $content = $response->streamedContent();

        // Verify no chat was created (since we're back to explicit chat creation)
        $this->assertDatabaseCount('chats', 0);
        $this->assertDatabaseCount('messages', 0);

        // Verify response was still generated
        $this->assertEquals('This is a test response.', $content);
    }
}
