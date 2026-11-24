# Vercel staging

Use Node.js 22, the Other framework preset, and the repository root. The community PHP runtime installs Composer dependencies from the lockfile and checks its PHP extensions before building the function. The Vercel build publishes the Vite output and the existing public assets. Laravel uses HTTPS from Vercel's proxy and temporary disk space for compiled views and PDF generation.

1. Provision an empty managed MySQL 8 staging database, such as DigitalOcean Managed MySQL. Require verified TLS, allow connectivity from Vercel, and give the application account access only to the staging schema. Store dummy payroll records there.
2. Use a secure shell to export the staging database settings listed below. Set `MYSQL_ATTR_SSL_CA` to the provider CA file path for this shell. Confirm the connection points to the empty staging schema, then run `php artisan migrate --force` and `php artisan payroll:create-admin` to create a staging-only login. Use an account with schema privileges for migrations and a separate runtime account with data privileges. Generate one staging application key with `php artisan key:generate --show` and store it directly in Vercel's encrypted environment settings.
3. Import the private GitHub repository into Vercel. Enable Deployment Protection for the staging project. Add the settings below to Preview before deploying. Use a stable HTTPS staging hostname for `APP_URL` and leave `ASSET_URL` unset for same-origin assets.
4. Run `vercel deploy` for a preview. Check `/up`, `/`, `/login`, a CSS asset, secure session cookies, login with the staging account, and a PDF download from a dummy finalized snapshot. Confirm that `/.env`, `/composer.json`, and `/storage/logs/laravel.log` return 404 after passing Deployment Protection.

| Environment variable | Staging setting |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | Stable, staging-only generated key |
| `APP_URL` | Stable HTTPS staging URL |
| `DB_CONNECTION` | `mysql` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Hosted staging database connection |
| `MYSQL_SSL_CA_PEM` | Provider CA certificate contents, including the PEM delimiters |
| `SESSION_DRIVER` | `database` |
| `SESSION_SECURE_COOKIE`, `SESSION_ENCRYPT` | `true` |
| `CACHE_STORE` | `database` |
| `QUEUE_CONNECTION` | `sync` |
| `LOG_CHANNEL`, `LOG_LEVEL` | `stderr`, `error` |

The existing app streams payslip PDFs and uses the database for payroll history and audit records. It has no persistent upload workflow, scheduled job, or application queue worker. A future upload workflow needs private object storage with authenticated downloads.
