import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, api, authEvents, get, post } from './client.js';

const json = (status, body) => new Response(body === undefined ? '' : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

describe('api client', () => {
  let fetchMock;
  beforeEach(() => { fetchMock = vi.fn(); vi.stubGlobal('fetch', fetchMock); document.cookie = 'XSRF-TOKEN=tok%3D; path=/'; });
  afterEach(() => vi.unstubAllGlobals());

  it('GET sends credentials, JSON accept and query string without empty values', async () => {
    fetchMock.mockResolvedValueOnce(json(200, { data: [] }));
    await get('/courses', { q: 'grace', page: 2, empty: '', none: null });
    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe('/api/v1/courses?q=grace&page=2');
    expect(init.credentials).toBe('include');
    expect(init.headers.Accept).toBe('application/json');
  });

  it('unsafe requests fetch the CSRF cookie once and send the decoded XSRF header', async () => {
    fetchMock.mockImplementation(async (u) => (u === '/sanctum/csrf-cookie' ? new Response(null, { status: 204 }) : json(200, { ok: true })));
    await post('/x', { a: 1 });
    await post('/y', { b: 2 });
    const csrfCalls = fetchMock.mock.calls.filter(([u]) => u === '/sanctum/csrf-cookie');
    expect(csrfCalls).toHaveLength(1);
    const [, init] = fetchMock.mock.calls.find(([u]) => u === '/api/v1/x');
    expect(init.headers['X-XSRF-TOKEN']).toBe('tok=');
    expect(init.body).toBe('{"a":1}');
  });

  it('retries once after a 419 with a fresh CSRF cookie', async () => {
    let n = 0;
    fetchMock.mockImplementation(async (u) => {
      if (u === '/sanctum/csrf-cookie') return new Response(null, { status: 204 });
      return n++ === 0 ? json(419, { message: 'expired' }) : json(200, { done: true });
    });
    await expect(api('/z', { method: 'POST', body: {} })).resolves.toEqual({ done: true });
  });

  it('throws ApiError carrying validation messages', async () => {
    fetchMock.mockResolvedValueOnce(json(422, { message: 'Invalid', errors: { email: ['Taken'] } }));
    const err = await get('/a').catch((e) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect(err.status).toBe(422);
    expect(err.field('email')).toBe('Taken');
  });

  it('announces an expired session on 401 except for login attempts', async () => {
    const seen = vi.fn(); authEvents.addEventListener('expired', seen);
    fetchMock.mockResolvedValue(json(401, { message: 'Unauthenticated' }));
    await get('/me/things').catch(() => {});
    expect(seen).toHaveBeenCalledTimes(1);
    await get('/auth/login').catch(() => {});
    expect(seen).toHaveBeenCalledTimes(1);
    authEvents.removeEventListener('expired', seen);
  });

  it('reports network failure as a status-0 error, never silently', async () => {
    fetchMock.mockRejectedValueOnce(new TypeError('failed'));
    const err = await get('/a').catch((e) => e);
    expect(err.status).toBe(0);
    expect(err.code).toBe('network');
  });
});
