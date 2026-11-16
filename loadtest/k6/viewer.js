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
 * Whether the recent-calls list moved forward. Judged by position, not by value, because the same
 * entry can appear twice (a ticket recalled twice at one counter). A correct next list is j new
 * entries at the head followed by previous[0..k) in the same positions. k may be shorter than the
 * whole previous list only when next is full, because then the tail fell off. With nothing in
 * common (k = 0) that again needs a full list: at least `capacity` calls landed in between.
 */
export function recentAdvances(previous, next, capacity = 5) {
    if (previous.length === 0) return true;
    for (let head = 0; head <= next.length; head++) {
        const k = next.length - head;
        if (k > previous.length) continue;
        if (k < previous.length && next.length < capacity) continue;
        let same = true;
        for (let i = 0; i < k && same; i++) same = next[head + i] === previous[i];
        if (same) return true;
    }
    return false;
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
