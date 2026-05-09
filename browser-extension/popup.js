const WEB_APP_URL = 'http://localhost:3000';

const elements = {
  domain: document.getElementById('domain'),
  refresh: document.getElementById('refresh'),
  notice: document.getElementById('notice'),
  locked: document.getElementById('locked'),
  content: document.getElementById('content'),
  authStatus: document.getElementById('auth-status'),
  formStatus: document.getElementById('form-status'),
  savePrompt: document.getElementById('save-prompt'),
  saveTitle: document.getElementById('save-title'),
  saveDescription: document.getElementById('save-description'),
  saveConfirm: document.getElementById('save-confirm'),
  saveDismiss: document.getElementById('save-dismiss'),
  empty: document.getElementById('empty'),
  accounts: document.getElementById('accounts'),
  openApp: document.getElementById('open-app'),
  openVault: document.getElementById('open-vault'),
  openVaultEmpty: document.getElementById('open-vault-empty'),
};

let activeTab = null;
let activeHostname = '';
let pendingCredential = null;

function sendRuntimeMessage(message) {
  return chrome.runtime.sendMessage(message);
}

async function getActiveTab() {
  const tabs = await chrome.tabs.query({ active: true, currentWindow: true });
  return tabs[0] || null;
}

async function sendTabMessage(tabId, message) {
  try {
    return await chrome.tabs.sendMessage(tabId, message);
  } catch {
    await chrome.scripting.executeScript({
      target: { tabId },
      files: ['content.js'],
    });
    return chrome.tabs.sendMessage(tabId, message);
  }
}

function getHostname(url) {
  try {
    return new URL(url).hostname;
  } catch {
    return '';
  }
}

function setNotice(message, type = 'info') {
  elements.notice.textContent = message;
  elements.notice.className = `notice ${type}`;
  elements.notice.classList.toggle('hidden', !message);
}

function setVisible(element, visible) {
  element.classList.toggle('hidden', !visible);
}

function openVault() {
  chrome.tabs.create({ url: WEB_APP_URL });
}

async function detectForm(tabId) {
  try {
    const response = await sendTabMessage(tabId, { type: 'DETECT_FORM' });
    if (!response?.success) return { hasForm: false, reason: response?.error || 'No password field detected.' };
    return response.data;
  } catch {
    return { hasForm: false, reason: 'No password field detected.' };
  }
}

function renderAccounts(accounts, formInfo) {
  elements.accounts.innerHTML = '';
  setVisible(elements.empty, accounts.length === 0);

  for (const account of accounts) {
    const row = document.createElement('article');
    row.className = 'account';

    const meta = document.createElement('div');
    meta.className = 'account-meta';

    const title = document.createElement('strong');
    title.textContent = account.site_name || 'Untitled entry';

    const username = document.createElement('span');
    username.textContent = account.username || 'No username';

    const details = document.createElement('small');
    details.textContent = [account.category, account.favorite ? 'favorite' : null].filter(Boolean).join(' · ');

    meta.append(title, username);
    if (details.textContent) meta.append(details);

    const fill = document.createElement('button');
    fill.className = 'primary small';
    fill.type = 'button';
    fill.textContent = 'Fill';
    fill.disabled = !formInfo.hasForm;
    fill.addEventListener('click', () => fillAccount(account, fill));

    row.append(meta, fill);
    elements.accounts.append(row);
  }
}

function renderSavePrompt(pending) {
  pendingCredential = pending;
  setVisible(elements.savePrompt, Boolean(pending && pending.mode !== 'same'));

  if (!pending || pending.mode === 'same') return;

  const isUpdate = pending.mode === 'update';
  elements.saveTitle.textContent = isUpdate ? 'Update saved password?' : 'Save new password?';
  elements.saveDescription.textContent = isUpdate
    ? `Update ${pending.account?.site_name || pending.hostname} for ${pending.username}?`
    : `Save ${pending.hostname} for ${pending.username}?`;
  elements.saveConfirm.textContent = isUpdate ? 'Update' : 'Save';
}

async function loadPendingPrompt() {
  const response = await sendRuntimeMessage({
    type: 'GET_PENDING_CREDENTIAL',
    hostname: activeHostname,
  });

  if (!response?.success) {
    setNotice(response?.error || 'Could not check pending credential.', 'error');
    renderSavePrompt(null);
    return;
  }

  renderSavePrompt(response.data);
}

