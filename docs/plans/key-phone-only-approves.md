# Only an enrolled phone approves a sign-in

Status: **built**.

## The bug

`ApproveController` decided who is a key with `$request->user() !== null`. A computer the
phone approved is signed in too, so it could approve the next device, and that one the
next: one approval handed out the power to approve, forever and to anything. The
`phone_key` cookie that enrolment sets was never consulted; it only worded the sign-out
warning.

A cookie of `1` could not simply be checked instead. It is the same on every key, it
survives `login:revoke`, and a revoked phone that a new key later signs in would read as a
key again with nothing to show for it.

## The fix

Being a key becomes a credential the server issued and can withdraw, separate from being
signed in.

1. **A `phone_keys` table**: `user_id` (cascades), `token_hash` (sha256, unique),
   timestamps. One row per enrolled phone, so enrolling a second phone keeps the first,
   as it did before.
2. **Enrolment issues the key.** `EnrolController::store` calls `PhoneKey::issue($user)`,
   which stores the hash of a random secret and returns the secret, and queues it as the
   `phone_key` cookie forever. The cookie is encrypted (it is not in `encryptCookies`'
   except list) and HTTP-only, so a page cannot read it and a device cannot write it.
   Re-enrolling a phone that already holds a key replaces its row.
3. **`PhoneKey::holds($request)` is the one test of being a key**: signed in, the cookie
   present, and a row with its hash for that user. `ApproveController::show` uses it for
   `isKey`, `update` refuses without it, and the shared `keyPhone` prop uses it too, so a
   stale cookie no longer brings up the key-phone warning. A device without the cookie
   costs no query.
4. **A sign-in by QR never issues a key.** `LoginController::store` writes no `phone_key`
   cookie (already true, and the test still says so).
5. **Withdrawing.** Signing out deletes this phone's row along with the cookie, and
   `login:revoke` deletes every row, so a lost phone's cookie opens nothing even if the
   phone is signed in again later.

## Rollout

The phone enrolled today holds a cookie of `1`, which no row matches, so after deploying it
is signed in but no longer a key. Run `php artisan migrate` and then `php artisan
login:enrol` once and open the link on that phone.

## Tests

- `PhoneApprovalTest`: a computer the key signed in cannot approve, or see the request; a
  signed-in device with a forged or stale cookie cannot either; after `login:revoke`, the
  old cookie is not a key.
- `EnrolPhoneTest`: enrolling issues a cookie that holds the key; signing out withdraws it.
