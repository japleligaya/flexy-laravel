<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Payroll Pro V9 release

Implemented directly in the existing workspace on 2026-11-22 (Asia/Manila). This folder has no Git metadata; original application files and a full private SQL backup were preserved before application edits and migration. Existing Flexy assets, employee/attendance CRUD, V8 rate history and retained draft cancellation remain in use.

### Delivered behavior

- Effective-dated salary history is authoritative. Legacy monthly salary is display/compatibility data. The conservative backfill uses only a recorded hire date, skips any existing rate history and leaves employees without dates for review. New employees enter the initial salary once with a confirmed effective date. Changes append salary versions.
- Company policies are versioned with confirmed first-of-month effective dates: monthly/semi-monthly cutoffs, calendar-day or scheduled-workday proration, attendance requirements, paid-leave approval, approved OT minutes, work schedule, contribution allocation and approval separation. Missing policy blocks review/finalization. Policy changes within a cutoff require review.
- Batch generation includes each eligible overlapping employee once, including daily and monthly earners. Existing, manual and reviewed entries survive generation. Missing rates/attendance, unknown classifications/policy, invalid cutoffs and negative net are visible blocking exceptions. Employees hired after cutoff are listed as excluded.
- Exact rational arithmetic and integer centavos produce dated, source-linked lines. Recalculation shows exact before/after values and requires confirmation of a source fingerprint. Reviewed entries must be explicitly reopened; stale source data prevents approval. Recurring adjustments apply once per overlapping cutoff, as stated in the form; one-off adjustments outside employment are excluded with warnings.
- Server-enforced Super Admin, HR, Payroll Officer, Approver and Employee roles cover both legacy and new routes. Existing logins were backfilled to Super Admin; new logins default to Employee. Super Admin manages role/payslip links. Employee logins land on their own finalized payslips.
- Workflow is Draft -> Review -> Approved -> Finalized -> Paid, with rejection back to Draft, actor/timestamp records, complete-employee checks, review acknowledgements and confirmed totals. Approval locks calculation records; finalization stores immutable money, employee, company, policy and source snapshots. Payment is a separate, explicit reconciliation record. Demo payroll cannot record actual payment.
- Draft cancellation retains entries and releases cutoff dates. Locked payroll can receive an approved full negative reversal ledger and a linked replacement draft. Original snapshots and payment records remain unchanged. Reversal approval does not transfer or recover funds; recovery/replacement disbursement requires separate reconciliation.
- Authenticated PDF payslips use pinned **dompdf/dompdf 3.1.6**, with remote/PHP/JavaScript execution disabled and private response headers. Repeated daily base lines are grouped for printing without changing snapshot totals. CSV retains itemized JSON and exceptions, protects spreadsheet formula cells and agrees with locked payslip totals. A generated sample was rendered with the native Windows PDF renderer and visually checked.
- Payroll detail is a Flexy batch review screen with readiness counters/filters, fix links, sticky actions, expandable formulas, statutory context, confirmations and secondary manual exceptions. Ready employees can be acknowledged together after reviewing a warning/totals summary, leaving only exceptions for individual resolution. Existing motion/reduced-motion behavior remains. A Flexy null-element error and assignment/comparison mistakes in its sidebar script were fixed; responsive browser QA opens the sidebar before navigation.
- Salary, attendance, adjustments, generation/recalculation, review, approvals, finalization, cancellations, role changes, reversals and payments are audited with actor and before/after values. Credentials and session secrets are not included in audit events.

### Official rules and explicit gates

These are bounded implementations, not a claim that all Philippine payroll classifications are automated. The source registry is in `config/payroll.php` and visible to Super Admin at `/payroll-settings`. Verification coverage ends **2026-11-22**; that is an implementation verification date, not a legal expiry. Cutoffs after coverage require verification or authorized manual treatment.

