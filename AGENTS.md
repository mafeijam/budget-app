# AGENTS.md

Personal finance tracker. Laravel 13 + PHP 8.3, Inertia 3 + Vue 3 + Quasar 2,
spatie/laravel-data 4, Brick\Math. No CI, no pre-commit hooks — **nothing runs
automatically, you must run the checks yourself.**

Setup and the two databases are in `README.md`. Read it before touching the DB.

## Commands

```bash
./vendor/bin/phpunit                      # not `pest` — no pest binary is installed
./vendor/bin/phpunit --filter CardStatementTest
./vendor/bin/phpunit --filter 'FooTest|BarTest'
./vendor/bin/pint                         # --test to check without writing
npm run lint                              # eslint; prettier runs through it
npm run lint:fix
npm run build                             # or `npm run dev`
```

The app is served on **port 9007** in this environment.

## Two databases, and this will bite you

`.env` names `budget_v2_testing` (development). `phpunit.xml` names
`budget_v2_test` (tests). `RefreshDatabase` runs **`migrate:fresh`, which DROPS
every table**, so if those ever point at the same database a test run destroys the
development data. It has happened here.

- **`.env.testing` is gitignored and is overridden by `phpunit.xml`.** It still
  says `budget_v2_testing`, which is misleading. Trust `phpunit.xml`.
- `php artisan config:cache` overrides `phpunit.xml`'s `DB_DATABASE`, which would
  aim `migrate:fresh` at real data. `Tests\CreatesApplication` aborts the run when
  that happens — fix with `php artisan optimize:clear`.
- The resolved database name must contain `test`, `testing`, `sqlite` or `:memory:`.
  Two independent guards enforce it: `Tests\CreatesApplication` (aborts the suite)
  and `Database\Seeders\Concerns\GuardsAgainstNonTestDatabase` (aborts a seeder).
  So a name like `budget_v2_phpunit` is read as production and refused.
- `DatabaseSafetyTest` asserts the suite resolved to a database *other than* the
  one `.env` names. Its name-pattern canary cannot catch "both are test databases".

## Seeders

Dev fixtures are `Dev*Seeder` and run with an explicit class, never through
`DatabaseSeeder`:

```bash
DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevCategorySeeder
DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevAccountSeeder
DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevTransactionSeeder
DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevRecurringSeeder
DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevHistorySeeder
DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevTradingSeeder
DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevUsdTradingSeeder
```

Order matters; each throws a message naming what is missing. They refuse a
database name without a test marker.

**`DevTransactionSeeder` owns the transactions on the accounts it names** — it
deletes them before writing, so re-running undoes anything settled by hand.

There are **no factories** except `UserFactory`. Tests build models directly;
`Account::factory()` does not exist.

## Frontend: almost nothing is imported

`vite.config.js` auto-imports all of `vue`, all of `@vueuse/core`, `useQuasar`,
and `router` / `usePage` / `useForm` from `@inertiajs/vue3`, plus **every export of
`resources/js/composables/`** (`useFormEmpty`, `useEdit`, `useSubmit`,
`useWatchTarget`, `useCloneForm`, `usePagination`, `useCalendarDay`,
`useHongKongTime`, …). Adding an explicit import for any of those is wrong.

Components under `resources/js/components` are auto-registered with
`directoryAsNamespace` + `collapseSamePrefixes`, so
`components/Form/FormAccount.vue` is `<FormAccount />`. Quasar components are
global. Inertia pages must live in `resources/js/pages/` (globbed in `app.js`).

`auto-imports.d.ts`, `components.d.ts` and `.eslintrc-auto-import.json` are
generated **and committed** — a build can dirty them.

## Dates: pick the right formatter, or fail silently

- `useCalendarDay()` for a `date` column, `useHongKongTime()` for a `timestamp`
  column. A `date` column is a calendar day; formatting it with a time invents a
  deadline that does not exist.
- Server-side `today()` is `Asia/Hong_Kong` (`config/app.php`). Do not derive a
  server-owned default from a JS `Date` — the two disagree for six hours a day.
- Date inputs are `q-input` + `q-menu` + `q-date` with `mask="YYYY-MM-DD"`. A
  mask on a plain `q-input` breaks (different parser, only a `#` token), and a
  native `type="date"` renders in the browser's locale. Both fail silently.
  `FormContractTest` pins the mask to the calendar.
