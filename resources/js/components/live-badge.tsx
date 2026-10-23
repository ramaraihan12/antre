import { cn } from '@/lib/utils';
import { useConnectionStatus } from '@laravel/echo-react';

/** A small dot that shows whether the websocket to Reverb is up. */
export default function LiveBadge({ className }: { className?: string }) {
    const status = useConnectionStatus();
    const connected = status === 'connected';

    return (
        <span
            className={cn('text-muted-foreground inline-flex items-center gap-1.5 text-xs', className)}
            data-testid="live-badge"
            data-status={status}
        >
            <span className={cn('size-2 rounded-full', connected ? 'bg-emerald-500' : 'bg-amber-500')} />
            {connected ? 'Langsung' : 'Menyambung'}
        </span>
    );
}
