// @ts-check

/**
 * Reads mail the e2e stack sent, from Mailpit's API on the host
 * (docker/docker-compose.yml maps it to :8027). No product code is involved:
 * the app sends real mail to Mailpit exactly as it would to a provider.
 * Mailpit is shared with the dev stack, so nothing here deletes mail: specs
 * filter by recipient and by `since` (take the time before the action).
 */
const MAILPIT = 'http://localhost:8027/api/v1';

async function json(path, init) {
    const res = await fetch(`${MAILPIT}${path}`, init);
    if (!res.ok) {
        throw new Error(`Mailpit ${path} → HTTP ${res.status}`);
    }
    return res.json();
}

/** Newest message to `recipient` received after `since` (a Date), waiting up to `timeout` ms. */
export async function latestMessage(recipient, { since = new Date(0), timeout = 10_000 } = {}) {
    const deadline = Date.now() + timeout;
    for (;;) {
        const found = await json(`/search?query=${encodeURIComponent(`to:"${recipient}"`)}&limit=5`);
        const message = (found.messages ?? []).find((m) => new Date(m.Created) > since);
        if (message) {
            return json(`/message/${message.ID}`);
        }
        if (Date.now() > deadline) {
            throw new Error(`No mail to ${recipient} after ${since.toISOString()} within ${timeout} ms`);
        }
        await new Promise((r) => setTimeout(r, 250));
    }
}

/** The 6-digit code from the newest OTP mail to `recipient` (OtpCodeNotification). */
export async function latestOtp(recipient, options = {}) {
    const message = await latestMessage(recipient, options);
    const code = /\b(\d{6})\b/.exec(message.Text ?? '')?.[1];
    if (!code) {
        throw new Error(`Newest mail to ${recipient} ("${message.Subject}") has no 6-digit code`);
    }
    return code;
}
