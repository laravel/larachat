import { useEventStream } from '@laravel/stream-react';

interface SidebarTitleUpdaterProps {
    chatId: number;
    onComplete: () => void;
}

export default function SidebarTitleUpdater({ chatId, onComplete }: SidebarTitleUpdaterProps) {
    useEventStream(`/chat/${chatId}/title-stream`, {
        eventName: "title-update",
        endSignal: "</stream>",
        onMessage: (event) => {
            try {
                const parsed = JSON.parse(event.data);
                
                if (parsed.title) {
                    // Broadcast to any listening components (like ChatList)
                    window.dispatchEvent(new CustomEvent('chatTitleUpdated', { 
                        detail: { chatId, newTitle: parsed.title } 
                    }));
                }
            } catch (error) {
                console.error('Error parsing sidebar title:', error);
            }
        },
        onComplete: () => {
            onComplete();
        },
        onError: (error) => {
            console.error('Sidebar title update error:', error);
            onComplete();
        },
    });

    // This component doesn't render anything
    return null;
}
