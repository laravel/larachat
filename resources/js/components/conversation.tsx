import StreamingIndicator from '@/components/streaming-indicator';
import { cn } from '@/lib/utils';
import { useCallback, useEffect, useRef, useState } from 'react';

type Message = {
    id?: number;
    type: 'response' | 'error' | 'prompt';
    content: string;
    saved?: boolean;
};

interface ConversationProps {
    messages: Message[];
    streamingData?: string;
    isStreaming: boolean;
    streamId?: string;
}

export default function Conversation({ messages, streamingData, isStreaming, streamId }: ConversationProps) {
    const scrollRef = useRef<HTMLDivElement>(null);

    // Auto-scroll to bottom when messages change or during streaming
    const [shouldAutoScroll, setShouldAutoScroll] = useState(true);

    const checkIfAtBottom = useCallback(() => {
        const container = scrollRef.current;
        if (!container) return false;

        const offset = Math.abs(container.scrollHeight - container.scrollTop - container.clientHeight);
        return offset <= 1; // Allow a small margin of error
    }, []);

    const handleScroll = useCallback(() => {
        const nearBottom = checkIfAtBottom();
        setShouldAutoScroll(nearBottom);
    }, [checkIfAtBottom]);

    useEffect(() => {
        if (scrollRef.current && shouldAutoScroll) {
            scrollRef.current.scrollTop = scrollRef.current.scrollHeight;
        }
    }, [messages.length, streamingData, shouldAutoScroll]);

    useEffect(() => {
        const container = scrollRef.current;
        if (container) {
            container.addEventListener('scroll', handleScroll);
            return () => container.removeEventListener('scroll', handleScroll);
        }
    }, [handleScroll]);

    return (
        <div ref={scrollRef} className="flex-1 overflow-x-hidden overflow-y-auto">
            <div className="mx-auto max-w-3xl space-y-4 p-4">
                {messages.length === 0 && <p className="text-muted-foreground mt-8 text-center">Type your message below and hit enter to send.</p>}
                {messages.map((message, index) => {
                    // Create a unique key that won't conflict between saved and new messages
                    const key = message.id ? `db-${message.id}` : `local-${index}-${message.content.substring(0, 10)}`;

                    return (
                        <div key={key} className={cn('relative', message.type === 'prompt' && 'flex justify-end')}>
                            <div
                                className={cn(
                                    'inline-block max-w-[80%] rounded-lg p-3',
                                    message.type === 'prompt' ? 'bg-primary text-primary-foreground' : 'bg-muted',
                                )}
                            >
                                {message.type === 'prompt' && (index === messages.length - 1 || index === messages.length - 2) && streamId && (
                                    <StreamingIndicator id={streamId} className="absolute top-3 -left-8" />
                                )}
                                <p className="whitespace-pre-wrap">{message.content}</p>
                            </div>
                        </div>
                    );
                })}
                {streamingData && (
                    <div className="relative">
                        <div className="bg-muted inline-block max-w-[80%] rounded-lg p-3">
                            <p className="whitespace-pre-wrap">{streamingData}</p>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
