<?php

namespace App\Support;

use App\DTO\TransactionMetaData;
use App\Enums\AccountType;
use App\Enums\Currency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Income, spending and money invested, per currency and month.
 *
 * Money moved between these accounts is neither. A card repayment's bank withdrawal is
 * left out, or every purchase would count twice -- once charged, once paid -- and a
 * trade's cash side is invested rather than spent. Both are told apart by the row the
 * bank row is paired with, so nothing has to be tagged by hand.
 *
 * A charge counts on its statement's due date, when the bank pays for it, or with
 * $onDueDate false on the day it was made. The due date is the default because it puts
 * card spending on the same clock as cash spending, the month the money left the bank.
 * Either way it is in the card's currency at its card_amount, the figure AccountBalance
 * sums. lastMonths()
 * reports each currency in its own money; combined() converts every row into the base
 * currency at its own day's rate.
 */
class CashFlow
{
    public const MONTHS = 12;

    /**
     * @return list<array{ccy: string, months: list<array<string, mixed>>, totals: array<string, string>}>
     */
    public static function lastMonths(Carbon $today, int $count = self::MONTHS, bool $onDueDate = true): array
    {
        return self::byCurrency(self::facts($today, $count, $onDueDate), $today, $count);
    }

    /**
     * Every currency in one report in the base currency, and the currencies left out for
     * having no rate on a row's day, so a total missing something says so.
     *
     * @return array{report: array{ccy: string, months: list<array<string, mixed>>, totals: array<string, string>}|null, unconverted: list<string>}
     */
    public static function combined(Carbon $today, int $count = self::MONTHS, bool $onDueDate = true): array
    {
        return self::inBase(self::facts($today, $count, $onDueDate), $today, $count);
    }

    /**
     * Both reports, and the currencies the base one left out, off one reading of the rows.
     *
     * The page shows the currencies side by side when one is picked and the combined one
     * when none is, but it needs the list of currencies either way -- so it needs both
     * readings always, and asking for them one at a time read a year of transactions twice.
     *
     * @return array{currencies: list<string>, by_currency: list<array{ccy: string, months: list<array<string, mixed>>, totals: array<string, string>}>, combined: array{report: array{ccy: string, months: list<array<string, mixed>>, totals: array<string, string>}|null, unconverted: list<string>}}
     */
    public static function both(Carbon $today, int $count = self::MONTHS, bool $onDueDate = true): array
    {
        $facts = self::facts($today, $count, $onDueDate);

        return [
            'currencies' => array_column($byCurrency = self::byCurrency($facts, $today, $count), 'ccy'),
            'by_currency' => $byCurrency,
            'combined' => self::inBase($facts, $today, $count),
        ];
    }

    /**
     * @param  list<array{kind: string, part: string|null, figure: BigDecimal, ccy: string, month: string, date: string, category: array{id: int|null, name: string|null}|null, one_off: bool}>  $facts
     * @return list<array{ccy: string, months: list<array<string, mixed>>, totals: array<string, string>}>
     */
    private static function byCurrency(array $facts, Carbon $today, int $count): array
    {
        $months = self::months($today, $count);
        $report = [];

        // Currency::cases() order, so the report reads the same way as every picker.
        foreach (self::aggregate($facts, null) as $ccy => $byMonth) {
            $report[$ccy] = self::currencyReport($ccy, $byMonth, $months);
        }

        return array_values(array_filter(array_map(
            fn (Currency $currency) => $report[$currency->value] ?? null,
            Currency::cases()
        )));
    }

