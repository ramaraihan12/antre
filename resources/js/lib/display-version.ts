import type { DisplaySnapshot } from '@/types/antre';

/** Where a snapshot came from: pushed over the websocket, or fetched from the server as page props. */
export type SnapshotSource = 'push' | 'fetch';

/** Decides which snapshot the TV keeps. */
export function pickSnapshot(current: DisplaySnapshot, next: DisplaySnapshot, source: SnapshotSource): DisplaySnapshot {
    void source;
    return next.version >= current.version ? next : current;
}
