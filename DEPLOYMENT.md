# Vercel staging

Use Node.js 22, the Other framework preset, and the repository root. The community PHP runtime installs Composer dependencies from the lockfile and checks its PHP extensions before building the function. The Vercel build publishes the Vite output and the existing public assets. Laravel uses HTTPS from Vercel's proxy and temporary disk space for compiled views and PDF generation.

1. Use the dedicated Flexy staging branch in Neon Singapore with synthetic records. Use a direct connection with the schema owner for `php artisan migrate --force` and `php artisan payroll:create-admin`. Give the pooled runtime role table and sequence privileges in the staging schema.
2. For local staging verification, use a separate ignored environment file, a PHP CLI with `pdo_pgsql` and a current libpq, and `DB_SSLROOTCERT` pointing to a trusted CA bundle. Some older Windows libpq builds need the Neon endpoint ID passed as a libpq connection option. The regular local XAMPP/MySQL configuration stays in `.env`.
3. Link the checkout to `flexy-laravel` in Vercel, select Node.js 22, and enable Vercel Authentication with Standard Protection. Set the variables below in Preview. Generate a staging key with `php artisan key:generate --show` and store it in the encrypted environment settings. Leave `ASSET_URL` unset for same-origin assets.
4. Run `vercel deploy` for a preview in Singapore. Check `/up`, `/`, `/login`, static assets, secure cookies, an authenticated dashboard, and a PDF download from a dummy finalized snapshot. Confirm that `/.env`, `/composer.json`, and `/storage/logs/laravel.log` return 404 after passing Deployment Protection.

| Environment variable | Staging setting |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | Stable, staging-only generated key |
| `APP_URL` | Optional HTTPS staging hostname. Defaults to the HTTPS URL derived from Vercel's `VERCEL_URL`. |
| `DB_CONNECTION` | `pgsql` |
| `NEON_DATABASE_URL` | Pooled staging runtime URL. Laravel enforces `verify-full` for this setting. |
| `DB_SSLROOTCERT` | Runtime CA bundle, typically `/etc/pki/tls/certs/ca-bundle.crt` on Vercel |
| `SESSION_DRIVER` | `database` |
| `SESSION_SECURE_COOKIE`, `SESSION_ENCRYPT` | `true` |
| `CACHE_STORE` | `database` |
| `QUEUE_CONNECTION` | `sync` |
| `LOG_CHANNEL`, `LOG_LEVEL` | `stderr`, `error` |

The existing app streams payslip PDFs and uses the database for payroll history and audit records. It has no persistent upload workflow, scheduled job, or application queue worker. A future upload workflow needs private object storage with authenticated downloads.
