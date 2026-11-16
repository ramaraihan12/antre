// What one TV shows, replayed from the snapshots it receives. Plain JavaScript with no k6 imports,
// so k6 and `node --test` load the same file. The accept rule mirrors pickSnapshot in
// resources/js/lib/display-version.ts: a pushed snapshot has to be strictly newer than the one on
// screen, anything else is dropped.

/** One string for what a snapshot shows: the code on every counter and the recent calls. */
export function contentOf(snapshot) {
    return JSON.stringify([snapshot.counters.map((c) => (c && typeof c === 'object' ? c.code : c)), snapshot.recent.map((r) => (typeof r === 'string' ? r : `${r.code}@${r.counter}:${r.kind}`))]);
}

const codesOf = (snapshot) => snapshot.counters.map((c) => (c && typeof c === 'object' ? c.code : c));
const recentOf = (snapshot) => snapshot.recent.map((r) => (typeof r === 'string' ? r : `${r.code}@${r.counter}:${r.kind}`));

/**
 * Whether the recent-calls list moved forward. Entries the TV already showed keep their relative
 * order and may only fall off the tail; new entries may appear anywhere (a call's id can be
 * handed out slightly out of commit order). A list with no entry in common counts as an advance
 * when it is full, since five or more calls landed between two displayed snapshots.
 */
export function recentAdvances(previous, next, capacity = 5) {
    if (previous.length === 0) return true;
    const kept = previous.filter((entry) => next.includes(entry));
    if (kept.length === 0) return next.length >= capacity;
    // The shown entries that survive must be in the same order, and the ones that dropped off
    // must be the oldest: only a suffix of the old list may be gone.
    const order = next.filter((entry) => previous.includes(entry));
    if (order.length !== kept.length || !order.every((entry, i) => entry === kept[i])) return false;
    return kept.every((entry, i) => entry === previous[i]);
}

export function createViewer() {
    let shown = null; // { version, content } on screen
    const seenAt = new Map(); // version -> content of the first arrival with that version
    const finished = new Set(); // codes that were on a counter and then displayed as gone: a ticket is called once, so they never come back
    const tally = { arrived: 0, displayed: 0, rawRegressions: 0, staleDisplayed: 0, sameVersionDifferentContent: 0 };
    let highestArrived = -Infinity;

    return {
        /**
         * @param {{version: number, counters: unknown[], recent: unknown[]}} snapshot
         * @returns {{displayed: boolean, stale: boolean, sameVersionDifferentContent: boolean}}
         */
        receive(snapshot) {
            const content = contentOf(snapshot);
            tally.arrived++;

            // Informational only: arrival order, before the accept rule.
            if (snapshot.version < highestArrived) tally.rawRegressions++;
            highestArrived = Math.max(highestArrived, snapshot.version);

            // Two snapshots with one version but different content: the version no longer
            // tells which one is newer, whatever the TV then does with them.
            let sameVersionDifferentContent = false;
            if (seenAt.has(snapshot.version)) {
                sameVersionDifferentContent = seenAt.get(snapshot.version) !== content;
            } else {
                seenAt.set(snapshot.version, content);
            }
            if (sameVersionDifferentContent) tally.sameVersionDifferentContent++;

            const displayed = shown === null || snapshot.version > shown.version;
            // Older content put on screen, judged with facts that only move forward: a code shown
            // as gone must not be back on a counter, and the recent calls may only gain entries at
            // the head. Exact repeats of an earlier state are fine, a newer version can hold them.
            let stale = false;
            if (displayed) {
                const codes = codesOf(snapshot).filter((code) => code);
                const recent = recentOf(snapshot);
                if (shown !== null) {
                    stale = codes.some((code) => finished.has(code)) || !recentAdvances(shown.recent, recent);
                    for (const code of shown.codes) if (!codes.includes(code)) finished.add(code);
                }
                shown = { version: snapshot.version, content, codes, recent };
                tally.displayed++;
                if (stale) tally.staleDisplayed++;
            }

            return { displayed, stale, sameVersionDifferentContent };
        },

        /** What is on screen now, or null before anything arrived. */
        final() {
            return shown;
        },

        tally() {
            return { ...tally };
        },
    };
}