    /**
     * @param  list<array{kind: string, part: string|null, figure: BigDecimal, ccy: string, month: string, date: string, category: array{id: int|null, name: string|null}|null, one_off: bool}>  $facts
     * @return array{report: array{ccy: string, months: list<array<string, mixed>>, totals: array<string, string>}|null, unconverted: list<string>}
     */
    private static function inBase(array $facts, Carbon $today, int $count): array
    {
        $fx = Fx::for(Account::query()->distinct()->pluck('ccy')->all());
        $unconverted = [];
        $book = self::aggregate($facts, $fx, $unconverted);
        $base = Fx::BASE->value;

        return [
            'report' => isset($book[$base]) ? self::currencyReport($base, $book[$base], self::months($today, $count)) : null,
            'unconverted' => array_values(array_unique($unconverted)),
        ];
    }

    /** @return list<Carbon> */
    private static function months(Carbon $today, int $count): array
    {
        $months = [];

        for ($n = $count - 1; $n >= 0; $n--) {
            $months[] = $today->copy()->startOfMonth()->subMonthsNoOverflow($n);
        }

        return $months;
    }

    /**
     * Every row that is money in or money out, with the kind and the figure it is.
     *
     * What a row is does not depend on which currency it is reported in, so this is done
     * once and handed to the readings below. The date is carried because the base currency's
     * reading converts at the rate on the row's own day, and a month's rate is not that.
     *
     * @return list<array{kind: string, part: string|null, figure: BigDecimal, ccy: string, month: string, date: string, category: array{id: int|null, name: string|null}|null, one_off: bool}>
     */
    private static function facts(Carbon $today, int $count, bool $onDueDate): array
    {
        $first = $today->copy()->startOfMonth()->subMonthsNoOverflow($count - 1);
        $last = $today->copy()->endOfMonth();

        // Read as plain rows, not models: a year is a couple of thousand of them, and
        // hydrating each with its bag, account and category was most of the page's time
        // -- four fifths of the forecast's -- for six columns and three keys of a bag.
        // The window is still countedSql(), so the list a month links to cannot drift.
        $rows = Transaction::query()
            ->whereHas('account', fn ($q) => $q->whereIn('type', self::accountTypes()))
            ->whereIn('status', TransactionStatus::countingTowardBalance())
            ->when(
                $onDueDate,
                fn (Builder $q) => $q
                    ->tap(fn (Builder $q) => self::whereCountedBetween($q, $first->toDateString(), $last->toDateString())),
                fn (Builder $q) => $q->whereBetween('date', [$first->toDateString(), $last->toDateString()]),
            )
            ->toBase()
            ->get(['id', 'account_id', 'category_id', 'type', 'amount', 'date', 'description']);

        $bags = self::bagsOf($rows->pluck('id')->all());
        $accounts = DB::table('accounts')->get(['id', 'type', 'ccy'])->keyBy('id');
        $categories = DB::table('categories')->pluck('name', 'id');
        $partners = self::partnersOf($rows, $bags);
        $transfers = self::transfersAmong($rows, $accounts);

        $facts = [];

        foreach ($rows as $row) {
            if (isset($transfers[$row->id]) || self::isExchange($row->description, $accounts[$row->account_id]->type)) {
                continue;
            }

            $bag = $bags[$row->id] ?? null;
            $account = $accounts[$row->account_id];
            $partner = $partners[$row->id] ?? null;
            $flow = self::classify($row, $account->type, $bag, $partner?->type, $partner === null ? null : $accounts[$partner->account_id]->type ?? null);

            if ($flow === null) {
                continue;
            }

            [$kind, $figure] = $flow;

            $facts[] = [
                'kind' => $kind,
                'part' => self::part($row->type, $account->type, $kind),
                'figure' => $figure,
                'ccy' => $account->ccy,
                'month' => substr($onDueDate ? self::countedOnDay($row->type, $row->date, $bag) : $row->date, 0, 7),
                'date' => $row->date,
                'category' => $kind === 'spending' ? ['id' => $row->category_id, 'name' => $categories[$row->category_id] ?? null] : null,
                'one_off' => (bool) ($bag['one_off'] ?? false),
            ];
        }

        return $facts;
    }

