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
```

`DevTransactionSeeder` owns the transactions on the accounts it names: it deletes
them before writing, so re-running restores the intended state rather than
doubling every figure. That also means it undoes anything you settled by hand.

### Tests and checks

```bash
./vendor/bin/phpunit        # uses budget_v2_test, never the dev one
./vendor/bin/pint           # --test to check without writing
npm run lint
```

`DatabaseSafetyTest` asserts the suite resolved to a database other than the one
`.env` names, which is the check that would have caught the two having been the
same.
