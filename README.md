# Budget

A personal finance tracker: cash accounts, credit cards and a brokerage, the
transactions on them, and the card statements they add up to.

- **Home** shows each cash account's balance and every card statement still owed,
  soonest due first, with how many days are left.
- **Transactions** lists every row, filterable by date range, account, type,
  status, currency, category and description. Above it, the card statements panel
  settles a statement, which records a payment on the card and a transfer out of
  the bank it is paid from, or corrects its due date.
- **Accounts** holds each account's balance and terms: a card's statement day, its
  payment term, and the bank it is paid from.
- **Recurring** holds withdrawals, deposits, charges and payments that repeat
  monthly or yearly on a cash account or a card. Each is written as a pending
  transaction on the day it falls due, so it counts toward nothing until it is
  posted. A pause skips what falls due during it rather than catching up.
- **Categories** are what spending is filed under.

A statement that has been paid is fixed. A charge cannot be added to it, moved in
or out, edited or deleted, and the two halves of a settlement stay in agreement.
The only way to reopen one is to delete the payment that settled it.

Built with Laravel 13, Inertia 3, Vue 3 and Quasar 2, with spatie/laravel-data for
the DTOs, spatie/laravel-query-builder for the filters, and Brick\Math for money,
which is never a float. The domain rules that are easy to get backwards, and the
conventions the code follows, are in [AGENTS.md](AGENTS.md).

## Local setup

Two databases, and they must be two. `RefreshDatabase` runs `migrate:fresh`, which
**drops every table** before the suite starts, so a test run against the
development database destroys the seeded data. `.env` names the development one;
`phpunit.xml` names the test one.

Needs PHP 8.3, MySQL, and Node 20.19 or 22.12 and later, which is what Vite 8 requires.

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate

# Both databases have to exist. Only the test one is disposable.
mysql -e "CREATE DATABASE budget_v2_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE DATABASE budget_v2_test     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Two things in `.env` need changing before anything else works, and the first is a
trap:

- `DB_DATABASE` must name **your development** database, and its name must contain
  `test`. `.env.example` ships `budget_v2`, which `GuardsAgainstNonTestDatabase`
  reads as live — so the fixtures below will refuse to run until you change it, with
  a message about a database not looking like a test one. `budget_v2_testing` is
  what this project uses.
- `DB_PASSWORD` ships empty.

The `phpunit.xml` test database name has to contain `test` for the same reason, and
for a second one: a name like `budget_v2_phpunit` reads as production, the guard
aborts, and the seeder tests fail rather than the app misbehaving quietly.

Then:

```bash
php artisan migrate
npm run build             # or `npm run dev` for the watcher
```

### Fixtures

Development fixtures, in this order — each throws a message naming what is missing
if you skip one, and the transaction seeder refuses to run against a database that
does not look like a test one:

```bash
DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevCategorySeeder
DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevAccountSeeder
DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevTransactionSeeder
DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevRecurringSeeder
DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevHistorySeeder
DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevTradingSeeder
DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevUsdTradingSeeder
```

`DevTransactionSeeder` owns the transactions on the accounts it names: it deletes
them before writing, so re-running restores the intended state rather than
doubling every figure. That also means it undoes anything you settled by hand.

### Scheduled jobs

Two commands run from the scheduler, which does nothing unless something calls
`schedule:run` every minute:

```bash
* * * * * cd /path/to/budget-app && php artisan schedule:run >> /dev/null 2>&1
```

- `prices:fetch` at 06:30 Hong Kong time, for the brokerage's closing prices.
- `recurring:record` at 00:05 Hong Kong time, for the recurring transactions due
  that day. A missed run catches up on the next one, and saving a rule records
  whatever is already due, so the page works without cron and only goes stale.

### Tidying the ledger

Two commands pair rows the reports would otherwise misread. Both only report
unless given `--apply`, and a second run finds nothing left to do:

```bash
php artisan transfers:pair            # report; --apply to write, --fetch for rates
php artisan trades:link-cash          # report; --apply to write
```

- `transfers:pair` pairs money that only moved between the cash accounts but
  was entered so the cash flow cannot tell: an `EXCHANGE HKD TO YEN 160,000`
  becomes a deposit on the yen account, made if there is none, and a
  `TRANSFER TO FUTU` whose deposit landed on another day is paired with it. A
  same-day transfer is found without it. It then runs `trades:link-cash`.
- `trades:link-cash` links a trade entered with no cash side to the bank row
  that paid for it, so that row counts as invested rather than spent.

A currency account the first one makes needs its rates, or every total leaves
it out: `--fetch`, or `prices:fetch --history --symbol=JPYHKD=X`.

### Tests and checks

```bash
./vendor/bin/phpunit        # uses budget_v2_test, never the dev one
./vendor/bin/pint           # --test to check without writing
npm run lint
```

`DatabaseSafetyTest` asserts the suite resolved to a database other than the one
`.env` names, which is the check that would have caught the two having been the
same.
