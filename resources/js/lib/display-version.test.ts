import assert from 'node:assert/strict';
import { test } from 'node:test';
import { pickSnapshot } from './display-version.ts';

const snap = (version: number, code: string | null) => ({
    counters: [{ number: 1, label: 'Loket 1', code }],
    recent: [],
    version,
    built_at: 0,
});

test('a pushed snapshot with the same version does not replace what is shown', () => {
    const shown = snap(7, 'A-001');
    assert.equal(pickSnapshot(shown, snap(7, null), 'push'), shown);
});

test('a pushed snapshot that is older is ignored', () => {
    const shown = snap(7, 'A-001');
    assert.equal(pickSnapshot(shown, snap(6, null), 'push'), shown);
});

test('a pushed snapshot that is newer replaces it', () => {
    const next = snap(8, null);
    assert.equal(pickSnapshot(snap(7, 'A-001'), next, 'push'), next);
});

test('page props after a reconnect or reload replace the same version', () => {
    const fetched = snap(7, null);
    assert.equal(pickSnapshot(snap(7, 'A-001'), fetched, 'fetch'), fetched);
});

test('page props never replace a newer snapshot', () => {
    const shown = snap(9, 'A-002');
    assert.equal(pickSnapshot(shown, snap(8, null), 'fetch'), shown);
});
