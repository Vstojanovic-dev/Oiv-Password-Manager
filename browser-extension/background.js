const API_BASE = 'http://localhost:8000/backend/api';

let csrfToken = null;
const pendingCredentials = new Map();
const PENDING_TTL_MS = 5 * 60 * 1000;

async function ensureCsrfToken() {
  if (csrfToken) return csrfToken;

  const response = await fetch(`${API_BASE}/auth/csrf`, {
    credentials: 'include',
  });
  const json = await parseJson(response);
  csrfToken = json?.data?.csrf_token || null;

  if (!response.ok || !csrfToken) {
    throw new Error(json?.error || `Could not get CSRF token (${response.status}).`);
  }

  return csrfToken;
}

async function api(path, options = {}) {
  const method = (options.method || 'GET').toUpperCase();
  const headers = {};

  if (['POST', 'PUT', 'DELETE'].includes(method)) {
    headers['X-CSRF-Token'] = await ensureCsrfToken();
  }

  const init = {
    method,
    credentials: 'include',
    headers,
  };

  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(options.body);
  }

  let response;
  try {
    response = await fetch(`${API_BASE}${path}`, init);
  } catch {
    throw new Error(`Password Manager API is not reachable at ${API_BASE}.`);
  }

  const json = await parseJson(response);
  if (json?.data?.csrf_token) csrfToken = json.data.csrf_token;

  return json;
}

async function parseJson(response) {
  try {
    return await response.json();
  } catch {
    return {
      success: false,
      error: `API returned a non-JSON response (${response.status}).`,
    };
  }
}

function searchTermsForHost(hostname) {
  const clean = hostname.toLowerCase().replace(/^www\./, '');
  const terms = [hostname.toLowerCase()];
  if (clean !== terms[0]) terms.push(clean);

  return [...new Set(terms.filter(Boolean))];
}

async function searchAccounts(hostname) {
  const byId = new Map();

  for (const term of searchTermsForHost(hostname)) {
    const response = await api(`/accounts?search=${encodeURIComponent(term)}`);
    if (!response.success) return response;

    for (const account of response.data || []) {
      byId.set(account.id, account);
    }

    if (byId.size > 0) break;
  }

  return { success: true, data: [...byId.values()] };
}

async function getPassword(accountId) {
  const passwordResponse = await api(`/accounts/${accountId}/password`);
  if (!passwordResponse.success) return passwordResponse;

  return {
    success: true,
    data: {
      password: passwordResponse.data?.password || '',
    },
  };
}

async function markUsed(accountId) {
  return api(`/accounts/${accountId}/used`, { method: 'POST' });
}

function pendingKeyForUrl(url) {
  try {
    return new URL(url).hostname.toLowerCase();
  } catch {
    return '';
  }
}

function pendingKeyForSender(sender, url) {
  const tabId = sender?.tab?.id;
  if (typeof tabId === 'number') return `tab:${tabId}`;
  return `host:${pendingKeyForUrl(url)}`;
}

function prunePendingCredentials() {
  const now = Date.now();
  for (const [key, pending] of pendingCredentials.entries()) {
    if (now - pending.createdAt > PENDING_TTL_MS) {
      pendingCredentials.delete(key);
    }
  }
}

async function updateBadgeForPendingCount() {
  prunePendingCredentials();
  const count = new Set([...pendingCredentials.values()].map((pending) => pending.id)).size;
  await chrome.action.setBadgeBackgroundColor({ color: '#0f172a' });
  await chrome.action.setBadgeText({ text: count > 0 ? 'SAVE' : '' });
}

function sanitizeUrlForEntry(url) {
  try {
    const parsed = new URL(url);
    return `${parsed.protocol}//${parsed.hostname}`;
  } catch {
    return null;
  }
}

function sameUsername(a, b) {
  return String(a || '').trim().toLowerCase() === String(b || '').trim().toLowerCase();
}

async function classifyPendingCredential(pending) {
  const accountsResponse = await searchAccounts(pending.hostname);
  if (!accountsResponse.success) return accountsResponse;

  const accounts = accountsResponse.data || [];
  const matchingAccount = accounts.find((account) => sameUsername(account.username, pending.username)) || null;

  if (!matchingAccount) {
    return {
      success: true,
      data: {
        mode: 'save',
        account: null,
      },
    };
  }

  const passwordResponse = await getPassword(matchingAccount.id);
  if (!passwordResponse.success) return passwordResponse;

  return {
    success: true,
    data: {
      mode: passwordResponse.data.password === pending.password ? 'same' : 'update',
      account: matchingAccount,
    },
  };
}