    /**
     * The day a row counts on by due date: a charge's due date, and any other row's own date. A charge
     * with no due date is on a card with no statement cycle, so its own date is all it has.
     */
    public static function countedOn(Transaction $row): string
    {
        return self::countedOnDay($row->type, $row->date, $row->meta?->meta?->getArrayCopy());
    }

    /** @param  array<string, mixed>|null  $bag */
    private static function countedOnDay(string $type, string $date, ?array $bag): string
    {
        $due = $type === TransactionType::Charge->value ? ($bag['due_date'] ?? null) : null;

        return $due ?? $date;
    }

    /**
     * Each row's bag, decoded, by transaction id. The first a row has, as morphOne() takes
     * it, so a row with two reads as it does everywhere else.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    public static function bagsOf(array $ids): array
    {
        $bags = [];

        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (DB::table('meta')->where('model_type', Transaction::class)->whereIn('model_id', $chunk)->orderBy('id')->get(['model_id', 'meta']) as $bag) {
                $bags[$bag->model_id] ??= json_decode($bag->meta, true) ?? [];
            }
        }

        return $bags;
    }

    /**
     * countedOn() in SQL, for the report's window and for the transactions list a month links
     * to: filtered on the date column instead, a charge due this month but made last month is
     * counted here and listed there.
     */
    public static function whereCounted(Builder $q, string $operator, string $day): Builder
    {
        if (! in_array($operator, ['>=', '<='], true)) {
            throw new \InvalidArgumentException("whereCounted() compares with >= or <=, not {$operator}.");
        }

        return $q->whereRaw(self::countedSql()." {$operator} ?", [Transaction::class, $day]);
    }

    /** whereCounted() at both ends, which reads the row's bag once rather than twice. */
    public static function whereCountedBetween(Builder $q, string $from, string $to): Builder
    {
        return $q->whereRaw(self::countedSql().' BETWEEN ? AND ?', [Transaction::class, $from, $to]);
    }

    /**
     * The day a row counts on, as one expression: a charge's due date where its bag has one,
     * and the row's own date otherwise. One subquery a row, where a whereHas() for the due
     * date and a whereDoesntHave() for its absence were two -- four for a window, over
     * every transaction there is, and most of the cash flow's query.
     *
     * A due date stored as JSON null is no due date, as Laravel's whereNotNull() on a JSON
     * path reads it: unquoted, it is the string "null", which sorts after every date.
     */
    private static function countedSql(): string
    {
        $due = "JSON_EXTRACT(m.meta, '$.\"due_date\"')";

        return "(CASE WHEN transactions.type = '".TransactionType::Charge->value."' THEN COALESCE("
            ."(SELECT CASE WHEN JSON_TYPE({$due}) != 'NULL' THEN JSON_UNQUOTE({$due}) END"
            .' FROM meta m WHERE m.model_id = transactions.id AND m.model_type = ?), transactions.date)'
            .' ELSE transactions.date END)';
    }

