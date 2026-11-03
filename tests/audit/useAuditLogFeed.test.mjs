import assert from 'node:assert/strict';
import { setTimeout as delay } from 'node:timers/promises';
import { test } from 'node:test';
import axios from 'axios';
import { createRenderer, nextTick, reactive } from 'vue';
import { useAuditLogFeed } from '../../resources/js/composables/useAuditLogFeed.ts';

const renderer = createRenderer({
    insert() {}, remove() {}, patchProp() {}, setText() {}, setElementText() {},
    createElement: () => ({}), createText: () => ({}), createComment: () => ({}),
    parentNode: () => null, nextSibling: () => null,
});

function mountFeed(t, page = { auditLogs: [{ id: 1 }], nextCursor: 'page-two' }) {
    const initial = page ? reactive(page) : undefined;
    let feed;
    const app = renderer.createApp({
        setup() {
            feed = useAuditLogFeed(() => '/audit-log', () => initial);
            return () => null;
        },
    });
    app.mount({});
    let mounted = true;
    const unmount = () => { if (mounted) app.unmount(); mounted = false; };
    t.after(unmount);
    return { feed, initial, unmount };
}

test('a lazily opened log loads rows and options, retries failures and aborts when closed', async (t) => {
    const request = t.mock.method(axios, 'get', async () => { throw new Error('Offline'); });
    const { feed, unmount } = mountFeed(t, null);
    assert.equal(feed.loading.value, true);
    assert.deepEqual(feed.rows.value, []);
    assert.deepEqual(request.mock.calls[0].arguments[1].params, { include_options: '1' });
    await nextTick();
    assert.equal(feed.failed.value, true);
    const options = { actions: [], severities: [], users: [], activities: [] };
    request.mock.mockImplementation(async () => ({ data: { auditLogs: [{ id: 2 }], nextCursor: 'next', filters: options } }));
    feed.retry();
    await nextTick();
    assert.deepEqual(feed.filterOptions.value, options);
    assert.deepEqual(feed.rows.value.map(row => row.id), [2]);

    let finish;
    request.mock.mockImplementation(() => new Promise(resolve => { finish = resolve; }));
    feed.loadMore();
    assert.deepEqual(request.mock.calls[2].arguments[1].params, { cursor: 'next' });
    unmount();
    assert.equal(request.mock.calls[2].arguments[1].signal.aborted, true);
    finish({ data: { auditLogs: [{ id: 99 }], nextCursor: null } });
    await nextTick();
    assert.deepEqual(feed.rows.value.map(row => row.id), [2]);
});

test('requests the next cursor only once and appends without duplicate rows', async (t) => {
    let resolve;
    const request = t.mock.method(axios, 'get', () => new Promise((done) => { resolve = done; }));
    const { feed } = mountFeed(t);
    feed.loadMore();
    feed.loadMore();
    assert.equal(request.mock.callCount(), 1);
    assert.equal(request.mock.calls[0].arguments[1].params.cursor, 'page-two');
    resolve({ data: { auditLogs: [{ id: 1 }, { id: 2 }], nextCursor: null } });
    await nextTick();
    assert.deepEqual(feed.rows.value.map((row) => row.id), [1, 2]);
    assert.equal(feed.hasMore.value, false);
});

test('filter changes cancel stale pages and restart at the first page', async (t) => {
    const requests = [];
    t.mock.method(axios, 'get', (url, options) => new Promise((resolve) => {
        requests.push({ options, resolve });
    }));
    const { feed } = mountFeed(t);
    feed.loadMore();
    feed.filters.value.search = 'new run';
    assert.equal(requests[0].options.signal.aborted, true);
    requests[0].resolve({ data: { auditLogs: [{ id: 99 }], nextCursor: 'stale' } });
    await nextTick();
    assert.deepEqual(feed.rows.value, []);
    await delay(275);
    assert.equal(requests.length, 2);
    assert.deepEqual(requests[1].options.params, { search: 'new run' });
    requests[1].resolve({ data: { auditLogs: [{ id: 3 }], nextCursor: null } });
    await nextTick();
    assert.deepEqual(feed.rows.value.map((row) => row.id), [3]);
    assert.equal(feed.loading.value, false);
});

test('failed incremental loads retain rows and can retry the same cursor', async (t) => {
    const request = t.mock.method(axios, 'get', async () => { throw new Error('Offline'); });
    const { feed } = mountFeed(t);
    feed.loadMore();
    await nextTick();
    assert.equal(feed.failed.value, true);
    assert.deepEqual(feed.rows.value.map((row) => row.id), [1]);
    request.mock.mockImplementation(async () => ({ data: { auditLogs: [{ id: 2 }], nextCursor: null } }));
    feed.retry();
    await nextTick();
    assert.equal(request.mock.calls[1].arguments[1].params.cursor, 'page-two');
    assert.equal(feed.failed.value, false);
    assert.deepEqual(feed.rows.value.map((row) => row.id), [1, 2]);
});

test('new page props reset filters without issuing an extra request', async (t) => {
    const request = t.mock.method(axios, 'get', async () => { throw new Error('Unexpected request'); });
    const { feed, initial } = mountFeed(t);
    feed.filters.value.activity = '123';
    initial.selectedFilters = { action: 'group.activity.created' };
    initial.auditLogs = [{ id: 5 }];
    initial.nextCursor = null;
    await nextTick();
    await delay(275);
    assert.equal(request.mock.callCount(), 0);
    assert.equal(feed.filters.value.activity, '__all__');
    assert.equal(feed.filters.value.action, 'group.activity.created');
    assert.deepEqual(feed.rows.value.map((row) => row.id), [5]);
});
