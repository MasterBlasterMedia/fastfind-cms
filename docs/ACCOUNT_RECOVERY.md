# Account password recovery

Select **Forgot your password?** on `/studio`, enter the account email, and follow the emailed link to choose a new password. Only an existing active account can reset its password. Owners still provision accounts; recovery does not register or reactivate users.

## Configure delivery

Set `APP_URL` to the trusted address people use to reach FastFind, including the correct port in development. Production must use the site's HTTPS URL. Reset links are built from this configuration, never the incoming request's Host header. The configured URL must use HTTP or HTTPS and cannot contain credentials, a query, or a fragment.

The default `.env.example` uses `MAIL_MAILER=log`. This writes the complete notification, including its usable reset link, to the configured Laravel log channel; with the default logging settings this is `storage/logs/laravel.log`. It does **not** send an email to an inbox. Use this only for local development, keep logs private, and do not share reset links in support tickets or commit them to Git.

For inbox delivery, configure the SMTP transport with your provider's credentials and sender identity. For example, replace all placeholder values below with the provider's settings:

```dotenv
APP_URL=https://cms.example.com
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=provider-username
MAIL_PASSWORD="provider-secret"
MAIL_FROM_ADDRESS=accounts@example.com
MAIL_FROM_NAME="FastFind"
```

Use the scheme and port required by your provider; implicit TLS commonly uses `MAIL_SCHEME=smtps` and port `465`. `MAIL_URL`, if configured, supplies transport connection settings, so remove stale overrides when changing providers. Keep credentials in deployment secrets or the private `.env` file. Apply changed configuration with `php artisan config:clear` locally or rebuild the production configuration cache with `php artisan config:cache`, and restart long-running processes.

Delivery is synchronous; no queue worker is required for this flow. Test real delivery and the complete reset/sign-in flow with a designated account in staging before enabling recovery for users. Automated tests exercise Laravel notifications and the local log mailer; they do not establish SMTP provider delivery, spam placement, or production email reliability.

## Link and password behavior

- Laravel's `users` password broker stores a hashed token in `password_reset_tokens`. Links expire after 60 minutes and are consumed by a successful reset. A newly issued link invalidates the preceding link for the same email.
- The broker permits a new token only after 60 seconds. Requests inside that window still receive the generic response and do not send another link.
- New passwords must contain at least 12 characters and at most 72 UTF-8 bytes, with matching confirmation. The byte bound prevents bcrypt truncation; non-ASCII characters can use several bytes each. An invalid password does not consume the link.
- A successful reset rotates the remember token and records `account.password_reset` in the audit trail. Existing sessions for that account lose authenticated access on their next request. Persisted sessions without a password marker must sign in again.
- Resetting does not sign the user in automatically. The browser completing the reset is signed out, receives a fresh CSRF token, and must sign in with the new password. Other accounts' sessions in other browsers remain valid.
- Successful completion removes the token and email from the current browser URL. Studio pages and password endpoints use `no-referrer`, private `no-store`, and `noindex, nofollow` response headers. Treat a reset URL as a credential while it remains valid; configure reverse proxies and access logs to avoid retaining its query string.

## HTTP contracts

These same-origin session endpoints require CSRF protection, including for guests. Load the application first and send `Accept: application/json` and the current `X-CSRF-TOKEN` value. The bundled client updates that token after a successful reset.

| Endpoint | Input | Result |
|---|---|---|
| POST `/api/v1/password/forgot` | `{email}` | `200` with a generic `message` for an active, inactive, unknown or broker-throttled address; delivery failures also return this response |
| POST `/api/v1/password/reset` | `{email,token,password,password_confirmation}` | `200` with `message` and `csrf_token`; invalid, expired, reused or inactive-account links return a generic `422` email error |

Malformed input returns Laravel's `422` validation error structure. Recovery requests are limited to **6 per minute per IP** and **5 per hour per normalized email key**. Reset attempts are limited to **10 per minute per IP**. Exceeding a request limiter returns `429`; this is separate from the broker's silent 60-second resend throttle. Request limits use the configured cache, so production proxy and cache configuration must reflect the actual deployment.

## Operations and limits

A generic success message does not prove delivery. Check provider status and the private application log when mail is missing. The recovery controller logs a fixed delivery-failure message and exception class without the address, token or provider exception text. Repair configuration before trying again, and account for the rate limits. Uniform response text reduces account disclosure, but synchronous delivery is not a guarantee of identical response timing.

Expired tokens cannot be used even while their database rows remain. Remove expired rows periodically with:

```sh
php artisan auth:clear-resets users
```

For an account whose mailbox is inaccessible, arrange a verified administrative recovery process; this flow cannot establish ownership of a different mailbox. Email verification, MFA, SSO and self-service account registration remain unimplemented. Database concurrency and mail transport behavior need validation on the actual production services. See [SECURITY](SECURITY.md), [KNOWN_LIMITATIONS](KNOWN_LIMITATIONS.md), and the [test execution report](TEST_EXECUTION_REPORT.md).