    /**
     * The facts by currency and month: each in its own currency's money, or with $fx, in the
     * base currency's at the rate on its own day.
     *
     * @param  list<array{kind: string, part: string|null, figure: BigDecimal, ccy: string, month: string, date: string, category: array{id: int|null, name: string|null}|null, one_off: bool}>  $facts
     * @param  list<string>  $unconverted  filled with the currency of every row left out
     * @return array<string, array<string, array<string, mixed>>>
     */
    private static function aggregate(array $facts, ?Fx $fx, array &$unconverted = []): array
    {
        $book = [];
        $rates = [];

        foreach ($facts as $fact) {
            $figure = $fact['figure'];
            $ccy = $fact['ccy'];

            if ($fx !== null) {
                $rate = $rates[$ccy][$fact['date']] ??= $fx->rate($ccy, $fact['date']);

                if ($rate === null) {
                    $unconverted[] = $ccy;

                    continue;
                }

                $figure = $figure->multipliedBy($rate)->toScale(TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp);
                $ccy = Fx::BASE->value;
            }

            $entry = &$book[$ccy][$fact['month']];
            $entry[$fact['kind']] = ($entry[$fact['kind']] ?? BigDecimal::zero())->plus($figure);

            if ($fact['part'] !== null) {
                $entry[$fact['part']] = ($entry[$fact['part']] ?? BigDecimal::zero())->plus($figure);
            }

            // Of each, what was marked one-off. Counted all the same, being money that moved;
            // the forecast takes it out of a typical month.
            if ($fact['one_off']) {
                foreach (array_filter([$fact['kind'], $fact['part']]) as $key) {
                    $entry["one_off_{$key}"] = ($entry["one_off_{$key}"] ?? BigDecimal::zero())->plus($figure);
                }
            }

            if ($fact['category'] !== null) {
                $key = $fact['category']['id'] ?? 0;
                $entry['categories'][$key] ??= [...$fact['category'], 'amount' => BigDecimal::zero()];
                $entry['categories'][$key]['amount'] = $entry['categories'][$key]['amount']->plus($figure);
            }

            unset($entry);
        }

        return $book;
    }

    /**
     * The account types whose rows move money a person holds, which is those with a
     * balance: a brokerage's side of a trade is counted from its bank row instead.
     *
     * @return list<string>
     */
    private static function accountTypes(): array
    {
        return array_values(array_map(
            fn (AccountType $type) => $type->value,
            array_filter(AccountType::cases(), fn (AccountType $type) => $type->hasBalance())
        ));
    }

    /**
     * A cash row the broker wrote as a currency exchange -- "HKD TO USD @ 4,988.85", "OFFSET
     * HKD" -- whose other side is in the brokerage's other currency and was never entered:
     * money changed, not spent or earned. Read off the wording because nothing else marks
     * it, with the currencies the enum names, so "OFFSET DIVIDEND" is not one.
     */
    public static function exchangePattern(): string
    {
        $codes = implode('|', array_column(Currency::cases(), 'value'));

        return "^(OFFSET ({$codes})|({$codes}) TO ({$codes}))( |$)";
    }

    private static function isExchange(?string $description, string $accountType): bool
    {
        return $accountType === AccountType::Cash->value
            && preg_match('/'.self::exchangePattern().'/i', (string) $description) === 1;
    }

    /**
     * The rows that are one half of money moved between these cash accounts: a withdrawal and
     * a deposit the same day, the same amount and currency -- on two accounts, a transfer, or
     * on one, an exchange recorded as out and straight back in. Neither is income or spending,
     * since the money did not leave, though nothing pairs them: each is entered as two plain
     * rows, and counted as they were, a transfer was spent on one side and earned on the
     * other, a million dollars of both over the ledger.
     *
     * Read as "an opposite row exists", not one-to-one, because whereSpends() states the same
     * rule in SQL and the two must not disagree; on the data here they match the same rows.
     *
     * @param  iterable<object{id: int, account_id: int, type: string, amount: string, date: string}>  $rows
     * @param  Collection<int, object{id: int, type: string, ccy: string}>  $accounts
     * @return array<int, true>
     */
    private static function transfersAmong(iterable $rows, $accounts): array
    {
        $sides = [TransactionType::Withdraw->value => TransactionType::Deposit->value, TransactionType::Deposit->value => TransactionType::Withdraw->value];
        $seen = [];
        $candidates = [];

        foreach ($rows as $row) {
            $account = $accounts[$row->account_id] ?? null;

            if ($account?->type !== AccountType::Cash->value || ! isset($sides[$row->type])) {
                continue;
            }

            $key = $row->date.'|'.BigDecimal::of($row->amount)->toScale(4).'|'.$account->ccy;
            $seen[$key][$row->type][$row->account_id] = true;
            $candidates[] = [$row, $key];
        }

        $transfers = [];

        foreach ($candidates as [$row, $key]) {
            if (isset($seen[$key][$sides[$row->type]])) {
                $transfers[$row->id] = true;
            }
        }

        return $transfers;
    }

