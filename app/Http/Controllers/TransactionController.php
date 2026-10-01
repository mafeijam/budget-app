<?php

namespace App\Http\Controllers;

use App\DTO\TransactionData;
use App\DTO\TransactionMetaData;
use App\DTO\TransactionTemplateData;
use App\Enums\AccountType;
use App\Enums\Currency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Meta;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\TransactionTemplate;
use App\Support\CardStatement;
use App\Support\CashFlow;
use App\Support\Positions;
use App\Support\TradeCash;
use Brick\Math\BigDecimal;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\PaginatedDataCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class TransactionController extends Controller
{
    /** @var array<int, list<array<string, mixed>>> each brokerage's trades, read once per request */
    private array $tradesByBroker = [];

    private const DEFAULT_SORT = 'date';

    private const SORTABLE = ['date', 'type', 'description', 'amount', 'status'];

    private const HINT_YEARS = 2;

    /**
     * What the category filter carries for "no category". It cannot be null, which is what an
     * unfiltered select holds. Mirrored by NO_CATEGORY in resources/js/composables/filter.js,
     * where the cash flow page's uncategorised block puts it into a link.
     */
    public const NO_CATEGORY = 'none';

    /** Unencrypted, as bootstrap/app.php says: the page writes these, not the server. */
    public const HIDE_TRANSFERS_COOKIE = 'transactions_hide_transfers';

    public const TOTALS_COOKIE = 'transactions_totals';

    public function index(Request $r)
    {
        // Hide transfers is a choice about the page, kept in a cookie so the first request
        // can honour it: kept in the browser's storage, a page that remembered it loaded the
        // whole list and then asked again, and was a second slower for it. A link that names
        // it wins for that visit. Read before the query builder reads the filter.
        $filter = (array) $r->query('filter', []);

        if (! array_key_exists('hide_transfers', $filter) && $r->cookie(self::HIDE_TRANSFERS_COOKIE) === '1') {
            $r->query->set('filter', [...$filter, 'hide_transfers' => '1']);
        }

        $hideTransfers = in_array((string) ($r->query('filter')['hide_transfers'] ?? ''), ['1', 'true'], true);

        // What the form needs, which the dialog on any other page asks for too: see formProps().
        $form = self::formProps();
        $categories = $form['options']['categories'];

        // The filter gets a list of its own, because the two forms choose a category from the
        // plain one and neither may offer a row with none: NO_CATEGORY is a filter, not a
        // category, and a form that offered it would write it into a category_id.
        $filterCategories = [
            ...$categories->all(),
            ['label' => 'No category', 'value' => self::NO_CATEGORY],
        ];

        // Inactive cards included: closing a card does not pay it.
        $cards = Account::query()
            ->where('type', AccountType::Card->value)
            ->orderBy('name')
            ->with('meta')
            ->get();

        $cardPeriods = $cards
            ->mapWithKeys(fn (Account $card) => [$card->id => CardStatement::forAccount($card)])
            ->all();

        // The periods decide what is paid, not a row's settled_by. Keyed by card, since a
        // due date is only unique within one card.
        $unpaid = collect($cardPeriods)
            ->map(fn (Collection $periods) => $periods->reject->isSettled()->pluck('dueDate')->all())
            ->filter()
            ->all();

        // Whitelisted so a request cannot order by any other column.
        $sort = in_array($r->input('sort'), self::SORTABLE, true) ? $r->input('sort') : self::DEFAULT_SORT;
        $dir = $r->input('dir') === 'asc' ? 'asc' : 'desc';

        $transactions = QueryBuilder::for(Transaction::class, $r)
            ->allowedFilters(
                AllowedFilter::exact('account_id'),
                AllowedFilter::callback('account_type', fn (Builder $q, $value) => $q->whereHas(
                    'account',
                    fn (Builder $account) => $account->whereIn('type', (array) $value)
                )),
                AllowedFilter::exact('type'),
                AllowedFilter::exact('status'),
                // A category, or every row with none of one. The second has to be a value of
                // its own: null is what an untouched filter holds, so a filter that meant
                // both would be no filter at all -- which is what the cash flow page's
                // uncategorised block was sending, and why it showed the whole month.
                //
                // A callback rather than an exact filter, because the value has to be read
                // before it becomes a where. Or'd rather than and'd, since the select is a
                // set of categories and No category is one of them: asking for it and GAME &
                // TOY together is asking for either, and and'ing them asks for nothing.
                AllowedFilter::callback('category_id', function (Builder $q, $value) {
                    $values = (array) $value;
                    $none = in_array(self::NO_CATEGORY, $values, true);
                    $ids = array_values(array_filter(
                        $values,
                        fn ($id) => $id !== self::NO_CATEGORY,
                    ));

                    return $q->where(fn ($either) => $none
                        ? $either->whereNull('category_id')->orWhereIn('category_id', $ids)
                        : $either->whereIn('category_id', $ids));
                }),
                // Spending alone, as the report counts it. A category filter cannot say this:
                // an uncategorised month also holds uncategorised income, and a card payment
                // is a withdrawal and looks like any other. Off unless asked, as `unpaid` is,
                // so a stale filter[spending]=0 empties nothing.
                // The rows marked one-off. The flag is a JSON true in the bag; a value that
                // does not say yes filters nothing, as spending's does.
                AllowedFilter::callback('one_off', fn (Builder $q, $value) => in_array((string) $value, ['1', 'true'], true)
                    ? $q->whereHas('meta', fn (Builder $bag) => $bag->where('meta->one_off', true))
                    : $q),
                AllowedFilter::callback('spending', function (Builder $q, $value) {
                    if (! in_array((string) $value, ['1', 'true'], true)) {
                        return $q;
                    }

                    return CashFlow::whereSpends($q);
                }),
                // The bank's half of money that only moved between accounts: the withdrawal that
                // pays a card and the withdrawal or deposit a buy or sell settles through. Each
                // is the other half of a row the list shows already, and no category could mark
                // them: settle() and TradeCash write them with none, and TradeCash rewrites its
                // row's category to null on every edit of the trade. Off unless asked, as
                // `unpaid` is.
                AllowedFilter::callback('hide_transfers', function (Builder $q, $value) {
                    if (! in_array((string) $value, ['1', 'true'], true)) {
                        return $q;
                    }

                    return $q
                        ->whereNotIn('id', CashFlow::whereSettlesACard(DB::table('transactions')))
                        ->whereNotIn('id', CashFlow::whereSettlesATrade(DB::table('transactions')));
                }),
                AllowedFilter::exact('ccy'),
                // At or above a figure: the stored magnitude, which is what the Amount column
                // shows, in the row's own currency. Not card_amount, so a foreign charge is
                // found by the yen it was and not the dollars its card states. A value that is
                // not a plain non-negative number filters nothing, as a malformed day does.
                AllowedFilter::callback('amount_min', fn (Builder $q, $value) => is_string($value) && preg_match('/^\d+(\.\d+)?$/', trim($value))
                    ? $q->where('amount', '>=', trim($value))
                    : $q),
                // No delimiter: "coffee, tea" is one phrase.
                AllowedFilter::partial('description')->delimiter(''),
                // A callback because symbol lives in the meta bag, not a column. Or'd, so two
                // tickers find either.
                AllowedFilter::callback('symbol', function (Builder $q, $value) {
                    $phrases = array_filter(
                        (array) $value,
                        fn ($phrase) => is_string($phrase) && trim($phrase) !== ''
                    );

                    if ($phrases === []) {
                        return $q;
                    }

                    return $q->where(function (Builder $q) use ($phrases) {
                        foreach ($phrases as $phrase) {
                            $q->orWhereHas(
                                'meta',
                                fn (Builder $bag) => $bag->where(
                                    'meta->symbol',
                                    'like',
                                    '%'.trim($phrase).'%'
                                )
                            );
                        }
                    });
                }),
                // A malformed day is ignored: as a string, "2026-1-5" sorts after "2026-01-31".
                AllowedFilter::callback('date_from', fn (Builder $q, $value) => self::isDay($value)
                    ? $q->where('date', '>=', $value)
                    : $q),
                AllowedFilter::callback('date_to', fn (Builder $q, $value) => self::isDay($value)
                    ? $q->where('date', '<=', $value)
                    : $q),
                // A cash account's months, YYYY-MM each, by the row's own date: a bank's month as
                // its statement shows it, beside due_month, which is the cards'. Cash accounts
                // only, so a month lists the bank's rows and not every card charge made in it.
                // Ranges rather than a LEFT() on the column, so the date index still serves
                // it; a malformed month is dropped, as in due_month.
                AllowedFilter::callback('month', function (Builder $q, $value) {
                    $months = self::months($value);

                    return $months === []
                        ? $q
                        : $q->whereHas('account', fn ($a) => $a->where('type', AccountType::Cash->value))->where(function ($any) use ($months) {
                            foreach ($months as $month) {
                                $first = Carbon::createFromFormat('Y-m-d', "{$month}-01")->startOfDay();

                                $any->orWhere(fn ($in) => $in->where('date', '>=', $first->toDateString())
                                    ->where('date', '<', $first->copy()->addMonthNoOverflow()->toDateString()));
                            }
                        });
                }),
                // The day a cash flow month counts a row on, so its link lists what it summed.
                AllowedFilter::callback('counted_from', fn (Builder $q, $value) => self::isDay($value)
                    ? $q->where(fn (Builder $q) => CashFlow::whereCounted($q, '>=', $value))
                    : $q),
                AllowedFilter::callback('counted_to', fn (Builder $q, $value) => self::isDay($value)
                    ? $q->where(fn (Builder $q) => CashFlow::whereCounted($q, '<=', $value))
                    : $q),
                // One statement: its charges, and the payments naming it. Pair with account_id,
                // since a due date is only unique within one card.
                AllowedFilter::callback('due_date', fn (Builder $q, $value) => self::isDay($value)
                    ? $q->whereHas('meta', fn ($bag) => $bag->where('meta->due_date', $value))
                    : $q),
                // Every card's statements due in the months given, YYYY-MM each: those months'
                // card bills in one list. A malformed month is dropped, as a malformed day is
                // ignored, and none left is no filter rather than an empty list.
                AllowedFilter::callback('due_month', function (Builder $q, $value) {
                    $months = self::months($value);

                    return $months === []
                        ? $q
                        : $q->whereHas('meta', fn ($bag) => $bag->where(function ($any) use ($months) {
                            foreach ($months as $month) {
                                $any->orWhere('meta->due_date', 'like', "{$month}-%");
                            }
                        }));
                }),
                AllowedFilter::callback('unpaid', function (Builder $q, $value) use ($unpaid) {
                    // Off unless explicitly on: a stale filter[unpaid]=0 must not empty the list.
                    if (! in_array((string) $value, ['1', 'true'], true)) {
                        return $q;
                    }

                    $q->where('type', TransactionType::Charge->value);

                    // No constraint would return every charge rather than none.
                    if ($unpaid === []) {
                        return $q->whereRaw('0 = 1');
                    }

                    return $q->where(function (Builder $q) use ($unpaid) {
                        foreach ($unpaid as $cardId => $dates) {
                            $q->orWhere(fn (Builder $branch) => $branch
                                ->where('account_id', $cardId)
                                // whereHas adds the morph, so an account's bag of the same id cannot match.
                                ->whereHas('meta', fn ($bag) => $bag->whereIn('meta->due_date', $dates)));
                        }
                    });
                }),
            )
            ->with(['meta', 'account']);

        // A card payment is the other half of a bank withdrawal the list already shows, so
        // it is hidden, from the totals too, unless the filter is asking for it.
        if (! $this->wantsCardPayments((array) $r->input('filter', []))) {
            $transactions->where('type', '!=', TransactionType::Payment->value);
        }

        // Every filtered row, not the page: a total of ten rows would read as the filter's.
        // Unfiltered too, so the card under the table never disappears when the last filter
        // is cleared: gone, it shortened the page under a reader scrolled down to it, and the
        // browser threw them back up the list.
        //
        // Only with the panel open, which the page says in a cookie: closed, it is a grouped
        // query over every matching row for figures nobody is shown.
        $showTotals = $r->cookie(self::TOTALS_COOKIE) === '1';
        $totals = $showTotals
            ? $this->totals(clone $transactions->getEloquentBuilder())
            : ['currencies' => [], 'base' => null, 'unconverted' => []];
        $unconverted = $totals['unconverted'];
        $baseTotals = $totals['base'];
        $totals = $totals['currencies'];
        $base = Currency::Hkd->value;

        // By the signed figure the Amount column shows, not the stored magnitude, or money in
        // and money out interleave.
        $transactions = ($sort === 'amount'
            ? $transactions->orderByRaw(self::signedAmountSql().' '.$dir)
            : $transactions->orderBy($sort, $dir))
            // Tiebreak by id, or a row could appear on two pages or none.
            ->orderBy('id', $dir)
            ->paginate($r->input('per_page', self::PER_PAGE))
            ->withQueryString();

        $page = $transactions->getCollection();

        // From $page, before Data::collect() swaps the models for DTOs.
        $linked = $this->linkedCounterparts($page);

        $refusals = $this->deleteRefusals($page, $cardPeriods);

        $editLocks = $this->editLocks($page, $cardPeriods, $linked);

        $linked = array_map(fn (array $pair) => Arr::except($pair, 'row'), $linked);

        $directions = $page
            ->mapWithKeys(fn (Transaction $row) => [
                $row->id => $row->account === null
                    ? 0
                    : TransactionType::from($row->type)->movesBalanceOn(AccountType::from($row->account->type)),
            ])
            ->all();

        $data = TransactionData::collect($transactions, PaginatedDataCollection::class);

        $options = $form['options'] + ['filterCategories' => $filterCategories];

        // No window, unlike the hints: a ticker does not go stale. 'null' is what a present
        // null key unquotes to.
        $symbols = DB::table('meta')
            ->where('model_type', Transaction::class)
            ->selectRaw('DISTINCT JSON_UNQUOTE(JSON_EXTRACT(meta.meta, \'$.symbol\')) AS symbol')
            ->get()
            ->pluck('symbol')
            ->reject(fn ($symbol) => ! is_string($symbol) || in_array($symbol, ['', 'null'], true))
            ->sort()
            ->values()
            ->all();

        // Every account, not just active ones: a closed card's history is still searchable.
        //
        // Most used first, because the filter's question is which account, and the answer is
        // almost always the one with the most on it. Ranked here rather than in the option
        // list because the list re-sorts by account type to put the accounts under a heading
        // each, and that sort is stable -- so this order is what survives inside a heading.
        //
        // A left join, for the account with no rows in it: an inner join would quietly make
        // a new account unfilterable, and nothing else in the suite would notice. Name breaks
        // a tie, so two accounts with the same number of rows keep the order they had.
        $filterOptions = [
            'accounts' => Account::query()
                ->leftJoin('transactions', 'transactions.account_id', '=', 'accounts.id')
                ->select('accounts.id', 'accounts.name', 'accounts.type', 'accounts.ccy')
                // row_count, not usage: the second is a reserved word and MySQL refuses it
                // as an alias outright.
                ->selectRaw('COUNT(transactions.id) AS row_count')
                ->groupBy('accounts.id', 'accounts.name', 'accounts.type', 'accounts.ccy')
                ->orderByDesc('row_count')
                ->orderBy('accounts.name')
                ->get()
                ->map(fn (Account $account) => [
                    'label' => $account->name,
                    'value' => $account->id,
                    'type' => $account->type,
                    'ccy' => $account->ccy,
                ]),
            // Every month a statement is due in, newest first, for the filter's month picker.
            // Every month a cash account has a row in, newest first, for the month picker.
            'months' => Transaction::query()
                ->whereHas('account', fn ($a) => $a->where('type', AccountType::Cash->value))
                ->selectRaw('DISTINCT LEFT(date, 7) AS month')
                ->orderByDesc('month')
                ->pluck('month')
                ->values(),
            'dueMonths' => Meta::query()
                ->where('model_type', Transaction::class)
                ->selectRaw("DISTINCT LEFT(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.due_date')), 7) AS month")
                ->whereRaw("JSON_EXTRACT(meta, '$.due_date') IS NOT NULL")
                ->orderByDesc('month')
                ->pluck('month')
                ->filter(fn (?string $month) => $month !== null && preg_match('/^\d{4}-\d{2}$/', $month))
                ->values(),
            'types' => array_column(TransactionType::offeredOrder(), 'value'),
            'accountTypes' => array_column(AccountType::cases(), 'value'),
            'symbols' => $symbols,
        ];

        $statements = $cards
            ->map(fn (Account $card) => [
                'card' => [
                    'id' => $card->id,
                    'name' => $card->name,
                    'ccy' => $card->ccy,
                ],
                // values(): gaps in the keys would reach Vue as an object, not a list.
                'periods' => $cardPeriods[$card->id]->reject->isSettled()->values()
                    ->map(fn (CardStatement $statement) => $statement->toArray())
                    ->all(),
            ])
            ->filter(fn (array $group) => $group['periods'] !== [])
            ->values();

        // A card with no bank is absent, which tells the dialog to offer a choice.
        $cardBanks = $cards
            ->filter(fn (Account $card) => $card->settlementAccount() !== null)
            ->mapWithKeys(fn (Account $card) => [
                $card->id => [
                    'id' => $card->settlementAccount()->id,
                    'name' => $card->settlementAccount()->name,
                ],
            ])
            ->all();

        $settlementOptions = Account::settlementOptions();

        // A currency with no bank gets an empty list, so "none" differs from "unknown".
        $settlementOptionsByCcy = $cards
            ->pluck('ccy')
            ->unique()
            ->mapWithKeys(fn (string $ccy) => [$ccy => Account::settlementOptions($ccy)->all()])
            ->all();

        // The filter as the request named it, not as the cookie filled it in: the page echoes
        // this back as what it asked for, and the cookie is not something it asked for.
        $params = array_merge($r->query(), ['sort' => $sort, 'dir' => $dir]);

        if ($filter === []) {
            unset($params['filter']);
        } else {
            $params['filter'] = $filter;
        }

        // The order AppTable leaves out of the URL, since the server applies it unasked.
        $meta = [...$form['meta'], 'sort' => ['by' => self::DEFAULT_SORT, 'dir' => 'desc']];

        // The one pending row the action column's post button leaves out, named from the enum
        // so the frontend does not restate it: posting a payment settles a statement, which is
        // what the settle dialog and the statement's own refusals are for.
        $settleType = TransactionType::Payment->value;

        return inertia('transaction', [...$form, ...compact(
            'data',
            'params',
            'hideTransfers',
            'meta',
            'options',
            'statements',
            'cardBanks',
            'settlementOptions',
            'settlementOptionsByCcy',
            'linked',
            'refusals',
            'editLocks',
            'directions',
            'settleType',
            'totals',
            'baseTotals',
            'unconverted',
            'showTotals',
            'base',
            'filterOptions',
        )]);
    }

    /**
     * What FormTransaction needs, and nothing of the list's: the transactions page sends it
     * with the list, and the Add menu asks for it alone (FormContextController) to open the
     * form over any other page. One method, so the dialog there cannot drift from the one here.
     *
     * @return array<string, mixed>
     */
    public static function formProps(): array
    {
        // Seeded here, not in a watcher: useWatchTarget() overwrites it when editing.
        // today() is Hong Kong's day, not the browser's.
        $formEmpty = TransactionData::empty([
            'date' => today()->toDateString(),
            'status' => TransactionStatus::Posted->value,
        ]);

        // Not the paginated set: a card on page two must stay selectable.
        $accounts = Account::query()
            ->with('meta')
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->map(fn (Account $account) => [
                'label' => $account->name,
                'value' => $account->id,
                'type' => $account->type,
                'ccy' => $account->ccy,
            ]);

        $categories = Category::all()->map(fn ($category) => [
            'label' => $category->name,
            'value' => $category->id,
        ]);

        // Per account type: a flat list would offer "buy" on savings only to refuse it.
        $typeOptions = collect(AccountType::cases())
            ->mapWithKeys(fn (AccountType $accountType) => [
                $accountType->value => collect(TransactionType::offeredOrder())
                    ->filter(fn (TransactionType $type) => $type->isAllowedFor($accountType))
                    ->map(fn (TransactionType $type) => $type->value)
                    ->values()
                    ->all(),
            ])
            ->all();

        $typeDefaults = collect(AccountType::cases())
            ->mapWithKeys(fn (AccountType $accountType) => [
                $accountType->value => $accountType->defaultTransactionType()?->value,
            ])
            ->all();

        $derivesAmountTypes = collect(TransactionType::cases())
            ->filter(fn (TransactionType $type) => $type->derivesAmount())
            ->map(fn (TransactionType $type) => $type->value)
            ->values()
            ->all();

        $symbolTypes = collect(TransactionType::cases())
            ->filter(fn (TransactionType $type) => $type->carriesSymbol())
            ->map(fn (TransactionType $type) => $type->value)
            ->values()
            ->all();

        $brokers = Account::query()
            ->where('type', AccountType::Security->value)
            ->where('status', 'active')
            ->with('meta')
            ->orderBy('name')
            ->get();

        // Open positions only: a dividend is paid on something held.
        $heldSymbols = $brokers
            ->mapWithKeys(fn (Account $broker) => [$broker->id => Positions::heldSymbols($broker)])
            ->all();

        // A bank dividend's brokerage picker: the brokerages settling into each bank.
        $dividendBrokerages = $brokers
            ->groupBy(fn (Account $broker) => (string) ($broker->meta?->meta['settlement_account_id'] ?? ''))
            ->forget('')
            ->map(fn ($group) => $group->map(fn (Account $broker) => ['label' => $broker->name, 'value' => $broker->id])->values())
            ->all();

        $statusOptions = array_column(TransactionStatus::cases(), 'value');

        $currencyOptions = collect(Currency::cases())
            ->map(fn (Currency $currency) => [
                'label' => $currency->label(),
                'value' => $currency->value,
            ])
            ->values();

        // Raw because `date` is a MySQL keyword the grammar does not quote inside MAX().
        // Across every account on purpose: a hint is not per account.
        $descriptionHints = Transaction::query()
            ->where('date', '>=', today()->subYears(self::HINT_YEARS)->toDateString())
            ->groupBy('description')
            ->orderByRaw('MAX(`date`) DESC')
            ->orderBy('description')
            ->pluck('description')
            ->values();

        $templates = TransactionTemplate::query()
            ->with('account')
            ->orderBy('name')
            ->get(['id', 'name', 'account_id', 'category_id', 'payload'])
            ->map(fn (TransactionTemplate $template) => [
                'key' => 'template-'.$template->id,
                'id' => $template->id,
                'name' => $template->name,
                'account_id' => $template->account_id,
                'account_name' => $template->account?->name,
                'category_id' => $template->category_id,
                'derived' => false,
                'payload' => $template->payload,
            ])
            ->all();

        // An active recurring rule is a template in substance -- an account, a category, a
        // type, a description and a figure -- so the form reads it as one instead of it being
        // copied into a template row. A copy would be a second copy to keep: the rules are
        // brought up to date from the transaction history, and a copy would offer the figure a
        // subscription had on the day it was copied. No id, because there is no row behind it
        // to delete or edit.
        $rules = RecurringTransaction::query()
            ->with('account')
            ->where('active', true)
            ->orderBy('description')
            ->get()
            ->map(fn (RecurringTransaction $rule) => [
                'key' => 'rule-'.$rule->id,
                'id' => null,
                'name' => $rule->description,
                'account_id' => $rule->account_id,
                'account_name' => $rule->account?->name,
                'category_id' => $rule->category_id,
                'derived' => true,
                // When the rule lands, outside the payload since the payload fills the form's
                // fields. The form moves the date to this day, in the month it already has.
                'schedule' => [
                    'frequency' => $rule->frequency,
                    'day' => (int) substr((string) $rule->start_date, 8, 2),
                    'month' => (int) substr((string) $rule->start_date, 5, 2),
                ],
                'payload' => [
                    'type' => $rule->type,
                    'description' => $rule->description,
                    'amount' => $rule->amount,
                    'ccy' => $rule->ccy,
                    // What the form seeds anyway. A rule carries no status, and a payment
                    // that has happened is not a pending one.
                    'status' => TransactionStatus::Posted->value,
                ],
            ])
            ->all();

        $templates = [...$templates, ...$rules];

        $meta = ['form' => 'transaction-form', 'path' => '/transactions'];
        $options = compact('accounts', 'categories');

        return compact(
            'formEmpty',
            'meta',
            'options',
            'typeOptions',
            'typeDefaults',
            'derivesAmountTypes',
            'symbolTypes',
            'heldSymbols',
            'dividendBrokerages',
            'statusOptions',
            'currencyOptions',
            'templates',
            'descriptionHints',
        );
    }

    private static function isDay(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
    }

    /**
     * The well-formed months in a filter value, YYYY-MM each: a lone month, a comma list the
     * query builder has split, or an array.
     *
     * @return list<string>
     */
    private static function months(mixed $value): array
    {
        return array_values(array_filter(
            (array) $value,
            fn ($month) => is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1,
        ));
    }

    public function store(TransactionData $data)
    {
        // Outside the try, or a ValidationException is caught and reported as "error db...".
        $data->guardNewChargePeriod(Account::with('meta')->find($data->account_id));
        $data->guardHoldings();

        DB::beginTransaction();

        try {
            $transaction = $data->write();

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();

            report($e);

            return back()->with('message', 'error db...');
        }

        // type, not type->value: Transaction declares no casts.
        return back()->with('message', "Transaction [{$transaction->type}] recorded");
    }

    public function update(Transaction $transaction, TransactionData $data)
    {
        // Outside the try, as in store().
        $data->placeChargeInItsPeriod(
            Account::with('meta')->find($data->account_id),
            $transaction
        );

        // After the move guard, whose message is the better one for a charge leaving a paid
        // statement.
        $data->guardFigures($transaction);
        $data->guardHoldings($transaction);

        $data->keepLinksOf($transaction);

        // For TradeCash: a buy edited into another type leaves a cash row to remove.
        $wasType = $transaction->type;

        DB::beginTransaction();

        try {
            $transaction->update($data->except('meta_data')->toArray());

            $meta = collect($data->meta_data?->all())->filter(fn ($value) => $value !== null);

            if ($meta->isNotEmpty()) {
                // Keyed on the bag's id; keyed on the relation it finds nothing and inserts.
                $transaction->meta()->updateOrCreate(
                    ['id' => $transaction->meta?->id],
                    ['meta' => $meta]
                );
            } else {
                // A leftover bag would keep the charge in its statement period.
                $transaction->meta()->delete();
            }

            TradeCash::sync($transaction, $wasType);

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();

            report($e);

            return back()->with('message', 'error db...');
        }

        // fresh(): the type the row has now, not what was sent.
        return back()->with('message', "Transaction [{$transaction->fresh()->type}] updated");
    }

    public function storeTemplate(TransactionTemplateData $data)
    {
        $template = TransactionTemplate::create([
            'name' => self::freeName($data->name),
            'account_id' => $data->account_id,
            'category_id' => $data->category_id,
            'payload' => $data->keptPayload(),
        ]);

        // The name as it ended up: the toast is the only place a suffix is shown.
        return back()->with('message', "Template [{$template->name}] saved");
    }

    /** Replaces the payload rather than merging, so a cleared field stays cleared. */
    public function updateTemplate(TransactionTemplate $transactionTemplate, TransactionTemplateData $data)
    {
        $transactionTemplate->update([
            // Excluding itself, or an unchanged name would gain a suffix.
            'name' => self::freeName($data->name, $transactionTemplate),
            'account_id' => $data->account_id,
            'category_id' => $data->category_id,
            'payload' => $data->keptPayload(),
        ]);

        return back()->with('message', "Template [{$transactionTemplate->name}] updated");
    }

    public function destroyTemplate(TransactionTemplate $transactionTemplate)
    {
        $name = $transactionTemplate->name;

        $transactionTemplate->delete();

        return back()->with('message', "Template [$name] deleted");
    }

    /** One lookup per candidate: only the collation knows "netflix" collides with "Netflix". */
    private static function freeName(string $name, ?TransactionTemplate $except = null): string
    {
        $inUse = TransactionTemplate::query()
            ->when($except, fn (Builder $query) => $query->whereKeyNot($except->getKey()))
            ->where('name', $name);

        if (! $inUse->exists()) {
            return $name;
        }

        // mb_substr keeps the suffixed name within the 255-character column.
        for ($n = 2; ; $n++) {
            $suffix = " {$n}";
            $candidate = mb_substr($name, 0, 255 - strlen($suffix)).$suffix;

            if (! TransactionTemplate::where('name', $candidate)->exists()) {
                return $candidate;
            }
        }
    }

    /** The amount is computed here; the client's figure is only compared, to catch a stale one. */
    public function settle(Account $account, Request $r)
    {
        $figures = $r->validate([
            'due_date' => ['required', 'date_format:Y-m-d'],
            'owed' => ['required', 'decimal:0,'.TransactionMetaData::AMOUNT_SCALE],

            // Part of what is owed; the whole of it when absent.
            'amount' => ['nullable', 'decimal:0,'.TransactionMetaData::AMOUNT_SCALE, 'gt:0'],

            'date' => ['nullable', 'date_format:Y-m-d'],

            // Optional: the card's own link is used when it has one.
            'settlement_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
        ]);

        $refuse = fn (string $message) => throw ValidationException::withMessages(['due_date' => $message]);

        if ($account->type !== AccountType::Card->value) {
            $refuse(sprintf(
                'Account [%s] is a %s account. Only a card has a statement to settle.',
                $account->name,
                $account->type
            ));
        }

        $named = $account->settlementAccount();
        $bank = $named;

        // ?? null: validate() omits an absent key.
        if (($figures['settlement_account_id'] ?? null) !== null) {
            $chosen = Account::find($figures['settlement_account_id']);

            // Only a race: exists:accounts,id has already run.
            if ($chosen === null) {
                $refuse(sprintf(
                    'Card [%s] cannot be paid from account [%s], which no longer exists.',
                    $account->name,
                    $figures['settlement_account_id']
                ));
            }

            Account::guardSettledFrom(
                $chosen,
                $account->type,
                $account->ccy,
                Account::settlementWording($account->type)
            );

            // No self-target check: a card is not a cash account, so the guard refuses it.
            $bank = $chosen;
        }

        if ($bank === null) {
            $refuse(sprintf(
                'Card [%s] does not name the bank it is paid from, so it cannot be settled.',
                $account->name
            ));
        }

        $statement = CardStatement::forAccount($account)
            ->firstWhere('dueDate', $figures['due_date']);

        $owed = $statement?->owed() ?? '0.0000';

        if (BigDecimal::of($owed)->isLessThanOrEqualTo(BigDecimal::zero())) {
            $refuse(sprintf('Nothing is owed for the statement due %s.', $figures['due_date']));
        }

        // Before the figure check: pending rows are not billed yet.
        if ($statement->hasPendingActivity()) {
            $refuse(sprintf(
                'That statement has %d row%s not yet posted, so its total is not final. Post or remove %s first.',
                $statement->pendingCount,
                $statement->pendingCount === 1 ? '' : 's',
                $statement->pendingCount === 1 ? 'it' : 'them'
            ));
        }

        if (! BigDecimal::of($owed)->isEqualTo(BigDecimal::of($figures['owed']))) {
            $refuse(sprintf(
                'That statement now owes %s %s, not %s. Check the figure and confirm again.',
                $owed,
                $account->ccy,
                $figures['owed']
            ));
        }

        $amount = BigDecimal::of($figures['amount'] ?? $owed)->toScale(TransactionMetaData::AMOUNT_SCALE);

        if ($amount->isGreaterThan(BigDecimal::of($owed))) {
            throw ValidationException::withMessages(['amount' => sprintf(
                'That statement owes %s %s, so a payment of %s would pay more than it owes.',
                $owed,
                $account->ccy,
                $amount
            )]);
        }

        $clears = $amount->isEqualTo(BigDecimal::of($owed));
        $amount = (string) $amount;

        $paidOn = $figures['date'] ?? $figures['due_date'];

        DB::beginTransaction();

        try {
            // Merged, not written: term_days and statement_day share this bag. Only when it
            // changed, so an ordinary settle writes nothing.
            if ($named?->id !== $bank->id) {
                $account->meta()->update([
                    'meta' => array_merge(
                        $account->meta?->meta?->getArrayCopy() ?? [],
                        ['settlement_account_id' => $bank->id]
                    ),
                ]);
            }

            $payment = Transaction::create([
                'account_id' => $account->id,
                'category_id' => null,
                'date' => $paidOn,
                'type' => TransactionType::Payment->value,
                'description' => sprintf('Statement %s', $figures['due_date']),
                'amount' => $amount,
                'ccy' => $account->ccy,
                'status' => TransactionStatus::Posted->value,
            ]);

            $transfer = Transaction::create([
                'account_id' => $bank->id,
                'category_id' => null,
                'date' => $paidOn,
                'type' => TransactionType::Withdraw->value,
                'description' => sprintf('Card payment [%s]', $account->name),
                'amount' => $amount,
                'ccy' => $account->ccy,
                'status' => TransactionStatus::Posted->value,
            ]);

            // Written directly: the DTO drops these ids. No due_date on the bank's bag, or it
            // would enter the card's arithmetic.
            $payment->meta()->create([
                'meta' => ['due_date' => $figures['due_date'], 'paired_transaction_id' => $transfer->id],
            ]);

            $transfer->meta()->create([
                'meta' => ['paired_transaction_id' => $payment->id],
            ]);

            // Only the payment that clears the statement marks its charges paid. Merged: the
            // bag also holds the due_date the statement groups on.
            $covered = ! $clears ? collect() : Transaction::query()
                ->with('meta')
                ->where('account_id', $account->id)
                ->where('type', TransactionType::Charge->value)
                ->whereHas('meta', fn ($q) => $q->where('meta->due_date', $figures['due_date']))
                ->get();

            foreach ($covered as $charge) {
                $charge->meta->update([
                    'meta' => array_merge($charge->meta->meta->getArrayCopy(), ['settled_by' => $payment->id]),
                ]);
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();

            report($e);

            return back()->with('message', 'error db...');
        }

        if (! $clears) {
            return back()->with('message', sprintf(
                'Card statement [%s] part-paid: %s of %s %s, %s still owed',
                $figures['due_date'],
                $amount,
                $owed,
                $account->ccy,
                (string) BigDecimal::of($owed)->minus($amount)
            ));
        }

        return back()->with('message', sprintf(
            'Card statement [%s] settled: %s %s',
            $figures['due_date'],
            $amount,
            $account->ccy
        ));
    }

    /** Which periods may move is decided in CardStatement::moveDueDate(). */
    public function moveDueDate(Account $account, Request $r)
    {
        $figures = $r->validate([
            'due_date' => ['required', 'date_format:Y-m-d'],
            'new_due_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $refuse = fn (string $message) => throw ValidationException::withMessages(['due_date' => $message]);

        // Its own refusal, or a savings account would read as having no statement that day.
        if ($account->type !== AccountType::Card->value) {
            $refuse(sprintf(
                'Account [%s] is a %s account. Only a card has a statement to correct.',
                $account->name,
                $account->type
            ));
        }

        CardStatement::moveDueDate($account, $figures['due_date'], $figures['new_due_date']);

        return back()->with('message', sprintf(
            'Card statement [%s] now falls due [%s]',
            $figures['due_date'],
            $figures['new_due_date']
        ));
    }

    public function destroy(Transaction $transaction)
    {
        $refusal = $this->deleteRefusal($transaction);

        if ($refusal !== null) {
            return back()->with('message', $refusal);
        }

        // Read before the bags are deleted.
        $pairedId = $transaction->meta?->meta?->getArrayCopy()['paired_transaction_id'] ?? null;
        $partner = $pairedId === null ? null : Transaction::with(['meta', 'account'])->find($pairedId);
        $period = $this->settlementPeriod($transaction, $partner);
        $cashSide = TradeCash::isTrade($transaction) ? TradeCash::describe($transaction) : null;

        // A settlement's two rows go together or not at all.
        $rows = $partner === null ? [$transaction] : [$transaction, $partner];

        DB::beginTransaction();

        try {
            foreach ($rows as $row) {
                // A deleted payment reopens its statement, so clear the charges' settled_by.
                if ($row->type === TransactionType::Payment->value) {
                    $this->forgetSettlement($row);
                }

                $row->meta()->delete();
                $row->delete();
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();

            report($e);

            return back()->with('message', 'error db...');
        }

        if ($partner === null) {
            return back()->with('message', "Transaction [{$transaction->type}] deleted");
        }

        if ($cashSide !== null) {
            return back()->with('message', "Trade {$cashSide} deleted with its cash side: 2 transactions");
        }

        return back()->with('message', $period === null
            ? 'Card settlement deleted in full: 2 transactions'
            : sprintf('Card settlement [%s] deleted in full: 2 transactions', $period));
    }

    /**
     * A card payment is wanted by name, by a card chosen as the account, or with a
     * statement, which is its charges and the payments naming it.
     *
     * @param  array<string, mixed>  $filter
     */
    private function wantsCardPayments(array $filter): bool
    {
        $list = fn ($value) => array_filter(is_array($value) ? $value : explode(',', (string) $value));

        if (in_array(TransactionType::Payment->value, $list($filter['type'] ?? ''), true)) {
            return true;
        }

        if (! empty($filter['due_date'])) {
            return true;
        }

        $accounts = $list($filter['account_id'] ?? '');

        return $accounts !== [] && Account::query()
            ->whereIn('id', $accounts)
            ->where('type', AccountType::Card->value)
            ->exists();
    }

    /**
     * The amount signed as movesBalanceOn() signs it, as SQL. Built from the enums, so it
     * cannot drift from the Amount column's sign. A brokerage row moves no balance and
     * shows no sign, so it sorts as money in.
     */
    private static function signedAmountSql(): string
    {
        $cases = [];

        foreach (TransactionType::cases() as $type) {
            foreach ($type->accountTypes() as $accountType) {
                if ($type->movesBalanceOn($accountType) < 0) {
                    $cases[] = sprintf(
                        "WHEN transactions.type = '%s' AND (SELECT a.type FROM accounts a WHERE a.id = transactions.account_id) = '%s' THEN -transactions.amount",
                        $type->value,
                        $accountType->value
                    );
                }
            }
        }

        return 'CASE '.implode(' ', $cases).' ELSE transactions.amount END';
    }

    /**
     * Money in and out per currency, signed as each row's Amount is. A brokerage row
     * moves no balance, so trades are totalled apart rather than netted. A pending row
     * moves no balance either, so it is left out of these and of the base row below.
     *
     * Alongside the per-currency strips, the same figures in the base currency. A list of
     * USD rows and nothing else otherwise never says what it came to in HKD, and that is
     * the list a reader most wants it on -- so the test is whether anything in the set is
     * not already the base currency, not whether the set spans two. A list that is all HKD
     * gains nothing, since the base row would repeat that strip in the same words.
     *
     * @return array{currencies: list<array{ccy: string, count: int, in: string, out: string, net: string, trades: string}>, base: array{count: int, in: string, out: string, net: string, trades: string}|null, unconverted: list<string>}
     */
    private function totals(Builder $query): array
    {
        // A pending row moves no balance, so it is not a figure the reader has yet, and the
        // list is the only place it belongs. On the clone and not the shared builder, since
        // the table above must keep showing the rows the reader is looking at.
        $query = $query->whereIn('status', TransactionStatus::countingTowardBalance());

        // Summed in the database, a group to each currency, direction and kind of figure,
        // not row by row in PHP: the list is every row matching the filter, which on the
        // whole ledger is nine thousand models with their accounts and bags hydrated to add
        // up, and that took two of the page's two and a half seconds. The filter is the
        // list's own query, as a set of ids, so every filter applies without being restated.
        $groups = DB::table('transactions')
            ->leftJoin('accounts', 'accounts.id', '=', 'transactions.account_id')
            ->leftJoin('meta', fn ($join) => $join
                ->on('meta.model_id', '=', 'transactions.id')
                ->where('meta.model_type', Transaction::class))
            ->whereIn('transactions.id', $query->setEagerLoads([])->toBase()->select('transactions.id'))
            ->selectRaw('transactions.ccy AS ccy')
            ->selectRaw(self::signSql().' AS sign')
            ->selectRaw('accounts.type AS account_type, accounts.ccy AS account_ccy')
            ->selectRaw(self::statedSql().' IS NOT NULL AS stated')
            ->selectRaw('COUNT(*) AS n, SUM(transactions.amount) AS amount')
            ->selectRaw('SUM('.self::statedSql().') AS card_amount')
            ->groupBy('ccy', 'sign', 'account_type', 'account_ccy', 'stated')
            ->get();

        $zero = fn () => ['count' => 0, 'in' => BigDecimal::zero(), 'out' => BigDecimal::zero(), 'trades' => BigDecimal::zero()];
        $add = function (array $into, int $sign, int $count, string $amount) {
            $key = match ($sign) {
                1 => 'in',
                -1 => 'out',
                default => 'trades',
            };
            $into[$key] = $into[$key]->plus($amount);
            $into['count'] += $count;

            return $into;
        };
        $shown = fn (array $sums) => [
            'count' => $sums['count'],
            'in' => (string) $sums['in']->toScale(4),
            'out' => (string) $sums['out']->toScale(4),
            'net' => (string) $sums['in']->minus($sums['out'])->toScale(4),
            'trades' => (string) $sums['trades']->toScale(4),
        ];

        $base = Currency::Hkd->value;
        $byCurrency = [];
        $inBase = $zero();
        $unconverted = [];

        foreach ($groups as $group) {
            $sign = (int) $group->sign;
            $count = (int) $group->n;

            $byCurrency[$group->ccy] = $add($byCurrency[$group->ccy] ?? $zero(), $sign, $count, (string) $group->amount);

            // The base figure, as AGENTS.md says a card row is read: a base row's own amount, and a foreign
            // charge on a base card at what the card states. Any other foreign row has no
            // figure, and is named rather than counted at one-for-one.
            $figure = match (true) {
                $group->ccy === $base => (string) $group->amount,
                $group->account_type === AccountType::Card->value && $group->account_ccy === $base && (bool) $group->stated => (string) $group->card_amount,
                default => null,
            };

            if ($figure === null) {
                $unconverted[$group->ccy] = true;

                continue;
            }

            $inBase = $add($inBase, $sign, $count, $figure);
        }

        ksort($byCurrency);

        $currencies = array_map(
            fn (string $ccy, array $sums) => ['ccy' => $ccy, ...$shown($sums)],
            array_keys($byCurrency),
            $byCurrency,
        );

        // Null unless something here is not already the base currency, and something
        // converted. The second test is what stops a list of wholly unconvertible rows
        // showing a total of 0.00 over an account that plainly holds money: a figure of
        // nothing is a claim, and this one would be false.
        $worthConverting = array_keys($byCurrency) !== [] && array_keys($byCurrency) !== [$base];

        return [
            'currencies' => $currencies,
            'base' => $worthConverting && $inBase['count'] > 0 ? $shown($inBase) : null,
            'unconverted' => array_keys($unconverted),
        ];
    }

    /**
     * 1, -1 or 0, as the row's Amount column shows it, in SQL: built from movesBalanceOn() so it
     * cannot drift from it. A row whose account is gone moves nothing.
     */
    private static function signSql(): string
    {
        $cases = [];

        foreach (TransactionType::cases() as $type) {
            foreach ($type->accountTypes() as $accountType) {
                $sign = $type->movesBalanceOn($accountType);

                if ($sign !== 0) {
                    $cases[] = sprintf("WHEN transactions.type = '%s' AND accounts.type = '%s' THEN %d", $type->value, $accountType->value, $sign);
                }
            }
        }

        return 'CASE '.implode(' ', $cases).' ELSE 0 END';
    }

    /** A row's card_amount as a number, or null where the bag states none. */
    private static function statedSql(): string
    {
        return "CASE WHEN JSON_TYPE(JSON_EXTRACT(meta.meta, '$.card_amount')) IN ('STRING', 'INTEGER', 'DECIMAL', 'DOUBLE') "
            ."THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(meta.meta, '$.card_amount')) AS DECIMAL(24, 4)) END";
    }

    /**
     * By the marker, so a refiled charge is cleared too, and by the period, since removing
     * an earlier part-payment reopens a statement a later payment marked paid.
     */
    private function forgetSettlement(Transaction $payment): void
    {
        $dueDate = $payment->meta?->meta['due_date'] ?? null;

        $bags = Meta::query()
            ->where('model_type', Transaction::class)
            ->where(fn ($q) => $q
                ->where('meta->settled_by', $payment->id)
                ->when($dueDate !== null, fn ($q) => $q->orWhereIn('model_id', Transaction::query()
                    ->select('id')
                    ->where('account_id', $payment->account_id)
                    ->where('type', TransactionType::Charge->value)
                    ->whereHas('meta', fn ($bag) => $bag->where('meta->due_date', $dueDate)))))
            ->get()
            ->filter(fn (Meta $bag) => isset($bag->meta['settled_by']));

        foreach ($bags as $bag) {
            $bag->update(['meta' => Arr::except($bag->meta->getArrayCopy(), 'settled_by')]);
        }
    }

    /**
     * The message names the way out, or a mistake in a paid statement could never be fixed.
     *
     * @param  Collection<int, CardStatement>|null  $cardPeriods
     */
    private function deleteRefusal(?Transaction $transaction, ?Collection $cardPeriods = null): ?string
    {
        if ($transaction?->type === TransactionType::Buy->value) {
            return $this->buyRefusal($transaction);
        }

        // A cash side goes with its trade, never alone past the buy's holdings check.
        $pairedId = $transaction?->meta?->meta?->getArrayCopy()['paired_transaction_id'] ?? null;
        $partner = $pairedId === null ? null : Transaction::with(['meta', 'account'])->find($pairedId);

        if (TradeCash::isTrade($partner)) {
            return sprintf(
                'This %s is the cash side of the trade %s. Delete the trade instead, and its cash '
                    .'goes with it.',
                $transaction->type,
                TradeCash::describe($partner)
            );
        }

        if ($transaction === null || $transaction->type !== TransactionType::Charge->value) {
            return null;
        }

        $dueDate = $transaction->meta?->meta?->getArrayCopy()['due_date'] ?? null;

        if ($dueDate === null) {
            return null;
        }

        $cardPeriods ??= $this->cardPeriodsFor($transaction);

        $period = $cardPeriods?->firstWhere('dueDate', $dueDate);

        if ($period === null || ! $period->isClosed()) {
            return null;
        }

        return sprintf(
            'Charge [%s] is in the statement due %s, which has been settled, and cannot be deleted '
                .'on its own. Delete the payment that settled it first.',
            $transaction->description,
            $dueDate
        );
    }

    /** Only a shortfall the delete causes, as in TransactionData::guardHoldings(). */
    private function buyRefusal(Transaction $buy): ?string
    {
        $trades = $this->tradesByBroker[$buy->account_id] ??= $buy->account === null
            ? []
            : Positions::tradesOf($buy->account);

        $short = Positions::shortfall(array_values(array_filter($trades, fn (array $t) => $t['id'] !== $buy->id)));

        if ($short === null || $short === Positions::shortfall($trades)) {
            return null;
        }

        return sprintf(
            'Deleting this buy leaves the sell of %s %s on %s with only %s held. Delete or reduce '
                .'that sell first.',
            Positions::plain($short['selling']),
            $short['symbol'],
            $short['date'],
            Positions::plain($short['held'])
        );
    }

    /**
     * @param  Collection<int, Transaction>  $rows
     * @param  array<int, Collection<int, CardStatement>>  $cardPeriods
     * @return array<int, string>
     */
    private function deleteRefusals(Collection $rows, array $cardPeriods): array
    {
        $refusals = [];

        foreach ($rows as $row) {
            $refusal = $this->deleteRefusal($row, $cardPeriods[$row->account_id] ?? null);

            if ($refusal !== null) {
                $refusals[$row->id] = $refusal;
            }
        }

        return $refusals;
    }

    /**
     * @param  Collection<int, Transaction>  $rows
     * @param  array<int, Collection<int, CardStatement>>  $cardPeriods
     * @param  array<int, array<string, mixed>>  $linked
     * @return array<int, array{fields: list<string>, message: string}>
     */
    private function editLocks(Collection $rows, array $cardPeriods, array $linked): array
    {
        $locks = [];

        foreach ($rows as $row) {
            $lock = TransactionData::figureLock(
                $row,
                $cardPeriods[$row->account_id] ?? null,
                $linked[$row->id]['row'] ?? null
            );

            if ($lock !== null) {
                $locks[$row->id] = Arr::only($lock, ['fields', 'message']);
            }
        }

        return $locks;
    }

    private function cardPeriodsFor(Transaction $transaction): ?Collection
    {
        $account = $transaction->account;

        if ($account?->type !== AccountType::Card->value) {
            return null;
        }

        return CardStatement::forAccount($account);
    }

    /**
     * @param  Collection<int, Transaction>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function linkedCounterparts(Collection $rows): array
    {
        $counterpartIds = $rows
            ->map(fn (Transaction $row) => $row->meta?->meta['paired_transaction_id'] ?? null)
            ->filter()
            ->unique()
            ->values();

        if ($counterpartIds->isEmpty()) {
            return [];
        }

        $counterparts = Transaction::query()
            ->with(['account', 'meta'])
            ->whereIn('id', $counterpartIds)
            ->get()
            ->keyBy('id');

        $linked = [];

        foreach ($rows as $row) {
            $other = $counterparts->get($row->meta?->meta['paired_transaction_id'] ?? null);

            if ($other === null) {
                continue;
            }

            $linked[$row->id] = [
                'id' => $other->id,
                'description' => $other->description,
                'date' => $other->date,
                'amount' => $other->amount,
                'ccy' => $other->ccy,
                'account_name' => $other->account?->name,
                // Read off the card's half rather than stored on the bank's, so a corrected
                // due date moves it too.
                'due_date' => $other->meta?->meta['due_date'] ?? null,
                'kind' => match (true) {
                    TradeCash::isTrade($other), TradeCash::isTrade($row) => 'trade',
                    default => 'settlement',
                },
                // For figureLock(); stripped before the page gets it.
                'row' => $other,
            ];
        }

        return $linked;
    }

    /** Only the payment half carries the due date, so read whichever row has it. */
    private function settlementPeriod(?Transaction ...$rows): ?string
    {
        foreach ($rows as $row) {
            $due = $row?->meta?->meta?->getArrayCopy()['due_date'] ?? null;

            if ($due !== null) {
                return $due;
            }
        }

        return null;
    }
}
