import Conversation from '@/components/conversation';
import TitleGenerator from '@/components/title-generator';
import SidebarTitleUpdater from '@/components/sidebar-title-updater';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useStream } from '@laravel/stream-react';
import { Info } from 'lucide-react';
import { FormEvent, useCallback, useEffect, useRef, useState } from 'react';

type Message = {
    id?: number;
    type: 'response' | 'error' | 'prompt';
    content: string;
};

type ChatType = {
    id: number;
    title: string;
    messages: Message[];
    created_at: string;
    updated_at: string;
};

type PageProps = {
    auth: {
        user?: {
            id: number;
            name: string;
            email: string;
        };
    };
    chat?: ChatType;
    flash?: {
        stream?: boolean;
    };
};

function ChatWithStream({ chat, auth, flash }: { chat: ChatType | undefined; auth: PageProps['auth']; flash: PageProps['flash'] }) {
    const [messages, setMessages] = useState<Message[]>(chat?.messages || []);
    const [currentTitle, setCurrentTitle] = useState<string>(chat?.title || 'Untitled');
    const [shouldGenerateTitle, setShouldGenerateTitle] = useState<boolean>(false);
    const [isTitleStreaming, setIsTitleStreaming] = useState<boolean>(false);
    const [shouldUpdateSidebar, setShouldUpdateSidebar] = useState<boolean>(false);
    const inputRef = useRef<HTMLInputElement>(null);

    const currentChatId = chat?.id || null;
    const streamUrl = currentChatId ? `/chat/${currentChatId}/stream` : '/chat/stream';

    const { data, send, isStreaming, isFetching, cancel, id } = useStream(streamUrl);

    // Auto-focus input and handle auto-streaming on mount
    useEffect(() => {
        inputRef.current?.focus();

        // Auto-stream if we have a chat with exactly 1 message (newly created chat)
        // OR if flash.stream is true (fallback)
        const shouldAutoStream = chat?.messages?.length === 1 || (flash?.stream && chat?.messages && chat.messages.length > 0);

        if (shouldAutoStream) {
            setTimeout(() => {
                const first = chat.messages[0];
                if (first.type === 'prompt') {
                    send({ autoStream: 1 });
                }
            }, 100);
        }
    }, [chat?.messages, flash?.stream, send]); // Only run on mount

    // Scroll to bottom when streaming
    useEffect(() => {
        if (isStreaming) {
            window.scrollTo(0, document.body.scrollHeight);
        }
    }, [isStreaming, data]);

    // Focus input when streaming completes and trigger title generation
    useEffect(() => {
        if (!isStreaming && inputRef.current) {
            inputRef.current.focus();
            
            // Trigger title generation if this is an authenticated user with "Untitled" chat and we have a response
            if (auth.user && chat && currentTitle === 'Untitled' && data && data.trim()) {
                setShouldGenerateTitle(true);
                setShouldUpdateSidebar(true);
            }
        }
    }, [isStreaming, auth.user, chat, currentTitle, data]);

    // Update current title when chat changes
    useEffect(() => {
        if (chat?.title) {
            setCurrentTitle(chat.title);
        }
    }, [chat?.title]);

    // Track title state changes
    useEffect(() => {
        // Title state tracking
    }, [currentTitle, isTitleStreaming]);


    const handleSubmit = useCallback((e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        const form = e.currentTarget;
        const input = form.querySelector('input') as HTMLInputElement;
        const query = input?.value.trim();

        if (!query) return;

        const toAdd: Message[] = [];

        // If there's a completed response from previous streaming, add it first
        if (data && data.trim()) {
            toAdd.push({
                type: 'response',
                content: data,
            });
        }

        // Add the new prompt
        toAdd.push({
            type: 'prompt',
            content: query,
        });

        // Update local state
        setMessages((prev) => [...prev, ...toAdd]);

        // Send message data based on chat/auth status
        if (chat && auth.user) {
            send({ prompt: query });
        } else {
            send({ messages: [...messages, ...toAdd] });
        }

        input.value = '';
        inputRef.current?.focus();
    }, [send, data, messages]);

    return (
        <>
            <Head title={currentTitle} />
            {/* Title generator with working EventStream */}
            {shouldGenerateTitle && auth.user && chat && (
                <TitleGenerator
                    chatId={chat.id}
                    onTitleUpdate={(newTitle, isStreaming = false) => {
                        setCurrentTitle(newTitle);
                        setIsTitleStreaming(isStreaming);
                        document.title = `${newTitle} - LaraChat`;
                    }}
                    onComplete={() => {
                        setIsTitleStreaming(false);
                        setShouldGenerateTitle(false);
                    }}
                />
            )}
            
            {/* Sidebar title updater - separate EventStream for sidebar */}
            {shouldUpdateSidebar && auth.user && chat && (
                <SidebarTitleUpdater
                    chatId={chat.id}
                    onComplete={() => {
                        setShouldUpdateSidebar(false);
                    }}
                />
            )}
            
            <AppLayout
                currentChatId={chat?.id}
                className="flex h-[calc(100vh-theme(spacing.4))] flex-col overflow-hidden md:h-[calc(100vh-theme(spacing.8))]"
            >
                {!auth.user && (
                    <div className="bg-background flex-shrink-0 border-b p-4">
                        <Alert className="mx-auto max-w-3xl">
                            <Info className="h-4 w-4" />
                            <AlertDescription>
                                You're chatting anonymously. Your conversation won't be saved.
                                <Button variant="link" className="h-auto p-0 text-sm" onClick={() => router.visit('/login')}>
                                    Sign in to save your chats
                                </Button>
                            </AlertDescription>
                        </Alert>
                    </div>
                )}

                {/* Chat Title Display */}
                {auth.user && chat && (
                    <div className="bg-background flex-shrink-0 border-b px-4 py-3">
                        <div className="mx-auto max-w-3xl">
                            <h1 className="text-lg font-semibold text-foreground">
                                {currentTitle}
                                {isTitleStreaming && (
                                    <span className="ml-1 animate-pulse">|</span>
                                )}
                            </h1>
                        </div>
                    </div>
                )}

                <Conversation messages={messages} streamingData={data} isStreaming={isStreaming} streamId={id} />

                <div className="bg-background flex-shrink-0 border-t">
                    <div className="mx-auto max-w-3xl p-4">
                        <form onSubmit={handleSubmit}>
                            <div className="flex gap-2">
                                <Input
                                    ref={inputRef}
                                    type="text"
                                    placeholder="Type a message..."
                                    className="flex-1"
                                    disabled={isStreaming || isFetching}
                                />
                                <Button type="submit" disabled={isStreaming || isFetching}>
                                    Send
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            </AppLayout>
        </>
    );
}

export default function Chat() {
    const { auth, chat, flash } = usePage<PageProps>().props;

    // Use the chat ID as a key to force complete re-creation of the ChatWithStream component
    // This ensures useStream is completely reinitialized with the correct URL
    const key = chat?.id ? `chat-${chat.id}` : 'no-chat';

    return <ChatWithStream key={key} chat={chat} auth={auth} flash={flash} />;
}
