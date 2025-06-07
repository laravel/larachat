<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use OpenAI\Laravel\Facades\OpenAI;
use Illuminate\Http\StreamedEvent;

class ChatController extends Controller
{
    use AuthorizesRequests;

    public function index()
    {
        // For authenticated users, automatically create a new chat and redirect
        if (Auth::check()) {
            $chat = Auth::user()->chats()->create([
                'title' => 'Untitled',
            ]);

            return redirect()->route('chat.show', $chat);
        }

        // For unauthenticated users, show the blank chat page
        return Inertia::render('chat', [
            'chat' => null,
        ]);
    }

    public function show(Chat $chat)
    {
        $this->authorize('view', $chat);

        $chat->load('messages');

        return Inertia::render('chat', [
            'chat' => $chat,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'nullable|string|max:255',
            'firstMessage' => 'nullable|string',
        ]);

        $title = $request->title;

        // If no title provided, use "Untitled" initially
        if (! $title) {
            $title = 'Untitled';
        }

        $chat = Auth::user()->chats()->create([
            'title' => $title,
        ]);

        // If firstMessage provided, save it and trigger streaming via URL parameter
        if ($request->firstMessage) {
            // Save the first message
            $chat->messages()->create([
                'type' => 'prompt',
                'content' => $request->firstMessage,
            ]);

            return redirect()->route('chat.show', $chat)->with('stream', true);
        }

        return redirect()->route('chat.show', $chat);
    }

    public function update(Request $request, Chat $chat)
    {
        $this->authorize('update', $chat);

        $request->validate([
            'title' => 'required|string|max:255',
        ]);

        $chat->update([
            'title' => $request->title,
        ]);

        return redirect()->back();
    }

    public function destroy(Chat $chat)
    {
        $this->authorize('delete', $chat);

        $chatId = $chat->id;
        $chat->delete();

        // Check if this is the current chat being viewed
        $currentUrl = request()->header('Referer') ?? '';
        $isCurrentChat = str_contains($currentUrl, "/chat/{$chatId}");

        if ($isCurrentChat) {
            // If deleting the current chat, redirect to home
            return redirect()->route('home');
        } else {
            // If deleting from sidebar, redirect back to current page
            return redirect()->back();
        }
    }

    public function stream(Request $request, ?Chat $chat = null)
    {
        if ($chat) {
            $this->authorize('view', $chat);
        }

        return response()->stream(function () use ($request, $chat) {
            $messages = $request->input('messages', []);

            if (empty($messages)) {
                return;
            }

            // Only save messages if we have an existing chat (authenticated user with saved chat)
            if ($chat) {
                foreach ($messages as $message) {
                    // Only save if message doesn't have an ID (not from database)
                    if (! isset($message['id'])) {
                        $chat->messages()->create([
                            'type' => $message['type'],
                            'content' => $message['content'],
                        ]);
                    }
                }
            }

            // Prepare messages for OpenAI
            $openAIMessages = collect($messages)
                ->map(fn ($message) => [
                    'role' => $message['type'] === 'prompt' ? 'user' : 'assistant',
                    'content' => $message['content'],
                ])
                ->toArray();

            // Stream response from OpenAI
            $fullResponse = '';

            if (app()->environment('testing') || ! config('openai.api_key')) {
                // Mock response for testing or when API key is not set
                $fullResponse = 'This is a test response.';
                echo $fullResponse;
                ob_flush();
                flush();
            } else {
                try {
                    $stream = OpenAI::chat()->createStreamed([
                        'model' => 'gpt-4.1-nano',
                        'messages' => $openAIMessages,
                    ]);

                    foreach ($stream as $response) {
                        $chunk = $response->choices[0]->delta->content;
                        if ($chunk !== null) {
                            $fullResponse .= $chunk;
                            echo $chunk;
                            ob_flush();
                            flush();
                        }
                    }
                } catch (\Exception $e) {
                    $fullResponse = 'Error: Unable to generate response.';
                    echo $fullResponse;
                    ob_flush();
                    flush();
                }
            }

            // Save the AI response to database if authenticated
            if ($chat && $fullResponse) {
                $chat->messages()->create([
                    'type' => 'response',
                    'content' => $fullResponse,
                ]);

                // Generate title if this is a new chat with "Untitled" title
                \Log::info('Checking if should generate title', ['chat_title' => $chat->title]);
                if ($chat->title === 'Untitled') {
                    \Log::info('Generating title in background for chat', ['chat_id' => $chat->id]);
                    $this->generateTitleInBackground($chat);
                } else {
                    \Log::info('Not generating title', ['current_title' => $chat->title]);
                }
            }
        }, 200, [
            'Cache-Control' => 'no-cache',
            'Content-Type' => 'text/event-stream',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function titleStream(Chat $chat)
    {
        $this->authorize('view', $chat);

        \Log::info('Title stream requested for chat', ['chat_id' => $chat->id, 'title' => $chat->title]);

        return response()->eventStream(function () use ($chat) {
            // If title is already set and not "Untitled", send it immediately
            if ($chat->title && $chat->title !== 'Untitled') {
                yield new StreamedEvent(
                    event: 'title-update',
                    data: json_encode(['title' => $chat->title])
                );
                return;
            }

            // Generate title immediately when stream is requested
            $this->generateTitleInBackground($chat);

            // Wait for title updates and stream them
            $startTime = time();
            $timeout = 30; // 30 second timeout

            while (time() - $startTime < $timeout) {
                // Refresh the chat model to get latest title
                $chat->refresh();

                // If title has changed from "Untitled", send it
                if ($chat->title !== 'Untitled') {
                    yield new StreamedEvent(
                        event: 'title-update',
                        data: json_encode(['title' => $chat->title])
                    );
                    break;
                }

                // Wait a bit before checking again
                usleep(500000); // 0.5 seconds
            }
        }, endStreamWith: new StreamedEvent(event: 'title-update', data: '</stream>'));
    }

    private function generateTitleInBackground(Chat $chat)
    {
        // Get the first message
        $firstMessage = $chat->messages()->where('type', 'prompt')->first();

        if (!$firstMessage) {
            return;
        }

        try {
            if (app()->environment('testing') || ! config('openai.api_key')) {
                // Mock response for testing
                $generatedTitle = 'Chat about: ' . substr($firstMessage->content, 0, 30);
            } else {
                $response = OpenAI::chat()->create([
                    'model' => 'gpt-4.1-nano',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Generate a concise, descriptive title (max 50 characters) for a chat that starts with the following message. Respond with only the title, no quotes or extra formatting.'
                        ],
                        [
                            'role' => 'user',
                            'content' => $firstMessage->content
                        ]
                    ],
                    'max_tokens' => 20,
                    'temperature' => 0.7,
                ]);

                $generatedTitle = trim($response->choices[0]->message->content);

                // Ensure title length
                if (strlen($generatedTitle) > 50) {
                    $generatedTitle = substr($generatedTitle, 0, 47) . '...';
                }
            }

            // Update the chat title
            $chat->update(['title' => $generatedTitle]);

            \Log::info('Generated title for chat', ['chat_id' => $chat->id, 'title' => $generatedTitle]);

        } catch (\Exception $e) {
            // Fallback title on error
            $fallbackTitle = substr($firstMessage->content, 0, 47) . '...';
            $chat->update(['title' => $fallbackTitle]);
            \Log::error('Error generating title, using fallback', ['error' => $e->getMessage()]);
        }
    }
}
