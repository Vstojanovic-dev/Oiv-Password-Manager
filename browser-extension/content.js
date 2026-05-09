function isVisibleInput(input) {
  if (!(input instanceof HTMLInputElement)) return false;
  if (input.disabled || input.readOnly) return false;
  if (input.type === 'hidden') return false;

  const rect = input.getBoundingClientRect();
  const style = window.getComputedStyle(input);
  return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
}

function findPasswordInput(root = document) {
  return [...root.querySelectorAll('input[type="password"]')]
    .find((input) => isVisibleInput(input) && !shouldIgnorePasswordInput(input)) || null;
}

function scoreUsernameCandidate(input, passwordInput) {
  if (!isVisibleInput(input) || input === passwordInput) return -1;
  if (['password', 'checkbox', 'radio', 'submit', 'button', 'file'].includes(input.type)) return -1;

  const text = `${input.name || ''} ${input.id || ''} ${input.autocomplete || ''} ${input.placeholder || ''}`.toLowerCase();
  let score = 0;

  if (input.compareDocumentPosition(passwordInput) & Node.DOCUMENT_POSITION_FOLLOWING) score += 1;
  if (input.type === 'email') score += 4;
  if (text.includes('username') || text.includes('user')) score += 5;
  if (text.includes('email') || text.includes('login')) score += 4;
  if (input.autocomplete === 'username') score += 6;

  return score;
}

function findUsernameInput(passwordInput) {
  const container = passwordInput.form || passwordInput.closest('form') || document;
  const inputs = [...container.querySelectorAll('input')].filter((input) => input !== passwordInput);
  const ranked = inputs
    .map((input) => ({ input, score: scoreUsernameCandidate(input, passwordInput) }))
    .filter((candidate) => candidate.score >= 0)
    .sort((a, b) => b.score - a.score);

  if (ranked.length > 0) return ranked[0].input;

  const beforePassword = [...document.querySelectorAll('input')]
    .filter((input) => input.compareDocumentPosition(passwordInput) & Node.DOCUMENT_POSITION_FOLLOWING)
    .filter(isVisibleInput)
    .filter((input) => !['password', 'checkbox', 'radio', 'submit', 'button', 'file'].includes(input.type));

  return beforePassword.at(-1) || null;
}

function detectLoginForm() {
  const passwordInput = findPasswordInput();
  if (!passwordInput) {
    return { hasForm: false, reason: 'No password field detected.' };
  }

  const usernameInput = findUsernameInput(passwordInput);
  return {
    hasForm: true,
    hasUsername: Boolean(usernameInput),
    hasPassword: true,
  };
}

function setInputValue(input, value) {
  const descriptor = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
  descriptor.set.call(input, value);
  input.dispatchEvent(new Event('input', { bubbles: true }));
  input.dispatchEvent(new Event('change', { bubbles: true }));
}

function fillCredentials(credentials) {
  const passwordInput = findPasswordInput();
  if (!passwordInput) {
    return { success: false, error: 'No password field detected.' };
  }

  const usernameInput = findUsernameInput(passwordInput);
  if (usernameInput && credentials.username) {
    setInputValue(usernameInput, credentials.username);
  }

  setInputValue(passwordInput, credentials.password || '');
  passwordInput.focus();

  return {
    success: true,
    data: {
      usernameFilled: Boolean(usernameInput && credentials.username),
      passwordFilled: true,
    },
  };
}

function shouldIgnorePasswordInput(input) {
  const text = `${input.name || ''} ${input.id || ''} ${input.autocomplete || ''} ${input.placeholder || ''}`.toLowerCase();
  return text.includes('new-password')
    || text.includes('confirm')
    || text.includes('confirmation')
    || text.includes('repeat')
    || text.includes('2fa')
    || text.includes('otp')
    || text.includes('code');
}

function captureCredentialsFromForm(form) {
  const passwordInput = findPasswordInput(form || document);
  if (!passwordInput || shouldIgnorePasswordInput(passwordInput)) {
    return null;
  }

  const password = passwordInput.value || '';
  if (password.length < 4) {
    return null;
  }

  const usernameInput = findUsernameInput(passwordInput);
  const username = (usernameInput?.value || '').trim();
  if (!username) {
    return null;
  }

  return {
    username,
    password,
    url: window.location.href,
  };
}

let lastCaptureSignature = '';
let lastCaptureAt = 0;

function notifyCredentialCaptured(credentials) {
  const signature = `${credentials.url}|${credentials.username}|${credentials.password}`;
  const now = Date.now();
  if (signature === lastCaptureSignature && now - lastCaptureAt < 3000) {
    return;
  }

  lastCaptureSignature = signature;
  lastCaptureAt = now;

  chrome.runtime.sendMessage({
    type: 'CAPTURE_CREDENTIAL',
    username: credentials.username,
    password: credentials.password,
    url: credentials.url,
  }).catch(() => {});
}

function captureFromContext(context) {
  const credentials = captureCredentialsFromForm(context || document);
  if (credentials) notifyCredentialCaptured(credentials);
}

function isButtonLike(element) {
  const control = element.closest('button, input[type="submit"], input[type="button"], [role="button"], a');
  if (!control) return false;

  const text = `${control.textContent || ''} ${control.getAttribute('aria-label') || ''} ${control.getAttribute('name') || ''} ${control.getAttribute('id') || ''}`.toLowerCase();
  if (control.matches('button, input[type="submit"], input[type="button"], [role="button"]')) {
    return true;
  }

  return text.includes('login')
    || text.includes('log in')
    || text.includes('sign in')
    || text.includes('submit')
    || text.includes('continue');
}

document.addEventListener('submit', (event) => {
  captureFromContext(event.target);
}, true);

document.addEventListener('pointerdown', (event) => {
  const target = event.target;
  if (!(target instanceof HTMLElement)) return;
  if (!isButtonLike(target)) return;

  const form = target.closest('form');
  captureFromContext(form || document);
}, true);

document.addEventListener('keydown', (event) => {
  if (event.key !== 'Enter') return;
  const target = event.target;
  if (!(target instanceof HTMLInputElement)) return;

  const form = target.closest('form');
  captureFromContext(form || document);
}, true);

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (message?.type === 'DETECT_FORM') {
    sendResponse({ success: true, data: detectLoginForm() });
    return;
  }

  if (message?.type === 'FILL_CREDENTIALS') {
    const result = fillCredentials({
      username: String(message.username || ''),
      password: String(message.password || ''),
    });
    sendResponse(result);
    return;
  }

  sendResponse({ success: false, error: 'Unknown content script message.' });
});
