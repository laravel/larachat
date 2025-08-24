<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChatFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_plus_button_creates_blank_chat_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // User should have no chats initially
        $this->assertCount(0, $user->chats);

        // Click the + button (POST to /chat)
        $response = $this->post('/chat');

        // Should redirect to the new chat
        $response->assertRedirect();

        // User should now have one blank chat
        $user->refresh();
        $this->assertCount(1, $user->chats);

        $chat = $user->chats->first();
        $this->assertNotNull($chat);
        $this->assertEquals('Untitled', $chat->title); // Blank chat has "Untitled" title
        $this->assertCount(0, $chat->messages); // No messages yet

        // Should redirect to the new chat page
        $response->assertRedirect("/chat/{$chat->id}");
    }

    public function test_message_from_home_page_creates_chat_and_sends_message(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Visit home page - authenticated users get redirected to new chat
        $response = $this->get('/');
        $response->assertRedirect();
        
        // User should now have a chat from the redirect
        $user->refresh();
        $this->assertCount(1, $user->chats);

        // Simulate typing a message and submitting (this would trigger the frontend logic)
        // In the frontend, this would:
        // 1. Save the message to router.remember()
        // 2. POST to /chat to create a new chat
        // 3. Redirect to the new chat
        // 4. Auto-send the remembered message

        // For testing, we'll simulate the flow
        $response = $this->post('/chat');
        $chat = $user->chats()->first();

        // Now send the message to the new chat
        $response = $this->post("/chat/{$chat->id}/stream", [
            'prompt' => 'Hello from home page',
        ]);

        $response->assertStatus(200);

        // Get the streaming content to ensure all processing completes
        $response->streamedContent();

        // Verify the message was saved
        $this->assertDatabaseHas('messages', [
            'chat_id' => $chat->id,
            'type' => 'prompt',
            'content' => 'Hello from home page',
        ]);

        // Verify AI response was also saved
        $this->assertDatabaseHas('messages', [
            'chat_id' => $chat->id,
            'type' => 'response',
            'content' => 'This is a test response.',
        ]);
    }

    public function test_sidebar_updates_after_chat_creation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Create first chat
        $response = $this->post('/chat');
        $chat1 = $user->chats()->first();

        // Visit the new chat page
        $response = $this->get("/chat/{$chat1->id}");
        $response->assertInertia(fn (Assert $page) => $page
            ->component('chat')
            ->where('chat.id', $chat1->id)
        );

        // Create second chat
        $response = $this->post('/chat');
        $user->refresh();
        $chat2 = $user->chats()->latest()->first();

        // Visit the new chat page
        $response = $this->get("/chat/{$chat2->id}");
        $response->assertInertia(fn (Assert $page) => $page
            ->component('chat')
            ->where('chat.id', $chat2->id)
        );

        // Verify both chats exist via API
        $response = $this->actingAs($user)->get('/api/chats');
        $response->assertJsonCount(2);
    }
}