    /**
     * Each paired row's partner, read in one query rather than one per row.
     *
     * @param  iterable<object{id: int}>  $rows
     * @param  array<int, array<string, mixed>>  $bags
     * @return array<int, object{id: int, type: string, account_id: int}>
     */
    private static function partnersOf(iterable $rows, array $bags): array
    {
        $pairs = [];

        foreach ($rows as $row) {
            $partner = $bags[$row->id]['paired_transaction_id'] ?? null;

            if ($partner !== null) {
                $pairs[$row->id] = (int) $partner;
            }
        }

        $found = DB::table('transactions')->whereIn('id', array_unique($pairs))->get(['id', 'type', 'account_id'])->keyBy('id');

        return array_filter(array_map(fn (int $id) => $found[$id] ?? null, $pairs));
    }

    /**
     * A row's kind and figure, or null for money that only moved between these accounts.
     * The direction is movesBalanceOn()'s, so a new type is classified rather than dropped.
     *
     * @param  array<string, mixed>|null  $bag
     * @return array{0: string, 1: BigDecimal}|null
     */
    private static function classify(object $row, string $accountType, ?array $bag, ?string $partnerType, ?string $partnerAccountType): ?array
    {
        $accountType = AccountType::from($accountType);
        $sign = TransactionType::from($row->type)->movesBalanceOn($accountType);

        if ($sign === 0) {
            return null;
        }

        $amount = BigDecimal::of($row->amount);

        if ($accountType === AccountType::Card) {
            // A payment is the card half of a repayment, or a refund; neither is income.
            if ($sign > 0) {
                return null;
            }

            $stated = $bag['card_amount'] ?? null;

            return ['spending', $stated === null ? $amount : BigDecimal::of($stated)];
        }

        if ($partnerType !== null && $partnerAccountType === AccountType::Card->value) {
            return null;
        }

        // Paired to a row on another cash account: a transfer, recorded as one, money that only
        // moved -- the way an exchange into another currency is, whose two sides are not the
        // same amount and so are never found by transfersAmong().
        if ($partnerType !== null && $partnerAccountType === AccountType::Cash->value) {
            return null;
        }

        // Signed, so a sell's proceeds take back what a buy put in.
        if ($partnerType !== null && TransactionType::from($partnerType)->derivesAmount()) {
            return ['invested', $sign < 0 ? $amount : $amount->negated()];
        }

        return $sign > 0 ? ['income', $amount] : ['spending', $amount];
    }

    /**
     * The rows this report counts as spending: a card's charge, and a bank withdrawal that is
     * not settling one.
     *
     * A hand-written mirror of classify(), like whereCounted() above, and for the same reason:
     * the transaction list needs to be able to reproduce a figure this report summed, and a
     * filter built from the same rule cannot drift from it the way a second rule would. The
     * one thing worth restating is the exclusion, because it is the half a type list cannot
     * express -- a card payment is a withdrawal on the bank and looks like any other. It is
     * left out by its partner's account type in classify(); a payment only ever exists on a
     * card, so a partner that is a payment row says the same thing here, in SQL.
     */
    public static function whereSpends(Builder $q): Builder
    {
        return $q->where(fn (Builder $either) => $either
            ->where('type', TransactionType::Charge->value)
            ->orWhere(fn (Builder $bank) => $bank
                ->where('type', TransactionType::Withdraw->value)
                ->whereNotIn('id', self::whereSettlesACard(DB::table('transactions')))
                // A buy's withdrawal is invested, not spent: classify() puts it there, and
                // left in it listed under No category with the month's real spending.
                ->whereNotIn('id', self::whereSettlesATrade(DB::table('transactions')))
                // Nor money only moved: paired to a row on another cash account, as classify()
                // reads a pair; an exchange the broker wrote, as isExchange() reads one; or one
                // half of a transfer nothing pairs, transfersAmong() as SQL.
                ->whereNotIn('id', DB::table('meta')
                    ->select('model_id')
                    ->where('model_type', Transaction::class)
                    ->whereIn('meta->paired_transaction_id', DB::table('transactions')
                        ->join('accounts', 'accounts.id', '=', 'transactions.account_id')
                        ->where('accounts.type', AccountType::Cash->value)
                        ->select('transactions.id')))
                ->whereRaw('NOT REGEXP_LIKE(COALESCE(transactions.description, \'\'), ?, \'i\')', [self::exchangePattern()])
                ->whereNotExists(fn (QueryBuilder $deposit) => $deposit
                    ->from('transactions as deposit')
                    ->join('accounts as deposit_account', 'deposit_account.id', '=', 'deposit.account_id')
                    ->join('accounts as own', 'own.id', '=', 'transactions.account_id')
                    ->where('own.type', AccountType::Cash->value)
                    ->where('deposit_account.type', AccountType::Cash->value)
                    ->whereColumn('deposit_account.ccy', 'own.ccy')
                    ->where('deposit.type', TransactionType::Deposit->value)
                    ->whereIn('deposit.status', TransactionStatus::countingTowardBalance())
                    ->whereColumn('deposit.date', 'transactions.date')
                    ->whereColumn('deposit.amount', 'transactions.amount'))));
    }

