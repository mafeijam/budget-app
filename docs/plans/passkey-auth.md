# Plan: sign in with a passkey (phone as the key)

Status: **parked**, to revisit. Not started. Planned for when the app is hosted at
`ledger.jamwong.me`; until then it runs on a private network and nothing is wrong.

## Why

The app has no authentication at all: `route:list` has no auth route, the `users` table
is empty, and every page loads for anyone who can reach the port. That is fine on
localhost and not fine on the internet, where it exposes every account balance.

A passkey (WebAuthn) makes the phone the key. The private key stays on the phone and
unlocks with its fingerprint or face. A desktop browser offers "use a phone", shows a QR
code, and the phone approves over Bluetooth; the phone's own browser just prompts for
biometrics. There is no password to leak or reuse.

## Proposal

No custom guard. The stock session guard stays: a controller verifies the WebAuthn
assertion and then calls `Auth::login($user)`. A custom guard would only be needed to
bypass sessions, which nothing here wants.

### 1. One user

The `users` table exists. A `php artisan ledger:create-user` command makes the single
user. No registration page: the app has one owner, and a public sign-up route is a hole.

### 2. Passkeys

A `passkeys` table: user id, credential id (unique), public key, sign count, a name
("Pixel 9"), last used. Register and login endpoints issue a challenge and verify the
signed response.

Check whether Laravel 13 ships first-party passkey support before choosing a package;
if not, `spatie/laravel-passkeys` (or `web-auth/webauthn-lib` underneath). Whichever it
is, the sign count is checked and stored, since a count that goes backwards means a
cloned credential.

**RP ID is `jamwong.me`, not `ledger.jamwong.me`.** WebAuthn allows a parent domain, so
the passkeys survive moving the app to another subdomain. The cost is that the
credential is valid for any `*.jamwong.me` site, which is acceptable for a personal
domain. RP ID and origin live in `.env`, never hardcoded.

A passkey registered against `localhost` does not work on the hosted domain and the
reverse. Register once per environment. The test database stays separate from
development as AGENTS.md describes, and nothing here changes that.

### 3. Login page

One Inertia page under `resources/js/pages/`, with a "Sign in with passkey" button and no
password field. It is the only route outside the `auth` middleware.

### 4. Protect every route

Wrap the whole route group in `auth`. This is the largest change in the plan and the one
that fails silently: a route added later outside the group is simply public. A feature
test walks `Route::getRoutes()` as a guest and requires every route except login (and the
passkey challenge endpoints) to redirect.

The cookie preferences in AGENTS.md (`HIDE_TRANSFERS_COOKIE`, `TOTALS_COOKIE`) are read
by the controller and unaffected. Existing feature tests will need `actingAs()`; a base
helper beats touching each test.

### 5. Enrolment bootstrap

The first passkey needs a way in without already being signed in.
`php artisan ledger:enrol` prints a short-lived signed URL; opening it on the phone
registers that device. The same command enrols a replacement phone. The URL expires in
minutes and is single use.

### 6. Recovery

Losing the only phone would lock the owner out of their own finances.

- Register at least two devices (phone plus a laptop or security key).
- Issue a one-time recovery code at enrolment, stored hashed, shown once. It can only
  start a new enrolment, never sign in directly.
- Server access remains the break-glass: `ledger:enrol` from the shell.

### 7. Hosting settings

- HTTPS is required for WebAuthn off localhost (Caddy, Cloudflare, or Tailscale Funnel).
- `APP_URL=https://ledger.jamwong.me`, `SESSION_SECURE_COOKIE=true`,
  `SESSION_SAME_SITE=lax`, trusted proxies set if behind a tunnel.
- Throttle the challenge and login endpoints.
- A long session lifetime, so the phone is not asked constantly.
- `CsrfExpiryTest` reads a `.vue` file; check the login page does not fight the existing
  CSRF-expiry handling, since the first thing a logged-out session hits is a 419.

## Verification

- Feature tests: guest redirect on every route, enrolment URL expiry and single use,
  challenge replay refused, sign count regression refused.
- The real WebAuthn ceremony cannot run headless; Playwright's virtual authenticator
  (CDP `WebAuthn.addVirtualAuthenticator`) can drive register-then-login locally, with
  the final phone round-trip checked by hand once hosted.
- Per AGENTS.md: PHP changes need the full suite and pint; the login page needs lint,
  build and a look at it.

## Open questions

- Which HTTPS front (Caddy, Cloudflare, Tailscale Funnel) decides the proxy settings.
- Whether to keep a password-less fallback at all, or accept recovery code plus shell
  access as the only way back.