| Component | Official source and effective date | Implemented scope / gate |
| --- | --- | --- |
| SSS | [SSS contribution schedules](https://www.sss.gov.ph/sss-contribution-table/), [Circular 2024-006 employer/employee table](https://www.sss.gov.ph/wp-content/uploads/2024/12/Cir-2024-006-Employers-scaled.jpg), effective January 2025 | Published MSC brackets, employee/employer regular/MPF portions and employer-only EC. Boundary fixtures match published rows. Standard verified private employment only; variable compensation and unsupported classifications require review. |
| PhilHealth | [PA2025-0002](https://www.philhealth.gov.ph/advisories/2025/PA2025-0002.pdf), effective January 2025; [PA2026-0042](https://www.philhealth.gov.ph/advisories/2026/PA2026-0042.pdf) confirms continuing premium guidance | Standard direct-contributor basic-salary floor/ceiling and employee/employer sharing, with published examples tested. Special statuses such as solo-parent subsidy are not inferred; leave/classification changes require manual verification. |
| Pag-IBIG | [Regular Savings](https://pagibigfund.gov.ph/Membership_RegularSavings.html), [official circular listing](https://www.pagibigfund.gov.ph/about_publication_circulars_memberservices.html), Circular 460 listed effective February 2024 | **Needs verification.** The original content was access-blocked; the government mirror could not be fetched. No invented percentage/table is enabled. Strict mode blocks; manual mode requires authorized amounts, source URL and reason. |
| BIR | [RR11-2018 Annex E, 2023+ withholding table](https://bir-cdn.bir.gov.ph/local/pdf/Annex%20E%20RR%2011-2018.pdf), effective January 2023 onwards | Monthly/semi-monthly regular compensation table fixtures. Exempt-benefit caps, de minimis rules, supplementary-pay methods, previous-employer income, year-end/final-pay annualization and special classifications remain gated for authorized verification. December annualization is not claimed automatic. |
| Labor premiums / leave | [DOLE Workers' Statutory Monetary Benefits Handbook 2024](https://nwpc.dole.gov.ph/wp-content/uploads/2024/11/Workers-Statutory-Monetary-Benefits-Handbook-2024-Edition.pdf) | Explicitly approved ordinary-day OT uses the supported ordinary-day calculation and configured hourly divisor. Holiday/rest-day coverage, night differential, 13th-month amounts and statutory leave entitlement require documented coverage/source review. They may be recorded as dated manual adjustments; no hours or legal entitlement is inferred. |

SSS/PhilHealth contribution allocation reconciles employee/employer amounts and EC against earlier finalized cutoffs in the same month. Read-only month/year summaries show prior finalized earning-period snapshots, exclude demo and approved reversals, and retain unknown legacy/taxable amounts as **null**, not assumed zero. They do not substitute for tax-year cash/payment reconciliation or annualization.

Real payroll must use an explicitly confirmed company policy and employee classification. **Strict mode cannot finalize while Pag-IBIG or another required component remains unverified.** Authorized manual mode records exceptions with sources/reasons; demo mode visibly excludes statutory components and disables actual payments. These gates are deliberate and are not unfinished rules represented as compliant deductions. Old finalized records without verified snapshots remain historical, but new PDF/payment/reversal actions require verified historical reconciliation; their old source values are not fabricated.

### Routes, views and components

- Existing `/employees`, `/attendance`, `/payroll-periods` and `/reports` routes remain protected. New primary actions: `POST /payroll-periods/{period}/generate` and `/review-ready`.
- Review/reopen, statutory exceptions and GET/POST recalculation live under `/payroll-entries/{entry}/...`. Batch submit/approve/reject use `/payroll-periods/{period}/workflow/{action}`; existing `/finalize` now requires approved complete payroll.
- Settings/roles: `/payroll-settings` and `/payroll-settings/users/{user}`. Dated company/employee adjustments: `POST /payroll-adjustments`. Explicit leave/hours approval: `POST /attendance/{record}/approve`.
- Payslips: `/my-payslips`, `/payslips/{entry}/pdf`. Payments: `/payroll-periods/{period}/payments`. Reversal requests: `/payroll-periods/{period}/correction`; decisions/replacements: `/payroll-corrections/{correction}/decision` and `/replacement`.
- New views: `payroll-settings/index`, `payroll-periods/recalculate`, `payroll-periods/adjustment-form`, `payslips/index`, `payslips/pdf`. The existing batch detail, employee and attendance views were adapted in place.
- Computation/workflow live in `app/Services/Payroll`: `PayrollEngine`, `PayrollWorkflow`, `StatutoryRules`, `PayrollHistory`, `ManualCalculation`, `LegacySalaryBackfill`, `PayslipDocument`, `Access`, `Audit`. Route middleware and audit/model guards enforce permissions and history retention.

### Windows installation and verification

The additive migration `2026_11_23_000001_add_payroll_engine_workflow` has already been applied to this workspace's `payroll_pro` database. Re-running migration reported nothing pending. Verification preserved all original users (1), employees (1), attendance records (1), payroll periods (2) and payroll entries (0); salary history gained one conservative hire-date backfill. No automated test created live payroll entries or payments.

From `C:\Users\ligay\Projects\flexy-laravel`:

```powershell
composer install --no-interaction
php artisan migrate --force --no-interaction
php artisan payroll:backfill-legacy-rates
php artisan route:list --except-vendor
php artisan view:cache
php artisan test
node qa/run-isolated.cjs
```

For a read-only local browser/server start, run `php artisan serve`. Sign in with the existing administrator, open **Payroll settings**, confirm the actual company policy/effective date, and review the backfilled salary history. Never use `migrate:fresh`, `db:wipe` or test financial flows against live MySQL.

`phpunit.xml` forces SQLite `:memory:`. `Tests/TestCase.php` refuses any other database before refresh traits run. The browser runner creates a uniquely named `storage/app/private/payroll-qa-*.sqlite`, creates ephemeral credentials, starts a private local test server and runs both viewport projects. It never changes `.env`. Its fixture command refuses production/MySQL and nonempty databases. For a focused browser run: `node qa/run-isolated.cjs payroll-v9.spec.cjs`.

The acceptance fixture uses a PHP 200,000 monthly rate, hire date 2026-11-22 and November 1-30 cutoff: explicit calendar-day policy produces PHP 60,000 base (9/30), with no salary retyping. Missing policy produces a blocking review exception. Production policy was intentionally not guessed or seeded with demo settings.

### Verification results and limits

- Laravel: **56 passing tests (382 assertions), zero failed**; coverage includes existing CRUD/cancellation, monthly/daily/effective boundaries, hires/leavers, paid leave, OT approval, policy dates, missing inputs, negative net, reviewed/locked edits, source fingerprints, completeness, separate actors/rejections, snapshots, roles/own payslips, manual statutory authority, payments, reversal/replacement, conservative backfill, MTD/YTD unknowns and published statutory boundary fixtures.
- Playwright: **30 passed, zero failed**. Final desktop/mobile results are recorded in `storage/app/private/phase6-playwright.txt` and `qa/playwright-report/index.html`. Public, theme, motion, authenticated navigation and payroll batch/PDF/CSV flows run in isolated SQLite. The runner also sends duplicate generation requests concurrently and checks one entry per employee.
- `route:list` lists **51 routes**; `view:cache` succeeds; all compiled Blade templates passed PHP syntax checks. `composer validate --no-check-publish` passed with the expected exact-version warning for the requested pinned PDF dependency.
- Full multi-worker MySQL contention/load tests were **not run** against the live database. Application transactions, a cutoff-creation mutex and database entry uniqueness guard writes, but isolated SQLite/browser checks are not a MySQL load-test certification.
- Local `php artisan db:show --counts` is blocked by the XAMPP/MariaDB `performance_schema.session_status` mismatch. Direct schema/backup access, migration and record-preservation checks succeeded. This does not block application tests.
- The installed Composer does not expose `composer audit`; that optional command could not run. No blanket dependency update was performed. Pag-IBIG source access and the statutory gates above are the remaining verification limits, not passing compliance claims.

### Backup and rollback

Private backup: `storage/app/private/phase6-backup/`. It contains original application source copies under `source/`, `baseline.json`, and `database-before.sql`. Keep this directory private: the SQL contains sensitive employee/authentication data. The dependency manifests were captured after the targeted PDF package installation; they remain compatible with the earlier application, but are not a byte-for-byte dependency rollback.

The financial migration is intentionally forward-only: `down()` refuses to delete salary, audit, payment or correction history. **Do not run migrate:rollback as a financial-data rollback.** If rollback is necessary, stop writes (`php artisan down`), take a new backup of the upgraded database, and restore the pre-release SQL into a **separate empty database**. For example, using the real local MySQL account and password prompt:

```powershell
& 'C:\xampp\mysql\bin\mysql.exe' -u YOUR_USER -p -e 'CREATE DATABASE payroll_pro_v8_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
cmd /c '"C:\xampp\mysql\bin\mysql.exe" -u YOUR_USER -p payroll_pro_v8_restore < "C:\Users\ligay\Projects\flexy-laravel\storage\app\private\phase6-backup\database-before.sql"'
```

Verify the restored records independently, restore the original source copies (remove only added application files identified in the delivery manifest), point a private `.env` copy to the restored database, then run `php artisan config:clear`, `php artisan view:clear`, and `php artisan up`. Retain the upgraded database and its new financial history for reconciliation. Do not overwrite either database or expose backups in `public/`.

The file/hash manifest is `delivery/changed-files.json`. The consolidated delivery ZIP includes only changed/new application files with original relative paths. It excludes `.env`, SQL/private backups, credentials, `vendor`, `node_modules`, generated test data/reports and PowerShell scripts.