async function getPendingCredential(hostname) {
  prunePendingCredentials();
  const requestedHost = String(hostname || '').toLowerCase();
  let pending = pendingCredentials.get(`host:${requestedHost}`);

  if (!pending) {
    pending = [...pendingCredentials.values()]
      .filter((candidate) => !candidate.consumed)
      .sort((a, b) => b.createdAt - a.createdAt)[0] || null;
  }

  if (!pending) {
    return { success: true, data: null };
  }

  const classification = await classifyPendingCredential(pending);
  if (!classification.success) return classification;

  return {
    success: true,
    data: {
      id: pending.id,
      hostname: pending.hostname,
      currentHostname: requestedHost,
      origin: pending.origin,
      username: pending.username,
      siteName: pending.siteName,
      createdAt: pending.createdAt,
      mode: classification.data.mode,
      account: classification.data.account,
    },
  };
}

async function savePendingCredential(pendingId, action) {
  prunePendingCredentials();
  const pending = [...pendingCredentials.values()].find((candidate) => candidate.id === pendingId);
  if (!pending) {
    return { success: false, error: 'Saved credential prompt expired. Submit the form again.' };
  }

  const classification = await classifyPendingCredential(pending);
  if (!classification.success) return classification;

  const account = classification.data.account;
  const mode = action === 'update' && account ? 'update' : 'save';
  const body = {
    site_name: account?.site_name || pending.siteName || pending.hostname,
    site_url: account?.site_url || sanitizeUrlForEntry(pending.url),
    username: pending.username,
    password: pending.password,
    favorite: Boolean(account?.favorite),
    category: account?.category || null,
    notes: account?.notes || null,
  };

  const response = mode === 'update'
    ? await api(`/accounts/${account.id}`, { method: 'PUT', body })
    : await api('/accounts', { method: 'POST', body });

  if (response.success) {
    deletePendingById(pending.id);
    await updateBadgeForPendingCount();
  }

  return response;
}

function deletePendingById(pendingId) {
  for (const [key, pending] of pendingCredentials.entries()) {
    if (pending.id === pendingId) {
      pendingCredentials.delete(key);
    }
  }
}

async function discardPendingCredential(pendingId) {
  const existed = [...pendingCredentials.values()].some((pending) => pending.id === pendingId);
  deletePendingById(pendingId);
  await updateBadgeForPendingCount();
  return { success: true, data: { discarded: existed } };
}

async function storePendingCredential(message, sender) {
  const url = String(message.url || sender?.tab?.url || '');
  const hostname = pendingKeyForUrl(url);
  const username = String(message.username || '').trim();
  const password = String(message.password || '');

  if (!hostname || !username || !password) {
    return { success: false, error: 'Incomplete credential capture ignored.' };
  }

  const pending = {
    id: crypto.randomUUID(),
    hostname,
    origin: sanitizeUrlForEntry(url),
    url,
    siteName: hostname.replace(/^www\./, ''),
    username,
    password,
    createdAt: Date.now(),
  };

  pendingCredentials.set(pendingKeyForSender(sender, url), pending);
  pendingCredentials.set(`host:${hostname}`, pending);
  await updateBadgeForPendingCount();
  return { success: true, data: { captured: true } };
}

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  (async () => {
    if (message?.type === 'STATUS') {
      sendResponse(await api('/auth/status'));
      return;
    }

    if (message?.type === 'SEARCH_ACCOUNTS') {
      sendResponse(await searchAccounts(String(message.hostname || '')));
      return;
    }

    if (message?.type === 'GET_PASSWORD') {
      sendResponse(await getPassword(Number(message.accountId)));
      return;
    }

    if (message?.type === 'MARK_USED') {
      sendResponse(await markUsed(Number(message.accountId)));
      return;
    }

    if (message?.type === 'CAPTURE_CREDENTIAL') {
      sendResponse(await storePendingCredential(message, _sender));
      return;
    }

    if (message?.type === 'GET_PENDING_CREDENTIAL') {
      sendResponse(await getPendingCredential(String(message.hostname || '')));
      return;
    }

    if (message?.type === 'SAVE_PENDING_CREDENTIAL') {
      sendResponse(await savePendingCredential(String(message.pendingId || ''), String(message.action || 'save')));
      return;
    }

    if (message?.type === 'DISCARD_PENDING_CREDENTIAL') {
      sendResponse(await discardPendingCredential(String(message.pendingId || '')));
      return;
    }

    sendResponse({ success: false, error: `Unknown extension message: ${message?.type || 'missing type'}. Reload the extension if this appears after an update.` });
  })().catch((error) => {
    sendResponse({ success: false, error: error.message || 'Extension request failed.' });
  });

  return true;
});