    /**
     * The rows behind one category's spending in one window -- the rows whose figures the
     * breakdown tile adds up, listed. The window is read as facts() reads it: by the day a
     * row counts on with $onDueDate, otherwise by its own date. A null category is the rows
     * with none.
     *
     * @return Builder<Transaction>
     */
    public static function spendingRows(string $from, string $to, bool $onDueDate, ?int $category): Builder
    {
        return Transaction::query()
            ->with(['account', 'category', 'meta'])
            ->whereHas('account', fn ($q) => $q->whereIn('type', self::accountTypes()))
            ->whereIn('status', TransactionStatus::countingTowardBalance())
            ->tap(fn (Builder $q) => self::whereSpends($q))
            ->when(
                $onDueDate,
                fn (Builder $q) => $q
                    ->tap(fn (Builder $q) => self::whereCountedBetween($q, $from, $to)),
                fn (Builder $q) => $q->whereBetween('date', [$from, $to]),
            )
            ->when(
                $category === null,
                fn (Builder $q) => $q->whereNull('category_id'),
                fn (Builder $q) => $q->where('category_id', $category),
            );
    }

    /** A spending row's figure: a card's own, as the report adds it, and a bank row's amount. */
    public static function spendingFigure(Transaction $row): BigDecimal
    {
        return BigDecimal::of((string) ($row->meta?->meta['card_amount'] ?? $row->amount));
    }

    /**
     * The ids of the bank-side rows that settle a card: a withdraw paired, in its bag, to a
     * payment on a card, which is the pair settle() writes.
     *
     * Its own method because the rule is wanted in two places for two reasons, and a
     * settlement's bank half is money that has already moved however far ahead its date
     * sits. Cash flow leaves it out so a purchase is not counted as spending twice; the
     * forecast leaves it out of what is coming up, where the same money already appears as
     * the statement event. Neither can restate it without the other drifting.
     *
     * Read off the bag rather than the type, because a settlement's bank row is a plain
     * withdraw and nothing about it says card. A payment only ever exists on a card, so
     * pairing to one is the same statement as joining its account for the type.
     *
     * @param  QueryBuilder<int, object>  $q
     * @return QueryBuilder<int, object>
     */
    public static function whereSettlesACard(QueryBuilder $q): QueryBuilder
    {
        return DB::table('meta')
            ->select('model_id')
            ->where('model_type', Transaction::class)
            ->whereIn('meta->paired_transaction_id', $q
                ->join('accounts', 'accounts.id', '=', 'transactions.account_id')
                ->select('transactions.id')
                ->where('transactions.type', TransactionType::Payment->value)
                ->where('accounts.type', AccountType::Card->value));
    }

