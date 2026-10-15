// Single HTTP entry point. Same-origin cookie session (Laravel Sanctum SPA mode): no tokens are stored in JavaScript.
const BASE = '/api/v1';

export class ApiError extends Error {
  constructor(status, body = {}) {
    super(body.message || `Request failed (${status})`);
    this.name = 'ApiError';
    this.status = status;
    this.code = body.code || null;
    this.errors = body.errors || null; // { field: [messages] }
    this.details = body.details || null;
  }
  /** First validation message for a field, or undefined. */
  field(name) { return this.errors?.[name]?.[0]; }
}

let csrfPromise = null;
function ensureCsrf() {
  if (!csrfPromise) {
    csrfPromise = fetch('/sanctum/csrf-cookie', { credentials: 'include' }).then((r) => {
      if (!r.ok) throw new ApiError(r.status, { message: 'Could not start a secure session.' });
    }).catch((e) => { csrfPromise = null; throw e; });
  }
  return csrfPromise;
}
const xsrfToken = () => {
  const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
  return m ? decodeURIComponent(m[1]) : null;
};

function qs(query) {
  if (!query) return '';
  const p = new URLSearchParams();
  Object.entries(query).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') p.append(k, v); });
  const s = p.toString();
  return s ? `?${s}` : '';
}

export const authEvents = new EventTarget();

export async function api(path, { method = 'GET', body, query, signal, headers = {}, blob = false } = {}, retried = false) {
  const unsafe = method !== 'GET' && method !== 'HEAD';
  if (unsafe) await ensureCsrf();
  const init = { method, credentials: 'include', signal, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...headers } };
  if (unsafe && xsrfToken()) init.headers['X-XSRF-TOKEN'] = xsrfToken();
  if (body instanceof FormData) init.body = body;
  else if (body !== undefined) { init.body = JSON.stringify(body); init.headers['Content-Type'] = 'application/json'; }

  let res;
  try {
    res = await fetch(`${BASE}${path}${qs(query)}`, init);
  } catch (e) {
    if (e.name === 'AbortError') throw e;
    throw new ApiError(0, { message: 'You appear to be offline or the server is unreachable.', code: 'network' });
  }
  if (res.status === 419 && !retried) { csrfPromise = null; return api(path, { method, body, query, signal, headers, blob }, true); }
  if (blob && res.ok) return res.blob();
  let data = null;
  const text = await res.text();
  if (text) { try { data = JSON.parse(text); } catch { data = null; } }
  if (!res.ok) {
    const err = new ApiError(res.status, data || {});
    if (res.status === 401 && !path.startsWith('/auth/login') && path !== '/auth/me') authEvents.dispatchEvent(new Event('expired'));
    throw err;
  }
  return data;
}

export const get = (p, query, opts) => api(p, { ...opts, query });
export const post = (p, body, opts) => api(p, { ...opts, method: 'POST', body });
export const put = (p, body, opts) => api(p, { ...opts, method: 'PUT', body });
export const del = (p, opts) => api(p, { ...opts, method: 'DELETE' });
