import assert from 'node:assert/strict';
import { test } from 'node:test';
import { contentOf, createViewer } from './viewer.js';

const snap = (version, counters, recent = []) => ({ version, counters, recent });

test('a snapshot with a lower version arriving late is dropped, not displayed', () => {
    const tv = createViewer();
    tv.receive(snap(2, ['A-001']));
    const r = tv.receive(snap(1, [null]));

    assert.equal(r.displayed, false);
    assert.equal(tv.final().version, 2);
    // Reordered delivery shows up only in the informational raw counter.
    assert.deepEqual([tv.tally().rawRegressions, tv.tally().staleDisplayed], [1, 0]);
});

test('an equal version is not displayed, even with different content', () => {
    const tv = createViewer();
    tv.receive(snap(5, ['A-001']));
    const r = tv.receive(snap(5, [null]));

    assert.equal(r.displayed, false);
    assert.equal(r.sameVersionDifferentContent, true);
    assert.equal(tv.final().content, contentOf(snap(5, ['A-001'])));
});

test('an equal version with equal content is a plain duplicate', () => {
    const tv = createViewer();
    tv.receive(snap(5, ['A-001']));
    const r = tv.receive(snap(5, ['A-001']));

    assert.deepEqual(r, { displayed: false, stale: false, sameVersionDifferentContent: false });
});

test('a newer version is displayed and moves the screen forward', () => {
    const tv = createViewer();
    tv.receive(snap(1, ['A-001'], ['A-001@Loket 1:call']));
    const r = tv.receive(snap(2, [null], ['A-001@Loket 1:call']));

    assert.equal(r.displayed, true);
    assert.equal(r.stale, false);
    assert.equal(tv.final().version, 2);
});

test('a newer version carrying content that was already replaced counts as stale', () => {
    const tv = createViewer();
    tv.receive(snap(1, ['A-001']));
    tv.receive(snap(2, [null]));
    // Version 3 shows the state of version 1 again: older content on the screen.
    const r = tv.receive(snap(3, ['A-001']));

    assert.equal(r.displayed, true);
    assert.equal(r.stale, true);
    assert.equal(tv.tally().staleDisplayed, 1);
});

test('the same content under a new version is not stale', () => {
    const tv = createViewer();
    tv.receive(snap(1, ['A-001']));
    const r = tv.receive(snap(2, ['A-001']));

    assert.equal(r.stale, false);
});

test('counters can be given as objects the way the server sends them', () => {
    assert.equal(contentOf({ counters: [{ code: 'A-001' }, { code: null }], recent: [{ code: 'A-001', counter: 'Loket 1', kind: 'call' }] }), contentOf(snap(0, ['A-001', null], ['A-001@Loket 1:call'])));
});

test('final() is null before anything arrived', () => {
    assert.equal(createViewer().final(), null);
});
