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
./vendor/bin/phpunit --filter FormContractTest   # 21 tests, under a second
./vendor/bin/pint                         # --test to check without writing
npm run lint                              # eslint; prettier runs through it
npm run lint:fix
npm run build                             # or `npm run dev`
```

The app is served on **port 9007** in this environment.

**Which of those a change needs.** Do not run the full suite: it re-runs
unchanged PHP against unchanged expectations, so it costs a minute and says
nothing the related tests did not. What decides which tests are related is
not server versus client — `FormContractTest` reads `resources/js`, and
`CsrfExpiryTest` reads a `.vue` file, so a template edit can fail a PHP
test.

- **PHP, or a route, DTO, query, migration or seeder:** the related tests,
  in one `--filter`, and pint. Find them by grepping `tests/` for what the
  change touched — the class, the method, the enum case, the route path,
  the table or column — and add the test named after the file. A DTO also
  takes `FormContractTest`; a seeder `DevSeederTest` or `BudgetSeederTest`;
  `bootstrap/app.php` or `config/` `DatabaseSafetyTest` and
  `CsrfExpiryTest`. A grep for a model or enum that names many files means
  the change is wide, and every one of them runs.
- **A form template that gains or loses a `v-model`, an error binding, a
  `v-if`, a `:disable` or any `form.` reference:** `FormContractTest` alone.
  It counts every `form.` reference in a template and requires each to be a
  binding it can parse, so an assignment inside a `@click` fails it. It has
  done so here, on an edit that touched no PHP at all.
- **Anything else in a `.vue` file** — a class, an offset, a handler that
  writes a plain ref: `npm run lint` and `npm run build`, and then look at
  it. There is no JS test runner in this project, so the browser is the only
  check on whether a menu opens, where a list lands, or whether the caret
  stays in a field. That is not a formality: the description field's hint
  list was wrong five times in an afternoon, in ways every check here
  passed, and each was caught by a person looking at the screen. See
  [Looking at it](#looking-at-it) for doing that without waiting on one.

## Looking at it

The desktop browser tool is often not connected to a session, and a change
that needs the screen does not wait for one to attach. Playwright works here
and needs no network: `playwright-core@1.63.0` matches the `chromium-1243`
build already in `~/.cache/ms-playwright`, so nothing downloads. Install it
**outside the repo** — the project has no Playwright dependency and adding
one would dirty `package.json` — and note the npm cache already holds the
tarball:

```bash
mkdir -p /tmp/opencode/pw && cd /tmp/opencode/pw
npm init -y && npm i playwright-core@1.63.0
node your-script.mjs
```

Every page but sign-in needs a signed-in user, and there is no password to
type. Enrol the browser the way a phone is enrolled: run `php artisan
login:enrol`, take the `/enrol/…` path from the link it prints, open it on
port 9007 and click the button. The context is then signed in and stays so.

Read the DOM rather than only screenshotting it. Asserting on the text a
badge actually rendered names the row that broke, where a picture says only
that something did — and both `console` and `pageerror` listeners pay for
themselves, because a page that throws while rendering a cell still
screenshot-plausibly.

**Wait for the text, never for a duration.** `q-input` debounces by 300ms and
Inertia reloads after that, so a `waitForTimeout` reads whichever rows the
*previous* filter left behind and confidently reports on the wrong thing.
`page.waitForFunction` on the expected text is the only wait to trust.

**A runtime `TypeError` on a page that lint and build both passed is the
ordinary case here, not the surprising one**, and the reason is worth
carrying: `vite.config.js` sets no `vueTemplate`, so `unplugin-auto-import`
rewrites script blocks only, and an auto-imported composable called from a
template is undefined at runtime. No static check can see it. That is the
whole argument for this section.

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

**The look is documented in `docs/design.md`**: the amber that every line, band and hover
is, the coloured cards and the two variables they read, and the overrides of Quasar that
look wrong and are not. A grey line or a slate hover is the old theme left behind. The old
design is the `pre-redesign` branch.

## A page's dropdowns and toggles live in localStorage, not the URL

A control that picks what the page is *about* — a brokerage, a currency, the Totals
switch, the Forecast's what-if — is the browser's to remember (`useStorage`, or
`useLocalStorage` on the Forecast), and choosing one must not add a query string. The
server cannot read storage, so it sends every view the control can ask for and the client
picks: `byBroker[id]` on Dividends, `views.all` / `views.USD` on the Forecast, and
`views.due.all` / `views.charged.USD` on Cash flow (card spending by due date or charge date,
then currency). The cost is a larger response, so build each view from one shared object
(`Forecast::for()` memoises, `CashFlow::both()` reads the rows once).

- **Keep the choice if the option is gone.** A stored currency or brokerage the data no
  longer has falls back to "all" *without overwriting storage* — a stale value is harmless
  until the option comes back.
- **The URL is for what the server needs to compute.** The Forecast's horizon (`?months=`)
  changes how many days are projected, so it stays a query. Filters on a list
  (Transactions) stay in the URL too, because a link to a filtered list is the point.
- **A preference the server must act on goes in a cookie, not storage.** Transactions'
  "Hide transfers" and "Totals" change what the first request queries, and from storage the
  page loaded, then asked again — a second wasted. So they are cookies
  (`TransactionController::HIDE_TRANSFERS_COOKIE`, `TOTALS_COOKIE`), written by the page
  with `writeCookie()`, read by the controller, and listed in `encryptCookies(except:)` in
  `bootstrap/app.php` — an encrypted cookie the browser wrote reads as nothing, silently.
  Totals closed means the totals query does not run at all. Hide transfers is a preference,
  not a filter: Reset leaves it, `active` does not count it, the echoed `params.filter`
  leaves out the cookie's addition, and a link that names it wins for that visit only.
  Tests set them with `withUnencryptedCookie()`; the totals tests need it to get totals.
  Home's choice between the phone's simple page and the full one
  (`HomeController::VIEW_COOKIE`) is the same kind: the page is picked on the first request,
  from the cookie or, without one, from "Mobi" in the user agent.
- **Detail too big to ship is fetched when asked, from a small JSON endpoint built on the
  same query as the figure.** Cash flow's quick view (`GET cash-flow/transactions`, opened
  by the eye on a breakdown tile) lists the rows behind one category in one month. It uses
  `CashFlow::spendingRows()` and `spendingFigure()`, which `facts()`' rules mirror, so the
  list adds up to the tile; change what counts as spending in one place and the test
  `test_a_tiles_transactions_are_the_rows_it_added_up` says if the other drifted.
- **The what-if is remembered too** (`forecast.spendingChange`, `.noIncome`, `.irregular`),
  so the page opens as it was left. Reset puts all three back.

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
- **A card states its own amount, so card money never needs a rate.** A charge in
  another currency puts the card's own figure in `card_amount`, and every card here
  is HKD, so that figure is the HKD amount — read a card row's money as
  `card_amount ?? amount` and it is already base, with nothing to convert. A foreign
  *cash* row is the opposite case and has no figure at all: leave it out and name its
  currency rather than counting it at one-for-one or inventing a rate for it, since
  either puts a yen row into an HKD total as that many dollars and the total is then
  wrong with nothing to show for it (`TransactionController::baseFigure()`).
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

Every test starts signed in as an unsaved user, set up in `Tests\TestCase`. A
test about signing in sets `protected bool $signedIn = false;`. The test client
keeps the guard between requests, which a real browser does not, so a test
that needs a fresh request calls `Auth::forgetGuards()` first.

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
