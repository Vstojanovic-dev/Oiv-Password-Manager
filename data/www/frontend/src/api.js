const DEFAULT_BASE = `${window.location.protocol}//${window.location.hostname}:8000/backend/api`;
const BASE = import.meta.env.VITE_API_BASE || DEFAULT_BASE;
let csrfToken = null;

async function ensureCsrfToken() {
  if (csrfToken) return csrfToken;
  const res = await fetch(`${BASE}/auth/csrf`, { credentials: 'include' });
  if (!res.ok) {
    throw new Error(`Could not get CSRF token (${res.status}).`);
  }
  const json = await res.json();
  csrfToken = json?.data?.csrf_token || null;
  if (!csrfToken) {
    throw new Error('Could not get CSRF token.');
  }
  return csrfToken;
}

/**
 * @param {string} path
 * @param {{ method?: string, body?: unknown, isForm?: boolean }} [options]
 */
export async function api(path, options = {}) {
  const { method = 'GET', body, isForm = false } = options;
  /** @type {RequestInit} */
  const init = { method, credentials: 'include', headers: {} };
  const upperMethod = method.toUpperCase();

  if (['POST', 'PUT', 'DELETE'].includes(upperMethod)) {
    const token = await ensureCsrfToken();
    if (token) init.headers['X-CSRF-Token'] = token;
  }

  if (body != null && !isForm) {
    init.headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(body);
  } else if (body != null && isForm) {
    init.body = body;
  }

  let res;
  try {
    res = await fetch(`${BASE}${path}`, init);
  } catch {
    throw new Error(`API is not reachable at ${BASE}.`);
  }

  let json;
  try {
    json = await res.json();
  } catch {
    throw new Error(`API returned a non-JSON response (${res.status}).`);
  }

  if (json?.data?.csrf_token) csrfToken = json.data.csrf_token;
  return json;
}
