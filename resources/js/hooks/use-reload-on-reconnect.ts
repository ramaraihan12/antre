import { router } from '@inertiajs/react';
import { useConnectionStatus } from '@laravel/echo-react';
import { useEffect, useRef } from 'react';

/**
 * Events sent while the websocket was down are lost, so when it comes back the page asks the
 * server for the props it would otherwise have missed.
 */
export function useReloadOnReconnect(only: string[]) {
    const status = useConnectionStatus();
    const dropped = useRef(false);
    const keys = only.join(',');

    useEffect(() => {
        if (status === 'connected') {
            if (dropped.current) {
                dropped.current = false;
                router.reload({ only: keys.split(',') });
            }
        } else {
            dropped.current = true;
        }
    }, [status, keys]);
}
