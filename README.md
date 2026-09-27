<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Local setup

Two databases, and they must be two. `RefreshDatabase` runs `migrate:fresh`, which
**drops every table** before the suite starts, so a test run against the
development database destroys the seeded data. `.env` names the development one;
`phpunit.xml` names the test one.

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
./vendor/bin/phpunit        # 546 tests; uses budget_v2_test, never the dev one
./vendor/bin/pint           # --test to check without writing
npm run lint
```

`DatabaseSafetyTest` asserts the suite resolved to a database other than the one
`.env` names, which is the check that would have caught the two having been the
same.

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

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains over 2000 video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the Laravel [Patreon page](https://patreon.com/taylorotwell).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Cubet Techno Labs](https://cubettech.com)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[Many](https://www.many.co.uk)**
- **[Webdock, Fast VPS Hosting](https://www.webdock.io/en)**
- **[DevSquad](https://devsquad.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[OP.GG](https://op.gg)**
- **[WebReinvent](https://webreinvent.com/?utm_source=laravel&utm_medium=github&utm_campaign=patreon-sponsors)**
- **[Lendio](https://lendio.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
