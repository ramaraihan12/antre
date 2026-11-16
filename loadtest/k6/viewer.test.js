import assert from 'node:assert/strict';
import { test } from 'node:test';
import { contentOf, createViewer, recentAdvances } from './viewer.js';

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

test('a newer version carrying older never-displayed content counts as stale (call vanishes from the head)', () => {
    const tv = createViewer();
    tv.receive(snap(1, ['A-029'], ['A-029@Loket 1:call', 'A-028@Loket 2:call']));
    // The A-029 call is gone from the list, and this exact content was never shown before.
    const r = tv.receive(snap(2, ['A-029'], ['A-028@Loket 2:call', 'A-027@Loket 3:call']));

    assert.equal(r.displayed, true);
    assert.equal(r.stale, true);
});

test('a freed ticket put back on a counter is the stale one, the correct return is not', () => {
    const tv = createViewer();
    tv.receive(snap(1, ['A-031'], ['A-031@Loket 4:call']));
    tv.receive(snap(2, [null], ['A-031@Loket 4:call']));
    const stale = tv.receive(snap(3, ['A-031'], ['A-031@Loket 4:call']));
    const correct = tv.receive(snap(4, [null], ['A-031@Loket 4:call']));

    assert.equal(stale.stale, true);
    assert.equal(correct.stale, false);
    assert.equal(tv.tally().staleDisplayed, 1);
});

test('new calls at the head of the recent list are not stale', () => {
    const tv = createViewer();
    tv.receive(snap(1, ['A-001'], ['A-001@Loket 1:call']));
    const r = tv.receive(snap(2, ['A-001', 'A-002'], ['A-002@Loket 2:call', 'A-001@Loket 1:call']));

    assert.equal(r.stale, false);
});

test('a new call appearing below the head is stale: ids follow commit order under the branch lock', () => {
    const tv = createViewer();
    tv.receive(snap(1, ['B-030'], ['B-030', 'C-024', 'C-023']));
    const r = tv.receive(snap(2, ['B-030'], ['B-030', 'A-029', 'C-024', 'C-023']));

    assert.equal(r.stale, true);
});

test('a second recall of the same ticket at the same counter is not stale', () => {
    const tv = createViewer();
    tv.receive(snap(1, ['A-001'], ['A-001@Loket 1:recall', 'A-002@Loket 2:call']));
    const r = tv.receive(snap(2, ['A-001'], ['A-001@Loket 1:recall', 'A-001@Loket 1:recall', 'A-002@Loket 2:call']));

    assert.equal(r.displayed, true);
    assert.equal(r.stale, false);
});

test('the older duplicate dropping off the full tail is not stale', () => {
    const tv = createViewer();
    const dup = 'A-001@Loket 1:recall';
    tv.receive(snap(1, ['A-001'], [dup, 'a', 'b', 'c', dup]));
    const r = tv.receive(snap(2, ['A-001'], [dup, dup, 'a', 'b', 'c']));

    assert.equal(r.stale, false);
});

test('a genuinely older list after duplicates is stale', () => {
    const tv = createViewer();
    const dup = 'A-001@Loket 1:recall';
    tv.receive(snap(1, ['A-001'], [dup, dup, 'a']));
    const r = tv.receive(snap(2, ['A-001'], [dup, 'a']));

    assert.equal(r.displayed, true);
    assert.equal(r.stale, true);
});

const list = (str) => str.split('');

test('recentAdvances: shown entries keep their positions, only the tail may fall off', () => {
    assert.equal(recentAdvances(['a', 'b', 'c'], ['x', 'a', 'b']), false); // tail dropped but list not full
    assert.equal(recentAdvances(['a', 'b', 'c', 'd', 'e'], ['x', 'a', 'b', 'c', 'd']), true);
    assert.equal(recentAdvances(['a', 'b', 'c'], ['x', 'y', 'a', 'b', 'c']), true);
    assert.equal(recentAdvances(['a', 'b', 'c'], ['a', 'x', 'b', 'c']), false);
    assert.equal(recentAdvances(['a', 'b', 'c'], ['b', 'a', 'c']), false);
    assert.equal(recentAdvances(['a', 'b', 'c'], ['a', 'c']), false);
    assert.equal(recentAdvances(['a', 'b', 'c'], ['x', 'b', 'c']), false);
});

test('recentAdvances: no overlap counts as an advance only when the list is full', () => {
    assert.equal(recentAdvances(['a', 'b'], ['v', 'w', 'x', 'y', 'z']), true);
    assert.equal(recentAdvances(['a', 'b'], ['v', 'w']), false);
});

test('recentAdvances: a full list that is older or reordered is stale', () => {
    assert.equal(recentAdvances(list('fedcb'), list('edcba')), false);
    assert.equal(recentAdvances(list('abcde'), list('bacde')), false);
    assert.equal(recentAdvances(list('abcde'), list('xbcde')), false);
    assert.equal(recentAdvances(list('abcde'), list('xabcd')), true);
    assert.equal(recentAdvances(list('abcde'), list('vwxyz')), true);
});

test('createViewer: a newer version carrying an older full list is stale', () => {
    const v = createViewer();
    const snap = (version, recent) => ({ version, counters: ['A-001'], recent });
    v.receive(snap(1, list('fedcb')));
    assert.equal(v.receive(snap(2, list('edcba'))).stale, true);
});
