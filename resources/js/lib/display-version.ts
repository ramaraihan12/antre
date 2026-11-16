import type { DisplaySnapshot } from '@/types/antre';

/** Where a snapshot came from: pushed over the websocket, or fetched from the server as page props. */
export type SnapshotSource = 'push' | 'fetch';

/**
 * Decides which snapshot the TV keeps. The server gives every change its own version, so a pushed
 * snapshot has to be strictly newer: an equal one is the same change or a reordered copy of it.
 * Page props come from a fresh request after a reconnect or reload and may repeat the current
 * version, so those also win on a tie. Either way an older snapshot never replaces a newer one.
 */
export function pickSnapshot(current: DisplaySnapshot, next: DisplaySnapshot, source: SnapshotSource): DisplaySnapshot {
    if (next.version > current.version) return next;
    if (next.version === current.version && source === 'fetch') return next;
    return current;
}
