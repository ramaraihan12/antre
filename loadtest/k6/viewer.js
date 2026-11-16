// What one TV shows, replayed from the snapshots it receives. Plain JavaScript with no k6 imports,
// so k6 and `node --test` load the same file. The accept rule mirrors pickSnapshot in
// resources/js/lib/display-version.ts: a pushed snapshot has to be strictly newer than the one on
// screen, anything else is dropped.

/** One string for what a snapshot shows: the code on every counter and the recent calls. */
export function contentOf(snapshot) {
    return JSON.stringify([snapshot.counters.map((c) => (c && typeof c === 'object' ? c.code : c)), snapshot.recent.map((r) => (typeof r === 'string' ? r : `${r.code}@${r.counter}:${r.kind}`))]);
}

export function createViewer() {
    let shown = null; // { version, content } on screen
    const seenAt = new Map(); // version -> content of the first arrival with that version
    const displayedBefore = new Set(); // contents that were on screen at some point
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
            // Older content put on screen: this exact content was already shown before and a
            // different one replaced it. Every real change leaves a new call log or occupancy, so a
            // state does not legitimately come back.
            const stale = displayed && shown !== null && content !== shown.content && displayedBefore.has(content);

            if (displayed) {
                shown = { version: snapshot.version, content };
                displayedBefore.add(content);
                tally.displayed++;
                if (stale) tally.staleDisplayed++;
            }

            return { displayed, stale: displayed && stale, sameVersionDifferentContent };
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
