# Password Manager Autofill Extension

Minimal Chrome Manifest V3 extension for the local password manager.

## Load In Chrome

1. Start the app with `docker compose up -d --build`.
2. Open the web app at `http://localhost:3000` and log in.
3. Open `chrome://extensions`.
4. Enable **Developer mode**.
5. Click **Load unpacked** and choose this `browser-extension` folder.

## Usage

1. Save an entry in the web app with a site URL such as `https://www.youtube.com`.
2. Open a page with a login form.
3. Click the extension icon.
4. Choose an entry and click **Fill**.

The extension uses click-to-fill only. It does not automatically fill forms on page load.

## Save Or Update Passwords

When you manually type credentials into a login form and submit it, the extension keeps that credential only in background memory for a short time.

1. Submit a login form manually.
2. If the extension captures it, the extension icon shows a `SAVE` badge.
3. Click the extension icon on the same domain.
4. If the username is new, click **Save**.
5. If the username already exists and the password changed, click **Update**.

The prompt expires when the background service worker is cleared or after a few minutes. The password is not written to `chrome.storage`.

## Security Notes

- Passwords are fetched only when the user clicks **Fill**.
- Passwords are not written to `chrome.storage`.
- Manually submitted passwords are stored only in extension background memory until the user saves, dismisses, or the prompt expires.
- The account list uses metadata-only `GET /accounts?search=...`.
- Plaintext password access uses `GET /accounts/{id}/password`.
- After a successful fill, the extension calls `POST /accounts/{id}/used`.

If Chrome does not send the local app session cookie from the extension context, add a short-lived extension token flow in the backend before using this outside the local demo setup.