    /**
     * The ids of the bank-side rows of a trade: the withdraw a buy takes money out of and the
     * deposit a sell pays into, found as whereSettlesACard() finds a settlement's, by the
     * partner in the bag. Read off the bag and not the description, which TradeCash writes
     * but a row's owner can edit.
     *
     * @param  QueryBuilder<int, object>  $q
     * @return QueryBuilder<int, object>
     */
    public static function whereSettlesATrade(QueryBuilder $q): QueryBuilder
    {
        return DB::table('meta')
            ->select('model_id')
            ->where('model_type', Transaction::class)
            ->whereIn('meta->paired_transaction_id', $q
                ->select('transactions.id')
                ->whereIn('transactions.type', [TransactionType::Buy->value, TransactionType::Sell->value]));
    }

    /**
     * The share of its kind a row is broken out as: a dividend of income, a card charge of
     * spending. The rest of each kind is the other share, taken by subtraction in
     * currencyReport() rather than summed, so the two always add up to the kind's figure.
     */
    private static function part(string $type, string $accountType, string $kind): ?string
    {
        return match (true) {
            $kind === 'income' && $type === TransactionType::Dividend->value => 'dividend',
            $kind === 'spending' && $accountType === AccountType::Card->value => 'card_spending',
            default => null,
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $byMonth
     * @param  list<Carbon>  $months
     * @return array{ccy: string, months: list<array<string, mixed>>, totals: array<string, string>}
     */
    private static function currencyReport(string $ccy, array $byMonth, array $months): array
    {
        $zero = BigDecimal::zero();
        $totals = array_fill_keys(['income', 'dividend', 'other_income', 'spending', 'card_spending', 'cash_spending', 'net', 'invested', 'one_off_income', 'one_off_dividend', 'one_off_spending', 'one_off_card_spending'], $zero);
        $rows = [];

        foreach ($months as $month) {
            $key = $month->format('Y-m');
            $entry = $byMonth[$key] ?? [];

            $figures = [
                'income' => $entry['income'] ?? $zero,
                'dividend' => $entry['dividend'] ?? $zero,
                'spending' => $entry['spending'] ?? $zero,
                'card_spending' => $entry['card_spending'] ?? $zero,
                'invested' => $entry['invested'] ?? $zero,
                'one_off_income' => $entry['one_off_income'] ?? $zero,
                'one_off_dividend' => $entry['one_off_dividend'] ?? $zero,
                'one_off_spending' => $entry['one_off_spending'] ?? $zero,
                'one_off_card_spending' => $entry['one_off_card_spending'] ?? $zero,
            ];
            $figures['other_income'] = $figures['income']->minus($figures['dividend']);
            $figures['cash_spending'] = $figures['spending']->minus($figures['card_spending']);
            $figures['net'] = $figures['income']->minus($figures['spending']);

            foreach ($totals as $name => $total) {
                $totals[$name] = $total->plus($figures[$name]);
            }

            $categories = array_values($entry['categories'] ?? []);
            usort($categories, fn (array $a, array $b) => $b['amount']->compareTo($a['amount']));

            $rows[] = [
                'month' => $key,
                'from' => $month->toDateString(),
                'to' => $month->copy()->endOfMonth()->toDateString(),
                ...array_map(fn (BigDecimal $figure) => self::money($figure), $figures),
                'categories' => array_map(fn (array $category) => [
                    ...$category,
                    'amount' => self::money($category['amount']),
                ], $categories),
            ];
        }

        return [
            'ccy' => $ccy,
            'months' => $rows,
            'totals' => array_map(fn (BigDecimal $total) => self::money($total), $totals),
        ];
    }

    private static function money(BigDecimal $value): string
    {
        return $value->toScale(TransactionMetaData::AMOUNT_SCALE)->toString();
    }
}
