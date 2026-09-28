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
use App\Models\Transaction;
use App\Models\TransactionTemplate;
use App\Support\CardStatement;
use App\Support\Positions;
use App\Support\TradeCash;
use Brick\Math\BigDecimal;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

    /** The columns the transactions table marks sortable. */
    private const SORTABLE = ['date', 'type', 'description', 'amount', 'status'];

    /**
     * How far back the description hints reach.
     *
     * A const rather than a literal in the query so the window is one thing to change,
     * and so the reason it is two years lives next to the number instead of in a
     * sentence about a date.
     */
    private const HINT_YEARS = 2;

    public function index(Request $r)
    {
        // Seeded with today, the way AccountController seeds the account form's status.
        // useWatchTarget() replaces these with the row's own values when editing, so
        // the seed reaches a new transaction and never an existing one -- which is why
        // this belongs here rather than in a watcher that would also fire on an edit.
        //
        // today() rather than a JS date, so "today" is the one Asia/Hong_Kong the rest
        // of the app already formats with (config/app.php, useHongKongTime) and not
        // whatever half-hour the browser thinks it is in.
        //
        // Posted, the default the DTO applies to a payload without one -- seeded too, so
        // the picker shows what will be saved rather than a blank the server then fills.
        $formEmpty = TransactionData::empty([
            'date' => today()->toDateString(),
            'status' => TransactionStatus::Posted->value,
        ]);

        // type and ccy ride along: the form needs both and has no other source.
        // Not the paginated set -- a card on page two must stay selectable.
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

        // By the day the money moved, newest first, rather than by when the row was typed
        // in: a charge entered a week late belongs among that week's rows. Only the
        // columns the table offers to sort, so a request cannot order by anything else.
        $sort = in_array($r->input('sort'), self::SORTABLE, true) ? $r->input('sort') : self::DEFAULT_SORT;
        $dir = $r->input('dir') === 'asc' ? 'asc' : 'desc';

        // Filters narrow the list and nothing else: the statement panel, the delete
        // refusals and the edit locks are about whole cards and whole periods, so a
        // filtered page still reports them in full. An unknown filter is a 400 from
        // the package rather than being ignored, so a mistyped key cannot quietly
        // show the unfiltered list as though it were the filtered one.
        $transactions = QueryBuilder::for(Transaction::class, $r)
            ->allowedFilters(
                // Comma-separated for several at once: filter[type]=charge,payment.
                AllowedFilter::exact('account_id'),
                // By the owning account's type -- every card row, say -- which is the
                // account's column, not the transaction's.
                AllowedFilter::callback('account_type', fn (Builder $q, $value) => $q->whereHas(
                    'account',
                    fn (Builder $account) => $account->whereIn('type', (array) $value)
                )),
                AllowedFilter::exact('type'),
                AllowedFilter::exact('status'),
                AllowedFilter::exact('category_id'),
                // The currency the row was made in, not its account's: a USD charge on an
                // HKD card is found under USD.
                AllowedFilter::exact('ccy'),
                // One phrase, not a list: "coffee, tea" is a description, and splitting
                // it on the comma would match either word.
                AllowedFilter::partial('description')->delimiter(''),
                // Inclusive, on the calendar day. A value that is not one is ignored
                // rather than compared as a string, where "2026-1-5" would sort after
                // "2026-01-31" and quietly drop rows.
                AllowedFilter::callback('date_from', fn (Builder $q, $value) => self::isDay($value)
                    ? $q->where('date', '>=', $value)
                    : $q),
                AllowedFilter::callback('date_to', fn (Builder $q, $value) => self::isDay($value)
                    ? $q->where('date', '<=', $value)
                    : $q),
            )
            // For account_name, which the accessor reads -- otherwise a query per row.
            ->with(['meta', 'account'])
            ->orderBy($sort, $dir)
            // Then by id, so rows sharing a date keep one order from page to page. MySQL
            // returns a tie in whatever order it likes, and a row could then show on two
            // pages or on none.
            ->orderBy('id', $dir)
            ->paginate($r->input('per_page', 5))
            ->withQueryString();

        $page = $transactions->getCollection();

        // What each card still owes. One query per card, not one for all: due_date
        // belongs to a single card's statements. Inactive cards included, unlike the
        // account picker below -- closing a card does not unpaid it, and settle() has
        // never checked status.
        //
        // Read once and split in PHP. The panel wants the periods still owing, and the
        // delete button below wants the ones already settled, and a second read would
        // be a second scan of the meta table -- see CardStatement on what that costs.
        $cards = Account::query()
            ->where('type', AccountType::Card->value)
            ->orderBy('name')
            // with('meta') so settlementAccount() is not a second query per card.
            ->with('meta')
            ->get();

        $cardPeriods = $cards
            ->mapWithKeys(fn (Account $card) => [$card->id => CardStatement::forAccount($card)])
            ->all();

        // What each row on this page would take with it, keyed by the row that would
        // take it along -- so the delete confirmation can name the other half of a card
        // settlement before the user agrees to remove it.
        //
        // Built from $page rather than from the paginator: Data::collect() below maps
        // it through and leaves DTOs where the models were, so a query returning it
        // afterwards hands over TransactionData and nothing says so -- a 500 on a page
        // with rows in it, an empty page otherwise.
        $linked = $this->linkedCounterparts($page);

        // Why each row on this page cannot be deleted, keyed by its id. Sent so the
        // button can say so rather than being offered and then refused.
        $refusals = $this->deleteRefusals($page, $cardPeriods);

        // Which figures each row on this page cannot change, and why, so the edit form
        // can say so and disable them rather than let the save be refused.
        $editLocks = $this->editLocks($page, $cardPeriods, $linked);

        // The partner rows were for the locks; the page gets the fields it names.
        $linked = array_map(fn (array $pair) => Arr::except($pair, 'row'), $linked);

        // Which way each row moves its account's balance, 1, -1 or 0, so the table can
        // mark money in and out. From movesBalanceOn() rather than a list in the page,
        // which would be a second copy of the rule, and per row because the answer
        // needs the account's type as well as the row's.
        $directions = $page
            ->mapWithKeys(fn (Transaction $row) => [
                $row->id => $row->account === null
                    ? 0
                    : TransactionType::from($row->type)->movesBalanceOn(AccountType::from($row->account->type)),
            ])
            ->all();

        $data = TransactionData::collect($transactions, PaginatedDataCollection::class);

        $options = compact('accounts', 'categories');

        // What the filter bar offers. Every account rather than the active ones the form
        // picks from, since a closed card's history is still worth finding; every type
        // in enum order, flat, since a filter is not narrowing by an account type.
        $filterOptions = [
            'accounts' => Account::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Account $account) => ['label' => $account->name, 'value' => $account->id]),
            'types' => array_column(TransactionType::cases(), 'value'),
            'accountTypes' => array_column(AccountType::cases(), 'value'),
        ];

        // The pairing is what makes a type legal, so a flat list would offer "buy" on
        // a savings account only to refuse it. Derived from accountTypes().
        $typeOptions = collect(AccountType::cases())
            ->mapWithKeys(fn (AccountType $accountType) => [
                $accountType->value => collect(TransactionType::cases())
                    ->filter(fn (TransactionType $type) => $type->isAllowedFor($accountType))
                    ->map(fn (TransactionType $type) => $type->value)
                    ->values()
                    ->all(),
            ])
            ->all();

        // Which type to pre-fill per account type. Beside typeOptions rather than folded
        // into it, so the list the picker reads keeps the shape it had.
        $typeDefaults = collect(AccountType::cases())
            ->mapWithKeys(fn (AccountType $accountType) => [
                $accountType->value => $accountType->defaultTransactionType()?->value,
            ])
            ->all();

        // Plain values for status (no display name), pairs for currency.
        $statusOptions = array_column(TransactionStatus::cases(), 'value');

        $currencyOptions = collect(Currency::cases())
            ->map(fn (Currency $currency) => [
                'label' => $currency->label(),
                'value' => $currency->value,
            ])
            ->values();

        // A period that is settled is a fact about the past and is left out, so a card
        // with five years of paid statements does not push the ones needing attention
        // off the page. The settled ones are not discarded: the refusal below is built
        // from them, and the transactions that made them are all still in the list.
        $statements = $cards
            ->map(fn (Account $card) => [
                'card' => [
                    'id' => $card->id,
                    'name' => $card->name,
                    'ccy' => $card->ccy,
                ],
                // values() because reject keeps the keys it did not reject, and a
                // period list keyed 1, 3, 7 reaches Vue as an object rather than the
                // list the v-for is written against.
                'periods' => $cardPeriods[$card->id]->reject->isSettled()->values()
                    ->map(fn (CardStatement $statement) => $statement->toArray())
                    ->all(),
            ])
            // A card with nothing outstanding is not worth a heading.
            ->filter(fn (array $group) => $group['periods'] !== [])
            ->values();

        // The bank each card is paid from, keyed by card id. Both fields, because the
        // dialog needs the id to preselect the picker and the name to print in the
        // sentence about where the money leaves. A card that names none is absent,
        // which is what tells the dialog to offer a choice.
        $cardBanks = $cards
            ->filter(fn (Account $card) => $card->settlementAccount() !== null)
            ->mapWithKeys(fn (Account $card) => [
                $card->id => [
                    'id' => $card->settlementAccount()->id,
                    'name' => $card->settlementAccount()->name,
                ],
            ])
            ->all();

        // The picker behind that choice -- the same list the account form offers, from
        // the model, so the two cannot disagree about what may be a target.
        $settlementOptions = Account::settlementOptions();

        // Hints for the description, which is the one field on this form a person types
        // rather than picks: everything else is chosen from a list the enum builds, and a
        // description is whatever the merchant was called that day.
        //
        // Two years, and the window is the point rather than a bound on the query. A
        // personal finance app outlives the accounts, cards and merchants someone had
        // four years ago, and a description from one of those is a suggestion nobody
        // would take -- it costs a keystroke to ignore and it is in the way.
        //
        // Newest first, by the last day the description was used rather than by name.
        // The thing about to be typed again is the thing last typed, and alphabetical
        // order would bury it under every "Coffee" ever entered.
        //
        // Raw for the aggregate, and the only raw clause here: `date` is a MySQL keyword,
        // so the grammar's own quoting never reaches inside MAX() and the statement fails
        // with "Unknown column 'MAX(date)' in 'order clause'". The column the filter
        // above uses goes through the grammar and needs nothing.
        //
        // Across every account, deliberately. A hint is "you have called this that
        // before", and the same description on a card and on a bank is one thing the
        // user thinks of rather than two. Narrowing it to the chosen account would also
        // mean the list changed under the field as they picked an account, and before
        // they picked one there would be nothing to offer at all.
        $descriptionHints = Transaction::query()
            ->where('date', '>=', today()->subYears(self::HINT_YEARS)->toDateString())
            ->groupBy('description')
            ->orderByRaw('MAX(`date`) DESC')
            ->orderBy('description')
            ->pluck('description')
            ->values();

        // The saved form states, for the transaction form's template menu. Read on every
        // page load rather than on demand: it is a handful of rows, and a menu that had
        // to be fetched is a menu that is not there when the dialog opens.
        //
        // Ordered by name, which is what makes the suffixed ones readable as a run:
        // "Coffee", "Coffee 2", "Coffee 3" rather than three scattered names, and a name
        // the server chose should be findable by eye.
        $templates = TransactionTemplate::query()
            ->with('account')
            ->orderBy('name')
            ->get(['id', 'name', 'account_id', 'category_id', 'payload'])
            ->map(fn (TransactionTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'account_id' => $template->account_id,
                'account_name' => $template->account?->name,
                'category_id' => $template->category_id,
                'payload' => $template->payload,
            ])
            ->all();

        $params = array_merge($r->query(), ['sort' => $sort, 'dir' => $dir]);

        // `sort` is the order AppTable leaves out of the URL, since the server applies it
        // unasked.
        $meta = [
            'form' => 'transaction-form',
            'path' => '/transactions',
            'sort' => ['by' => self::DEFAULT_SORT, 'dir' => 'desc'],
        ];

        return inertia('transaction', compact(
            'formEmpty',
            'data',
            'params',
            'meta',
            'options',
            'statements',
            'cardBanks',
            'settlementOptions',
            'linked',
            'refusals',
            'editLocks',
            'directions',
            'filterOptions',
            'typeOptions',
            'typeDefaults',
            'statusOptions',
            'currencyOptions',
            'templates',
            'descriptionHints',
        ));
    }

    /** Whether a filter value is a calendar day, Y-m-d, and one that exists. */
    private static function isDay(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
    }

    public function store(TransactionData $data)
    {
        // Outside the try, for the reason given in update().
        $data->guardNewChargePeriod(Account::with('meta')->find($data->account_id));
        $data->guardHoldings();

        // Two writes, so a failure between them must leave neither. As
        // AccountController::store().
        DB::beginTransaction();

        try {
            $transaction = Transaction::create($data->except('meta_data')->toArray());

            // Nulls dropped, falsy kept: plain filter() would drop a fee of '0', which
            // would then read back as missing rather than zero.
            $meta = collect($data->meta_data?->all())->filter(fn ($value) => $value !== null);

            if ($meta->isNotEmpty()) {
                $transaction->meta()->create([
                    'meta' => $meta,
                ]);
            }

            // A buy or sell's cash side, in the same write, so a trade never exists
            // without the money it moved.
            TradeCash::sync($transaction);

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();

            // Roll back but do not discard. See AccountErrorReportingTest.
            report($e);

            return back()->with('message', 'error db...');
        }

        // type, not type->value: Transaction declares no casts. See MassAssignmentTest.
        return back()->with('message', "Transaction [{$transaction->type}] recorded");
    }

    public function update(Transaction $transaction, TransactionData $data)
    {
        // Before the transaction is opened, and outside the try. A ValidationException
        // is an Exception, so one raised inside would be caught, rolled back and
        // reported as "error db..." -- telling the user the database failed when nothing
        // had been attempted. The guards in the DTO's constructor sit before this method
        // for the same reason, and by the same accident of when the DTO is built.
        //
        // with('meta') because the cycle is read off the card's bag.
        $data->placeChargeInItsPeriod(
            Account::with('meta')->find($data->account_id),
            $transaction
        );

        // After the move guard, whose message is the better one when a charge changes
        // card out of a paid statement.
        $data->guardFigures($transaction);
        $data->guardHoldings($transaction);

        $data->keepLinksOf($transaction);

        // What it was, for TradeCash: a buy edited into a dividend has cash to remove.
        $wasType = $transaction->type;

        // The bag is replaced rather than added, or a corrected charge would sit
        // beside the one it replaced.
        DB::beginTransaction();

        try {
            $transaction->update($data->except('meta_data')->toArray());

            $meta = collect($data->meta_data?->all())->filter(fn ($value) => $value !== null);

            if ($meta->isNotEmpty()) {
                // Keyed on the bag's own id, as AccountController does; keyed on the
                // relation instead, it finds nothing and tries to insert.
                $transaction->meta()->updateOrCreate(
                    ['id' => $transaction->meta?->id],
                    ['meta' => $meta]
                );
            } else {
                // A bag left behind would keep the charge in its statement period,
                // which the query groups on the bag's due_date.
                $transaction->meta()->delete();
            }

            // The cash side follows the corrected trade: its amount, date and status,
            // and its bank if the trade moved brokerage.
            TradeCash::sync($transaction, $wasType);

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();

            report($e);

            return back()->with('message', 'error db...');
        }

        // fresh(), not the instance: name what the row is now, not what was sent.
        return back()->with('message', "Transaction [{$transaction->fresh()->type}] updated");
    }

    /**
     * Save the form's current values as a reusable template.
     *
     * The payload arrives whole -- the browser sends the form as it stands -- and is
     * narrowed to the keys a template keeps before it is stored, so nothing the form
     * happens to hold can be written just because it was sent.
     *
     * No transaction write and no transaction lock around it: nothing here touches the
     * accounts table's rows or any balance, so the two writes that matter are this one
     * and the deletion a cascade may bring with it.
     */
    public function storeTemplate(TransactionTemplateData $data)
    {
        $template = TransactionTemplate::create([
            'name' => self::freeName($data->name),
            'account_id' => $data->account_id,
            'category_id' => $data->category_id,
            'payload' => $data->keptPayload(),
        ]);

        // The name as it ended up, not the one asked for: "Netflix 2" is the name this
        // template now answers to and the toast is the only place the user is told.
        return back()->with('message', "Template [{$template->name}] saved");
    }

    /**
     * Replace a template's values with the form's, which is how a template that has
     * drifted is put right without deleting and retyping it.
     *
     * The whole payload is replaced rather than merged into, so a field the user has
     * since cleared is cleared here too. Merging would keep the old value on a key the
     * new form left null, and the template would go on filling in something the user
     * deliberately removed.
     */
    public function updateTemplate(TransactionTemplate $transactionTemplate, TransactionTemplateData $data)
    {
        $transactionTemplate->update([
            // Excluding the row being updated is what keeps a plain update from
            // renaming "Netflix" to "Netflix 2" and the one after that to "Netflix 3":
            // the name is taken by this very template.
            'name' => self::freeName($data->name, $transactionTemplate),
            'account_id' => $data->account_id,
            'category_id' => $data->category_id,
            'payload' => $data->keptPayload(),
        ]);

        return back()->with('message', "Template [{$transactionTemplate->name}] updated");
    }

    /**
     * Remove a template.
     *
     * One click away from a shortcut somebody was about to use, and nothing else here
     * loses it, so -- as with a transaction or a category -- the button confirms and the
     * confirmation names the thing rather than asking whether you are sure.
     */
    public function destroyTemplate(TransactionTemplate $transactionTemplate)
    {
        $name = $transactionTemplate->name;

        $transactionTemplate->delete();

        return back()->with('message', "Template [$name] deleted");
    }

    /**
     * The name as given, or that name with a number on the end while it is taken.
     *
     * A loop of one-row lookups rather than a single query over the names in use,
     * because it is the column's collation that decides "netflix" collides with
     * "Netflix", and only the database knows that. Comparing in PHP instead would agree
     * with the unique index about some pairs and not others, and a pair it disagreed
     * about becomes a 500 on insert -- a name the app thought was free and the database
     * did not.
     *
     * $except is the row being updated, so keeping a template's own name is not a
     * collision.
     */
    private static function freeName(string $name, ?TransactionTemplate $except = null): string
    {
        $inUse = TransactionTemplate::query()
            ->when($except, fn (Builder $query) => $query->whereKeyNot($except->getKey()))
            ->where('name', $name);

        if (! $inUse->exists()) {
            return $name;
        }

        // mb_substr because the column counts characters and a suffix must not push the
        // name past it: a 255-character name would otherwise become a name too long for
        // the column that holds it, which is a 500 rather than a template.
        for ($n = 2; ; $n++) {
            $suffix = " {$n}";
            $candidate = mb_substr($name, 0, 255 - strlen($suffix)).$suffix;

            if (! TransactionTemplate::where('name', $candidate)->exists()) {
                return $candidate;
            }
        }
    }

    /**
     * Pay off one statement period of a card, in two rows.
     *
     * The amount is computed here, never taken from the request. The figure the
     * client does send is compared, not used: since both rows are written together, a
     * payment against a stale figure would be internally consistent and invisible.
     */
    public function settle(Account $account, Request $r)
    {
        $figures = $r->validate([
            'due_date' => ['required', 'date_format:Y-m-d'],
            'owed' => ['required', 'decimal:0,'.TransactionMetaData::AMOUNT_SCALE],

            // The day the money moved, which is a choice rather than a fact about the
            // period. Optional so a caller that sends nothing gets the period's own due
            // date below, which is the answer in the ordinary case.
            'date' => ['nullable', 'date_format:Y-m-d'],

            // The cash account the money leaves, which the dialog always sends and a
            // caller may not. Optional because the card's own link is the answer when
            // it has one, and a card that has none is a reachable state rather than
            // broken data -- AccountMetaData permits a card to carry no link at all.
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

        // ?? null because validate() omits an absent key entirely -- nullable permits a
        // present null, not a missing one. The date field above is read the same way.
        if (($figures['settlement_account_id'] ?? null) !== null) {
            $chosen = Account::find($figures['settlement_account_id']);

            // exists:accounts,id has already run, so this only fires for an account
            // deleted between the two, and a bank that is not there is the same answer
            // as a bank that cannot be named.
            if ($chosen === null) {
                $refuse(sprintf(
                    'Card [%s] cannot be paid from account [%s], which no longer exists.',
                    $account->name,
                    $figures['settlement_account_id']
                ));
            }

            // The account form's own rule, so the dialog cannot offer a target that form
            // would refuse. Account::guardSettledFrom() is where it lives.
            Account::guardSettledFrom(
                $chosen,
                $account->type,
                $account->ccy,
                Account::settlementWording($account->type)
            );

            // No check that the target is not the card itself: a settlement target is
            // always a cash account, and a card is not one, so the type check above
            // refuses that first. The form's `different:id` is reachable only because a
            // rule runs before the guard, and only to blame the target in the message.
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

        // Before the figure is compared: the total can be arithmetically right and
        // still not payable, since the issuer has not billed the pending rows.
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

        // The dialog pre-fills the due date and the server falls back to the same
        // thing, so there is one rule rather than a form that says one thing and an
        // endpoint another. Paid early, or long after it fell due, is what the field
        // is for.
        $paidOn = $figures['date'] ?? $figures['due_date'];

        DB::beginTransaction();

        try {
            // Remember where the card is paid from, when the dialog said somewhere else.
            // Inside the write, so a settlement cannot be recorded with the choice only
            // half applied -- and merged rather than written, because term_days and
            // statement_day share this row and a blind write would drop them, leaving a
            // card that produces no due dates at all.
            //
            // Only when it differs: the ordinary settle sends back the account the card
            // already names, and rewriting the row to its own value would touch every
            // card on every statement for nothing.
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
                'amount' => $owed,
                'ccy' => $account->ccy,
                'status' => TransactionStatus::Posted->value,
            ]);

            $transfer = Transaction::create([
                'account_id' => $bank->id,
                'category_id' => null,
                // The same day as the payment, as the same amount: a settlement is one
                // act, and two rows describing it differently would be two facts about
                // one event.
                'date' => $paidOn,
                'type' => TransactionType::Transfer->value,
                'description' => sprintf('Card payment [%s]', $account->name),
                'amount' => $owed,
                'ccy' => $account->ccy,
                'status' => TransactionStatus::Posted->value,
            ]);

            // Written here, not through the DTO, which drops the field: the ids do
            // not exist until both rows do. The transfer's bag carries no due_date, or a
            // bank would fall into a card's arithmetic.
            $payment->meta()->create([
                'meta' => ['due_date' => $figures['due_date'], 'paired_transaction_id' => $transfer->id],
            ]);

            $transfer->meta()->create([
                'meta' => ['paired_transaction_id' => $payment->id],
            ]);

            // Which payment paid each charge, so a charge says so on its own row. Inside
            // the write, so a settlement cannot exist with its charges unmarked. Merged,
            // because the bag also holds the due_date the statement groups on and any
            // card_amount it sums -- a blind write would drop the charge out of the very
            // bill being paid.
            $covered = Transaction::query()
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

            // Both rows or neither: a lone payment is a card that says it was paid
            // while the bank says the money is still there.
            report($e);

            return back()->with('message', 'error db...');
        }

        return back()->with('message', sprintf(
            'Card statement [%s] settled: %s %s',
            $figures['due_date'],
            $owed,
            $account->ccy
        ));
    }

    /**
     * Replace one statement period's due date with the day the bank stated.
     *
     * The day a period carries is a prediction, counted from the card's statement day and
     * term, and the bank's own day is not always that one. Every row in the period moves
     * together, because the due date is the key they are grouped by rather than a field
     * on any of them.
     *
     * Which periods may move is decided in CardStatement::moveDueDate(), beside the query
     * that defines the key, rather than here: a controller-side check would be a second
     * place to state what a period is.
     */
    public function moveDueDate(Account $account, Request $r)
    {
        $figures = $r->validate([
            'due_date' => ['required', 'date_format:Y-m-d'],
            'new_due_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $refuse = fn (string $message) => throw ValidationException::withMessages(['due_date' => $message]);

        // Said as its own refusal rather than left to the one below it, which would
        // report a savings account as having no statement due that day -- true, and no
        // help to somebody who clicked the wrong row.
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

        // Read the pairing, and the settlement's period, before anything is deleted:
        // both live in bags, and both bags are about to go.
        $pairedId = $transaction->meta?->meta?->getArrayCopy()['paired_transaction_id'] ?? null;
        $partner = $pairedId === null ? null : Transaction::with('meta')->find($pairedId);
        $period = $this->settlementPeriod($transaction, $partner);
        $trade = TradeCash::isTrade($transaction) ? TradeCash::describe($transaction) : null;

        // A settlement is two rows and must not come apart: deleting one half leaves a
        // card that says it was paid and a bank that says the money is still there. Both
        // go or neither does, and the browser has shown the user the other half first.
        // A pairing naming a row that is already gone resolves to nothing, and then the
        // single row is all there is to delete.
        //
        // Wrapped, because half a settlement is precisely the state the wrap exists to
        // prevent.
        $rows = $partner === null ? [$transaction] : [$transaction, $partner];

        DB::beginTransaction();

        try {
            foreach ($rows as $row) {
                // A deleted payment reopens its statement, so the charges stop claiming
                // it paid them. Left behind, the marker would name a row that no longer
                // exists on charges that are owing again.
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

        if ($trade !== null) {
            return back()->with('message', "Trade {$trade} deleted with its cash side: 2 transactions");
        }

        return back()->with('message', $period === null
            ? 'Card settlement deleted in full: 2 transactions'
            : sprintf('Card settlement [%s] deleted in full: 2 transactions', $period));
    }

    /**
     * Remove a payment's settled_by from every charge that names it.
     *
     * Found by the marker rather than by the payment's period, so a charge carrying it
     * is cleared wherever it has since been filed.
     */
    private function forgetSettlement(Transaction $payment): void
    {
        $bags = Meta::query()
            ->where('model_type', Transaction::class)
            ->where('meta->settled_by', $payment->id)
            ->get();

        foreach ($bags as $bag) {
            $bag->update(['meta' => Arr::except($bag->meta->getArrayCopy(), 'settled_by')]);
        }
    }

    /**
     * Why this row cannot be deleted, or null when it can.
     *
     * A charge in a settled statement. A settled period is the record of a bill that
     * was paid, and deleting one of its charges leaves the payment that closed it
     * explaining less than the money that left the bank -- or nothing at all: a period
     * with no charges and a payment on it reads as a credit, and the panel then offers a
     * settle button the server turns down.
     *
     * Derived rather than a column on the row. A period settles when its charges and
     * payments cancel, which no single row can know, so a status here could only be a
     * claim about the charge -- a user could mark it settled while its statement was
     * still owed.
     *
     * The message carries the way out, because a guard without one makes a mistake in a
     * paid statement uncorrectable for good: delete the payment that settled the period,
     * which reopens it, and the charge deletes normally. That is the same delete the
     * pairing below already takes as a pair.
     *
     * @param  Collection<int, CardStatement>|null  $cardPeriods  that card's periods,
     *                                                            read once by the caller
     */
    private function deleteRefusal(?Transaction $transaction, ?Collection $cardPeriods = null): ?string
    {
        if ($transaction?->type === TransactionType::Buy->value) {
            return $this->buyRefusal($transaction);
        }

        // The cash side of a trade goes with its trade and not on its own: deleting it
        // here would take the trade too, past the holdings check a buy's delete gets.
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

        // A row belongs to a statement only if the app filed it under one.
        $dueDate = $transaction->meta?->meta?->getArrayCopy()['due_date'] ?? null;

        if ($dueDate === null) {
            return null;
        }

        $cardPeriods ??= $this->cardPeriodsFor($transaction);

        $period = $cardPeriods?->firstWhere('dueDate', $dueDate);

        if ($period === null || ! $period->isSettled()) {
            return null;
        }

        return sprintf(
            'Charge [%s] is in the statement due %s, which has been settled, and cannot be deleted '
                .'on its own. Delete the payment that settled it first.',
            $transaction->description,
            $dueDate
        );
    }

    /**
     * Why deleting this buy is refused: a later sell would be selling shares no longer
     * held. Only a shortfall the delete causes, as in TransactionData::guardHoldings(),
     * and with the way out -- a buy can go once the sells after it are gone or smaller.
     *
     * A brokerage's trades are read once per request, since the index asks this of
     * every buy on the page.
     */
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
     * The refusals for a page of rows, keyed by id, so the browser can disable a button
     * rather than offer an action the server will turn down.
     *
     * The same answers destroy() gives, from the same method, so the sentence on the
     * button and the sentence in the message cannot drift. A disabled button is not
     * enforcement either way -- a stale page and a direct request both bypass it -- which
     * is why destroy() asks the same question again.
     *
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
     * The figure locks for a page of rows, keyed by id, for the edit form.
     *
     * TransactionData::figureLock() decides, and update() asks it again, so the form
     * and the refusal cannot disagree about which rows are fixed. The partner is the row
     * linkedCounterparts() found, handed over whole because figureLock() locks a
     * trade's cash differently from half a card settlement.
     *
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

    /**
     * One card's statement periods, for a row whose card this request has not read.
     *
     * The index reads them for every card on the page and passes them in, because that
     * query is the expensive one and re-running it per row is not free -- see
     * CardStatement on what it costs. This is the one-off read for a delete.
     */
    private function cardPeriodsFor(Transaction $transaction): ?Collection
    {
        $account = $transaction->account;

        if ($account?->type !== AccountType::Card->value) {
            return null;
        }

        return CardStatement::forAccount($account);
    }

    /**
     * The other half of each card settlement on a page of transactions, keyed by the
     * row that would delete it.
     *
     * destroy() removes both rows together, so the confirmation has to say so before
     * the user agrees. Keyed by id rather than carried on the row, because the
     * counterpart is a transaction of its own, on the card or on the bank, and is as
     * likely to be on another page of the list as on this one.
     *
     * Every field here is one the table already shows. A row named in a dialog has to
     * be recognisable against the row it was clicked from.
     *
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
            // Absent for an unpaired row, and for a pairing whose other half is gone:
            // neither has anything extra to delete, so neither has anything to warn about.
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
                // What the pair is, so the delete confirmation names it: a trade and
                // its cash, or the two halves of a card settlement.
                'kind' => TradeCash::isTrade($other) || TradeCash::isTrade($row) ? 'trade' : 'settlement',
                // For figureLock(), which needs the other row itself; not sent.
                'row' => $other,
            ];
        }

        return $linked;
    }

    /**
     * The due date naming the settlement these rows are, if either row carries one.
     *
     * The payment half does and the transfer's does not -- only the card side is filed
     * under a period, since a bank is not in any card's arithmetic. Read from whichever
     * row has it, or the same settlement would report itself differently depending on
     * which half was clicked.
     */
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
