import { useEventStream } from '@laravel/stream-react';

interface ChatTitleUpdaterProps {
    chatId: number;
    onTitleUpdate?: (title: string) => void;
}

export default function ChatTitleUpdater({ chatId, onTitleUpdate }: ChatTitleUpdaterProps) {
    useEventStream(`/chat/${chatId}/title-stream`, {
        eventName: "title-update",
        onMessage: (event) => {
            try {
                const parsed = JSON.parse(event.data);
                
                if (parsed.title) {
                    // Update the page title
                    document.title = `${parsed.title} - LaraChat`;
                    
                    // Update the conversation title via callback
                    if (onTitleUpdate) {
                        onTitleUpdate(parsed.title);
                    }
                }
            } catch (error) {
                console.error('Error parsing title update:', error);
            }
        },
        onError: (error) => {
            console.error('EventStream error:', error);
        },
        onComplete: () => {
            // Title stream completed
        },
    });

    // Don't render anything - this is just a listener component
    return null;
}