- `SettleDialog.vue` duplicates that control rather than sharing it, because
  `FormContractTest` reads `FormTransaction.vue`'s text for `v-model="form.date"`.
  Moving that binding into a child component fails the contract.

## Domain rules that are easy to get backwards

- **`amount` is a positive magnitude.** Its sign comes from the account type and
  the transaction type *together* — see `TransactionType::movesBalanceOn()`. Never
  store or expect a negative amount.
- **A balance is a position, not a direction of travel.** Cash is positive when
  holding; a card is negative when owing; a card paid beyond its charges is
  positive. `CardStatement::owed()` is the opposite sign on purpose — it is a
  period's debt.
- **Money is never a float.** BigDecimal server-side, decimal strings over the
  wire. `AMOUNT_SCALE` is 4.
- `pending` rows never count toward a balance
  (`TransactionStatus::countingTowardBalance()`).
- Constraints the property types cannot express go in the DTO's `rules()`. Anything
  needing the account row goes in the constructor as a `ValidationException`, so it
  reaches the form as a field error rather than a 500.
- MySQL cannot index a JSON path: a key you group or filter on would be a column,
  an attribute you only display belongs in the meta bag.
- **The enums are the single source of truth.** Never hand-write a list that
  restates `accountTypes()`, `countsTowardBalance()` or `movesBalanceOn()` —
  `CardStatement` and `AccountBalance` both build their SQL from the enums precisely
  so a second copy cannot drift.

## DTOs (spatie/laravel-data 4)

- A form's starting values are seeded by the **controller**, not the frontend:
  `AccountData::empty(['status' => 'active'])` and
  `TransactionData::empty(['date' => today()->toDateString()])`. Because
  `useWatchTarget` does `form.defaults(row)` when editing, a seed reaches a new
  transaction and stops there — a row keeps its own values. Doing it in a watcher
  instead means distinguishing "opened fresh" from "opened on a row".
- **`Data::collect()` replaces a paginator's collection with DTOs.** Code after that
  call gets DTOs where it expected models, and `type` is the enum on one and the
  column string on the other — which is a 500, and only in the HTTP path, never in a
  direct call. `AccountBalance` accepts both for this reason.
- `attributes()` supplies the names validation messages use, so a rejected field
  reads "The category id field is required" without it.

## Tests

`tests/Unit/FormContractTest.php` keeps the two forms and their DTOs in agreement:
every DTO field needs a control, every control needs a DTO field, error bindings
must name real fields, and a field the server requires must be on screen for every
account type. Adding a DTO property without a control (or the reverse) fails
there. `SERVER_ONLY` is the allowlist for server-owned fields — adding to it is a
deliberate act, not a fix.

Page props: `assertInertia(fn (Assert $page) => $page->where(...))`.

**`CardStatements.vue` and `SettleDialog.vue` have no test coverage at all.** A
template edit there can silently delete a feature and nothing fails — a pending-row
badge was dropped from `CardStatements.vue` this way and only re-reading caught it.
Diff those templates carefully.

## Conventions

- **Comments earn their place or go.** A comment stays when the code looks wrong
  without it — the decision, and the failure that made it one. That failure
  should be the *silent* kind: a wrong balance, a Laravel rule that is accepted
  and never run, a value that reaches a NOT NULL column. Naming the silent
  failure is what makes the comment worth its length.
  - Cut: how the code got here. "This used to…" belongs in the commit body,
    which is written at length on purpose, and a reader has `git log`.
  - Cut: the survey of rejected alternatives. Keep the one a reader would most
    likely "fix" and let it be a clause; the other two were never going to be
    tried.
  - Cut: restating the code, the signature, or a sibling comment three lines
    up. Say a fact in one place and point at it from the others.
  - Match the surrounding comment's voice: sentence case, the decision first,
    prose rather than a list.
- **Commits: one idea each.** Subject is sentence case, no prefix, no trailing
  period, and states the change and often the why. The body is several paragraphs
  arguing the reasoning and recording what a future reader would otherwise
  "fix". `git log` is the reference — the recent history is deliberate and matches.
- PHP 4-space indent (Pint). Vue/JS 2-space, prettier through eslint
  (single quotes, no semicolons, `arrowParens: avoid`, width 100).