async function savePendingCredential() {
  if (!pendingCredential) return;

  elements.saveConfirm.disabled = true;
  elements.saveDismiss.disabled = true;
  setNotice('', 'info');

  try {
    const response = await sendRuntimeMessage({
      type: 'SAVE_PENDING_CREDENTIAL',
      pendingId: pendingCredential.id,
      action: pendingCredential.mode === 'update' ? 'update' : 'save',
    });

    if (!response?.success) {
      throw new Error(response?.error || 'Could not save password.');
    }

    const savedMode = pendingCredential.mode;
    renderSavePrompt(null);
    setNotice(savedMode === 'update' ? 'Saved password updated.' : 'Password saved to vault.', 'success');

    const formInfo = await detectForm(activeTab.id);
    const accountsResponse = await sendRuntimeMessage({
      type: 'SEARCH_ACCOUNTS',
      hostname: activeHostname,
    });
    if (accountsResponse?.success) {
      renderAccounts(accountsResponse.data || [], formInfo);
    }
  } catch (error) {
    setNotice(error.message || 'Could not save password.', 'error');
  } finally {
    elements.saveConfirm.disabled = false;
    elements.saveDismiss.disabled = false;
  }
}

async function dismissPendingCredential() {
  if (!pendingCredential) return;

  await sendRuntimeMessage({
    type: 'DISCARD_PENDING_CREDENTIAL',
    pendingId: pendingCredential.id,
  });
  renderSavePrompt(null);
  setNotice('Save prompt dismissed.', 'info');
}

async function fillAccount(account, button) {
  const originalText = button.textContent;
  button.disabled = true;
  button.textContent = 'Filling...';
  setNotice('', 'info');

  let password = '';
  try {
    const passwordResponse = await sendRuntimeMessage({ type: 'GET_PASSWORD', accountId: account.id });
    if (!passwordResponse?.success) {
      throw new Error(passwordResponse?.error || 'Could not load password.');
    }

    password = passwordResponse.data?.password || '';
    const fillResponse = await sendTabMessage(activeTab.id, {
      type: 'FILL_CREDENTIALS',
      username: account.username || '',
      password,
    });

    password = '';

    if (!fillResponse?.success) {
      throw new Error(fillResponse?.error || 'Could not fill login form.');
    }

    const usedResponse = await sendRuntimeMessage({ type: 'MARK_USED', accountId: account.id });
    if (!usedResponse?.success) {
      setNotice('Credentials filled, but usage timestamp was not updated.', 'info');
      return;
    }

    setNotice('Credentials filled.', 'success');
  } catch (error) {
    password = '';
    setNotice(error.message || 'Autofill failed.', 'error');
  } finally {
    button.disabled = false;
    button.textContent = originalText;
  }
}

async function loadPopup() {
  setNotice('', 'info');
  setVisible(elements.locked, false);
  setVisible(elements.content, false);
  renderSavePrompt(null);
  elements.accounts.innerHTML = '';
  elements.domain.textContent = 'Checking current tab...';

  activeTab = await getActiveTab();
  activeHostname = getHostname(activeTab?.url || '');

  if (!activeTab || !activeHostname) {
    elements.domain.textContent = 'Unsupported tab';
    setNotice('Open a normal http/https page with a login form.', 'error');
    return;
  }

  elements.domain.textContent = activeHostname;

  const [statusResponse, formInfo] = await Promise.all([
    sendRuntimeMessage({ type: 'STATUS' }),
    detectForm(activeTab.id),
  ]);

  if (!statusResponse?.success || !statusResponse.data?.authenticated) {
    setVisible(elements.locked, true);
    setNotice(statusResponse?.error || 'Vault is locked.', 'error');
    return;
  }

  setVisible(elements.content, true);
  elements.authStatus.textContent = `Unlocked as ${statusResponse.data?.username || 'user'}`;
  elements.formStatus.textContent = formInfo.hasForm ? 'Login form detected' : (formInfo.reason || 'No password field detected');
  await loadPendingPrompt();

  const accountsResponse = await sendRuntimeMessage({
    type: 'SEARCH_ACCOUNTS',
    hostname: activeHostname,
  });

  if (!accountsResponse?.success) {
    setNotice(accountsResponse?.error || 'Could not load accounts.', 'error');
    renderAccounts([], formInfo);
    return;
  }

  renderAccounts(accountsResponse.data || [], formInfo);
}

elements.refresh.addEventListener('click', loadPopup);
elements.openApp.addEventListener('click', openVault);
elements.openVault.addEventListener('click', openVault);
elements.openVaultEmpty.addEventListener('click', openVault);
elements.saveConfirm.addEventListener('click', savePendingCredential);
elements.saveDismiss.addEventListener('click', dismissPendingCredential);

document.addEventListener('DOMContentLoaded', loadPopup);
