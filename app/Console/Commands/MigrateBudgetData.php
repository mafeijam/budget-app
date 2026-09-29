<?php

namespace App\Console\Commands;

use App\DTO\TransactionData;
use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\Currency;
use App\Enums\Frequency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\CardStatementCycle;
use App\Support\Legacy\BudgetSource;
use App\Support\Legacy\LegacyDescription;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Moves the pre-Laravel `budget` database into this schema.
 *
 *     php artisan budget:migrate              # read the old data and report the plan
 *     php artisan budget:migrate --commit     # write it
 *
 * WHY A COMMAND AND NOT A SEEDER
 * -----------------------------
 * This is a person's budget, not fixtures, and it must not be reachable from
 * DatabaseSeeder, which runs against whatever database is configured. It reads a second
 * connection and writes the default one, refuses to run against a target that already
 * holds accounts, and reports everything it intends to do before touching anything.
 *
 * WHAT CHANGES SHAPE
 * ------------------
 * The old schema kept a paid date on a charge, a running balance on a cash row and a
 * holding at an average cost. This schema has none of those: a statement is a due_date
 * in the meta bag shared by its rows, a settlement is a payment row plus a paired
 * withdrawal on the bank, a balance is derived, and positions are replayed from trades.
 * So a charge becomes a row on a derived period, each paid period gains a payment, and a
 * holding becomes a buy.
 *
 * The one place the old data is richer than this schema is a foreign charge: its amount
 * column holds the HKD the card was billed and its description holds what was actually
 * spent. That maps exactly onto what guardCardAmount() insists on -- the charge's own
 * currency and amount, with the HKD figure as card_amount.
 */
class MigrateBudgetData extends Command
{
    protected $signature = 'budget:migrate
        {--commit : Write the rows. Without it this only reports what it would do.}';

    protected $description = 'Migrate the old budget database into this schema (dry run unless --commit)';

    /**
     * The statement closing day for each card, at the 26-day term below.
     *
     * The old schema stored neither, and the charges cannot supply them: 121 of MASTER's
     * rows fall on the 10th and 62 on the 6th, because they are recurring monthly bills
     * rather than daily spending, so the day a charge fell says nothing about the cycle.
     *
     * Each day below was found by deriving every period from it and keeping the one whose
     * due dates the recorded CARD PAYMENT rows settle best -- the payment landing on or
     * just after the due date, which is the direction money actually moves. The fit is
     * 15/17 periods for PLATINUM up to 98/101 for SC ASIA MILES, with mean lateness of
     * 1.7 to 3.5 days. It is not exact, and cannot be: the old grouping overlaps (charges
     * from 1 to 9 July 2019 sit under two different payment dates), so some periods were
     * never a clean cycle.
     *
     * @var array<string, int>
     */
    private const STATEMENT_DAY = [
        'SC ASIA MILES' => 9,
        'MASTER' => 28,
        'PLATINUM' => 14,
        'PREMIER' => 18,
        'SIGNATURE' => 8,
        'ADVANCE' => 12,
        'VISA' => 24,
    ];

    /**
     * The account holder's figure for every card. Where the derived dates and the
     * recorded payments disagree, this is what the card is assumed to have run on.
     */
    private const TERM_DAYS = 26;

    /** The old ADVANCE cash account and the ADVANCE card are both called it. */
    private const CARD_NAMES = ['ADVANCE' => 'ADVANCE Card'];

    /**
     * A brokerage for each old cash account that has stock trades recorded against it.
     *
     * The old schema had one FUTU row and traded on two accounts: ADVANCE carries the
     * 2017-2021 trades and FUTU the 2021-2026 ones, and each has its own dividends
     * arriving. A security account holds no balance and cannot take a deposit, so neither
     * can also be the brokerage, and a brokerage may only settle into one cash account --
     * which is why these are two rather than one account settling into both.
     *
     * @var array<string, string> old cash account name => brokerage name
     */
    private const BROKERAGES = [
        'ADVANCE' => 'ADVANCE Securities',
        'FUTU' => 'Futu Securities',
    ];

    private const PAYING_BANK = 'SAVING';

    /** stock_holding.group_num of the lot that is a grouping rather than a position. */
    private const SKIPPED_HOLDING_GROUP = 2;

    /** How far the two records may date one trade differently and still be the same trade. */
    private const TRADE_DATE_SLACK = 14;

    /** @var array<int, string>  old accounts.id => name */
    private array $oldAccounts = [];

    /** @var array<int, string>  old cards.id => name */
    private array $oldCards = [];

    /** @var list<array{name: string, type: string, meta: array<string, mixed>}> */
    private array $plannedAccounts = [];

    /** @var array<string, array<string, mixed>>  account name => its meta */
    private array $accountMeta = [];

    /** @var list<string> category names, in old id order */
    private array $plannedCategories = [];

    /** @var array<int, int>  old category id => position in plannedCategories */
    private array $categoryPositions = [];

    /** @var array<string, int>  account name => new id */
    private array $accountIds = [];

    /** @var array<int, int>  old category id => new id */
    private array $categoryIds = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, mixed> */
    private array $counts = [];

    public function handle(BudgetSource $source, LegacyDescription $parser): int
    {
        $this->guardSourceIsNotTarget();
        $this->guardTargetIsEmpty();

        $data = [
            'accounts' => $source->accounts(),
            'cards' => $source->cards(),
            'categories' => $source->categories(),
            'cardTransactions' => $source->cardTransactions(),
            'cashTransactions' => $source->cashTransactions(),
            'holdings' => $source->stockHoldings(),
            'recurring' => $source->recurringPayments(),
        ];

        foreach ($data['accounts'] as $account) {
            $this->oldAccounts[(int) $account['id']] = (string) $account['name'];
        }

        foreach ($data['cards'] as $card) {
            $this->oldCards[(int) $card['id']] = (string) $card['name'];
        }

        $this->banner($source, $data);

        $plan = $this->buildPlan($data, $parser);

        $this->report($plan);

        if (! $this->option('commit')) {
            $this->components->info('Dry run. Nothing was written. Re-run with --commit to apply.');

            return self::SUCCESS;
        }

        try {
            DB::transaction(fn () => $this->write($plan));
        } catch (Throwable $e) {
            $this->components->error('Rolled back: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Migrated into '.$this->targetName().'.');

        return self::SUCCESS;
    }

    /** The old database must not be the one being written, whatever the environment says. */
    private function guardSourceIsNotTarget(): void
    {
        if ($this->sourceName() === $this->targetName()) {
            throw new RuntimeException(
                'Refusing to run: the `budget` connection resolves to the same database as the '
                ."target ({$this->targetName()}).\n\nThis command reads that database and must "
                .'never write to it. Point BUDGET_DB_DATABASE at the old database and leave '
                .'DB_DATABASE alone.'
            );
        }
    }

    private function guardTargetIsEmpty(): void
    {
        if (Account::query()->exists()) {
            throw new RuntimeException(
                "Refusing to run: {$this->targetName()} already holds accounts.\n\n"
                .'This migration writes a whole budget in one go and would interleave with '
                ."whatever is there, so it expects an empty schema:\n"
                .'    DB_DATABASE=budget_v2_testing php artisan migrate:fresh'
            );
        }
    }

    private function sourceName(): string
    {
        return (string) Config::get('database.connections.budget.database');
    }

    private function targetName(): string
    {
        return (string) Config::get('database.connections.'.Config::get('database.default').'.database');
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $data
     * @return array<string, mixed>
     */
    private function buildPlan(array $data, LegacyDescription $parser): array
    {
        $this->planAccounts($data['accounts'], $data['cards']);
        $this->planCategories($data['categories']);

        $cash = $this->planCash($data['cashTransactions'], $parser);

        $dueDates = $this->canonicalDueDates($cash['payments'], $data['cardTransactions']);

        $charges = $this->planCharges($data['cardTransactions'], $parser, $dueDates);

        // Before the statements are planned, so the plan writes the merged periods rather
        // than the ones they replaced.
        $charges = $this->mergePeriodsPaidTogether($charges, $cash['payments']);

        $settlements = $this->planSettlements($cash['payments'], $charges);
        $trades = $this->planTrades($cash['trades'], $data['holdings'], $parser);
        $recurring = $this->planRecurring($data['recurring']);

        return [
            'cash' => $cash['rows'],
            'charges' => $charges,
            'settlements' => $settlements,
            'trades' => $trades,
            'recurring' => $recurring,
            'dividends' => $cash['dividends'],
            'splits' => $this->countPositive($data['cardTransactions'], 'split'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $old
     * @param  list<array<string, mixed>>  $cards
     */
    private function planAccounts(array $old, array $cards): void
    {
        foreach ($old as $account) {
            $this->planAccount((string) $account['name'], AccountType::Cash->value, []);
        }

        foreach (self::BROKERAGES as $settlesInto => $brokerage) {
            $this->planAccount($brokerage, AccountType::Security->value, ['settlement' => $settlesInto]);
        }

        foreach ($cards as $card) {
            $old = (string) $card['name'];
            $day = self::STATEMENT_DAY[$old] ?? null;

            if ($day === null) {
                $this->flag("Card [{$old}] has no statement day in STATEMENT_DAY, so it is skipped.");

                continue;
            }

            $this->planAccount($this->cardName($old), AccountType::Card->value, [
                'statement_day' => $day,
                'term_days' => self::TERM_DAYS,
                'settlement' => self::PAYING_BANK,
            ]);
        }
    }

    /** @param array<string, mixed> $meta */
    private function planAccount(string $name, string $type, array $meta): void
    {
        $this->plannedAccounts[] = ['name' => $name, 'type' => $type];
        $this->accountMeta[$name] = $meta;
    }

    /** @param list<array<string, mixed>> $old */
    private function planCategories(array $old): void
    {
        foreach ($old as $category) {
            $this->categoryPositions[(int) $category['id']] = count($this->plannedCategories);
            $this->plannedCategories[] = (string) $category['name'];
        }
    }

    /**
     * The old category id, or null when it names one this migration did not read.
     *
     * A null category is what the new schema wants anyway for a cash row: only a charge
     * requires one, and a charge whose category is missing would otherwise be filed
     * under nothing.
     */
    private function oldCategory(mixed $id): ?int
    {
        $id = (int) $id;

        return $id === 0 || ! isset($this->categoryPositions[$id]) ? null : $id;
    }

    /**
     * The cash ledger, split by what each row turns out to be.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows: list<array<string, mixed>>, payments: list<array<string, mixed>>, trades: list<array<string, mixed>>, dividends: array{typed: int, plain: int}}
     */
    private function planCash(array $rows, LegacyDescription $parser): array
    {
        $planned = $payments = $trades = [];
        $typed = $plain = 0;
        foreach ($rows as $row) {
            $account = $this->oldAccounts[(int) $row['acct_id']] ?? null;

            if ($account === null || ! in_array($account, $this->plannedNames(), true)) {
                $this->flag("Cash row [{$row['id']}] names an unmapped account, so it is skipped.");

                continue;
            }

            $description = trim((string) $row['description']);
            $amount = (string) $row['amount'];
            $date = (string) $row['date'];
            $type = (string) $row['type'];

            if ($card = $parser->paidCard($description)) {
                $payments[] = [
                    'card' => $this->cardName($card),
                    'date' => $date,
                    'amount' => $amount,
                ];

                continue;
            }

            if ($trade = $parser->trade($description, $amount)) {
                // The cash side is a real movement and stays as recorded. The trade goes on
                // the brokerage with no_cash, so the bank is not charged for it twice and
                // Positions can still replay it.
                $planned[] = $this->cashRow($account, $date, $description, $type, $amount, null);
                // The brokerage is the one settling into the cash account the ledger
                // recorded the trade on, since that is the account the money moved
                // through. Two of them, because the old ledger traded on two.
                $trades[] = $trade + ['date' => $date, 'brokerage' => $this->brokerageFor($account)];

                if (BigDecimal::of($trade['discrepancy'])->isPositive()) {
                    $this->flag(sprintf(
                        'Trade [%s] on %s: quantity x price leaves the cash %s out, kept as typed.',
                        $description,
                        $date,
                        $trade['discrepancy']
                    ));
                }

                continue;
            }

            if (str_starts_with(strtoupper($description), 'DIVIDEND')) {
                $symbol = $parser->dividendSymbol($description);

                // A dividend is typed as one wherever a brokerage settles into the account
                // it was paid into, which guardDividendBrokerage() requires and both of the
                // brokerage pairs satisfy.
                if ($symbol !== null && isset(self::BROKERAGES[$account])) {
                    $planned[] = $this->cashRow(
                        $account,
                        $date,
                        $description,
                        TransactionType::Dividend->value,
                        $amount,
                        null,
                        ['symbol' => $symbol, 'brokerage' => $this->brokerageFor($account)]
                    );
                    $typed++;

                    continue;
                }

                $plain++;
            }

            if ($leg = $parser->fxLeg($description)) {
                $this->flag(sprintf(
                    'FX row [%s] on %s moved %s %s. The OFFSET row paired with it stores the HKD '
                    .'equivalent rather than the %s amount, so both halves are left as recorded '
                    .'rather than one being guessed from the other.',
                    $description,
                    $date,
                    $leg['ccy'],
                    $leg['amount'],
                    $leg['ccy']
                ));
            } elseif ($parser->mentionsForeign($description)) {
                // A cash account has no card_amount, so a foreign withdrawal can only be
                // recorded as what left the bank, which is what this row holds.
                $this->counts['foreign_cash'] = ($this->counts['foreign_cash'] ?? 0) + 1;
            }

            $planned[] = $this->cashRow($account, $date, $description, $type, $amount, null);
        }

        return [
            'rows' => array_merge($this->openingBalances($rows), $planned),
            'payments' => $payments,
            'trades' => $trades,
            'dividends' => ['typed' => $typed, 'plain' => $plain],
        ];
    }

    /**
     * The money an account already held before its first recorded row.
     *
     * The old schema kept a running `balance` on every cash row, and on SAVING that column
     * starts 19,361.15 above the first row's own movement and stays exactly that far above
     * for nine years -- an opening balance the rows do not contain. ADVANCE and FUTU agree
     * with their rows to the cent, so this is one account, not a broken column.
     *
     * Without it the migrated balance is short by that much, and every figure built on it
     * -- net worth, the forecast, a card's share of it -- is short with it, with nothing on
     * the page to say why. Written as a deposit on the account's first recorded date, so it
     * sits where the ledger starts rather than inventing a day the ledger never mentions.
     *
     * A column that runs the other way is reported and not written: that would mean the
     * rows overstate the account, and no opening balance can explain money that left.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function openingBalances(array $rows): array
    {
        $sums = [];
        $recorded = [];
        $firstDate = [];

        foreach ($rows as $row) {
            $account = $this->oldAccounts[(int) $row['acct_id']] ?? null;

            if ($account === null) {
                continue;
            }

            $amount = (string) $row['amount'];
            $date = (string) $row['date'];

            $sums[$account] = BigDecimal::of($sums[$account] ?? '0')
                ->plus($row['type'] === 'deposit' ? $amount : BigDecimal::of($amount)->negated())
                ->toScale(4)->toString();

            // The rows come in id order and the column is a running total, so the last one
            // is the account's closing figure as the bank reported it.
            $recorded[$account] = (string) $row['balance'];

            if (! isset($firstDate[$account]) || $date < $firstDate[$account]) {
                $firstDate[$account] = $date;
            }
        }

        $openings = [];

        foreach ($recorded as $account => $closing) {
            $gap = BigDecimal::of($closing)->minus($sums[$account] ?? '0')->toScale(4)->toString();

            if (BigDecimal::of($gap)->isEqualTo(BigDecimal::zero())) {
                continue;
            }

            if (! BigDecimal::of($gap)->isPositive()) {
                $this->flag(sprintf(
                    'The old ledger records %s closing at %s but its rows only account for %s, so %s is more '
                    .'than the movements add up to. Nothing is written for it.',
                    $account,
                    $closing,
                    $sums[$account] ?? '0',
                    BigDecimal::of($gap)->abs()->toScale(2)->toString()
                ));

                continue;
            }

            $openings[] = $this->cashRow(
                $account,
                $firstDate[$account],
                'Opening balance',
                TransactionType::Deposit->value,
                $gap,
                null
            );

            $this->counts['opening_balances'] = ($this->counts['opening_balances'] ?? 0) + 1;
            $this->counts['opening_lines'][] = sprintf(
                '  %-10s %s  %12s  closing balance the rows did not account for',
                $account,
                $firstDate[$account],
                BigDecimal::of($gap)->toScale(2)->toString()
            );
        }

        return $openings;
    }

    /** @return array<string, mixed> */
    private function cashRow(string $account, string $date, string $description, string $type, string $amount, ?int $category, ?array $meta = null): array
    {
        return [
            'account' => $account,
            'date' => $date,
            'type' => $type,
            'description' => $description,
            'amount' => $amount,
            'category' => $category,
            'status' => TransactionStatus::Posted->value,
            'meta' => $meta,
        ];
    }

    /**
     * Each recorded period's key becomes the date its payment actually left the bank.
     *
     * The old schema filed a charge under the day its statement was *marked* paid, which
     * is not always the day the money moved: 10 of the 374 CARD PAYMENT rows sit a day or
     * three off the paid date on their charges. Keying the period on the payment instead
     * makes every payment match its own period exactly, so there is no near-miss matching
     * left to get wrong and a statement cannot end up billed to a day nothing was paid on.
     *
     * A paid date with no payment row keeps its own date, since there is nothing better to
     * key it on, and a payment already claimed by an earlier period is left alone: two
     * periods sharing one key would come back as a single bill.
     *
     * @param  list<array<string, mixed>>  $payments
     * @param  list<array<string, mixed>>  $charges
     * @return array<string, string> "card|old paid date" => the due date to use
     */
    private function canonicalDueDates(array $payments, array $charges): array
    {
        $byCard = [];

        foreach ($payments as $payment) {
            $byCard[$payment['card']][(string) $payment['date']] = true;
        }

        $paidDates = [];

        foreach ($charges as $row) {
            if ($row['paid'] === null) {
                continue;
            }

            $old = $this->oldCards[(int) $row['card_id']] ?? null;

            if ($old !== null) {
                $paidDates[$this->cardName($old)][] = (string) $row['paid'];
            }
        }

        $map = [];
        $claimed = [];

        foreach ($paidDates as $card => $dates) {
            // Oldest first, so a period claims the payment on its own date before a later
            // one can reach back for it. In the order the rows came in, SC ASIA MILES's
            // 2018-08-06 period took the 2018-08-05 payment that belonged to the period
            // before it, and merged two statements into one.
            $dates = array_values(array_unique($dates));
            sort($dates);

            foreach ($dates as $paid) {
                $key = $card.'|'.$paid;
                $map[$key] = $paid;

                $candidate = $this->nearestPayment($card, $paid, $byCard[$card] ?? [], $claimed);

                if ($candidate === null) {
                    $this->counts['periods_without_payment'] = ($this->counts['periods_without_payment'] ?? 0) + 1;

                    continue;
                }

                if ($candidate === $paid) {
                    $this->counts['periods_on_payment_date'] = ($this->counts['periods_on_payment_date'] ?? 0) + 1;
                } else {
                    $this->counts['periods_moved_to_payment'] = ($this->counts['periods_moved_to_payment'] ?? 0) + 1;
                }

                $map[$key] = $candidate;
                $claimed[$card.'|'.$candidate] = true;
            }
        }

        return $map;
    }

    /**
     * The payment nearest a period's recorded date, within a week, that no other period
     * has claimed.
     *
     * @param  array<string, true>  $available
     * @param  array<string, true>  $claimed
     */
    private function nearestPayment(string $card, string $paid, array $available, array $claimed): ?string
    {
        $best = null;
        $bestGap = PHP_INT_MAX;
        $on = Carbon::parse($paid);

        foreach (array_keys($available) as $date) {
            if (isset($claimed[$card.'|'.$date])) {
                continue;
            }

            $gap = (int) $on->diffInDays(Carbon::parse($date), false);

            if (abs($gap) > 7 || abs($gap) >= abs($bestGap)) {
                continue;
            }

            $bestGap = $gap;
            $best = $date;
        }

        return $best;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $dueDates
     * @return list<array<string, mixed>>
     */
    private function planCharges(array $rows, LegacyDescription $parser, array $dueDates): array
    {
        $charges = [];

        foreach ($rows as $row) {
            $old = $this->oldCards[(int) $row['card_id']] ?? null;
            $name = $old === null ? null : $this->cardName($old);

            if ($name === null || ! in_array($name, $this->plannedNames(), true)) {
                $this->flag("Card transaction [{$row['id']}] names an unmapped card, so it is skipped.");

                continue;
            }

            $hkd = (string) $row['amount'];
            $description = trim((string) $row['description']);
            $date = (string) $row['date'];

            // The period is keyed on the payment that settled it where the old ledger
            // recorded one, and on the card's own terms where it never was -- the charges
            // still owed, which open a period of their own.
            $paid = $row['paid'] === null ? null : (string) $row['paid'];
            $due = $paid === null
                ? $this->dueDateFor($name, $date)
                : ($dueDates[$name.'|'.$paid] ?? $paid);

            $meta = ['due_date' => $due];
            $credit = BigDecimal::of($hkd)->isNegative();
            $foreign = $credit ? null : $parser->foreignCharge($description, $hkd);

            if ($credit) {
                // A card holds only charges and payments and amount is a magnitude, so a
                // refund becomes a payment: it lowers what the period owes and the card's
                // balance, at the cost of reading as a payment on the panel.
                $this->noteCredit($date, $name, $hkd, $description);
            } elseif ($foreign !== null) {
                $meta['card_amount'] = $hkd;
                $this->counts['foreign'][$foreign['ccy']] = ($this->counts['foreign'][$foreign['ccy']] ?? 0) + 1;
            } elseif ($parser->mentionsForeign($description)) {
                $this->flag(sprintf('Charge [%s] on %s names a currency its figure could not be read from: %s', $row['id'], $date, $description));
            }

            $charges[] = [
                'account' => $name,
                'date' => $date,
                'type' => $credit ? TransactionType::Payment->value : TransactionType::Charge->value,
                'description' => $description,
                'amount' => $foreign['amount'] ?? BigDecimal::of($hkd)->abs()->toScale(4)->toString(),
                'hkd' => $hkd,
                'ccy' => $foreign['ccy'] ?? Currency::Hkd->value,
                'category' => $this->oldCategory($row['cat_id']),
                'status' => TransactionStatus::Posted->value,
                'meta' => $meta,
                // The old ledger's own assertion that this charge belonged to a statement
                // that was settled. A period where every charge says so was paid, whatever
                // the cash ledger turns out to record.
                'asserted' => $paid !== null,
            ];
        }

        return $charges;
    }

    /** The running total kept signed, and the magnitude reported at the end. */
    private function noteCredit(string $date, string $card, string $hkd, string $description): void
    {
        $this->counts['credits'] = ($this->counts['credits'] ?? 0) + 1;
        $this->counts['credit_total'] = BigDecimal::of((string) ($this->counts['credit_total'] ?? '0'))
            ->plus($hkd)->toScale(2)->toString();
        $this->counts['credit_lines'][] = sprintf(
            '  %s  %-13s %10s  %s',
            $date,
            $card,
            number_format((float) $hkd, 2),
            $description
        );
    }

    /**
     * One payment per old CARD PAYMENT row, matched to the period whose derived due date
     * it falls on. settled_by goes only on the payment that clears a period, which is the
     * rule the settle dialog itself follows.
     *
     * @param  list<array<string, mixed>>  $payments
     * @param  list<array<string, mixed>>  $charges
     * @return array<string, mixed>
     */
    private function planSettlements(array $payments, array $charges, array $merged = []): array
    {
        $owed = [];
        $credited = [];
        $asserted = [];

        foreach ($charges as $charge) {
            $key = $charge['account'].'|'.$charge['meta']['due_date'];

            // A period counts as settled only if every charge in it was filed under a paid
            // date. One charge the ledger never marked leaves the period genuinely open,
            // and it must not be swept into a transfer below.
            $asserted[$key] = ($asserted[$key] ?? true) && $charge['asserted'];

            if ($charge['type'] !== TransactionType::Charge->value) {
                // A credit is written as a payment, so CardStatement counts it as one and
                // it lowers what the period owes. Left out of the arithmetic here it would
                // make a settled period read as still owing and mark settled_by wrongly.
                $credited[$key] = BigDecimal::of($credited[$key] ?? '0.0000')
                    ->plus($charge['hkd'])->abs()->toScale(4)->toString();

                continue;
            }

            $owed[$key] = BigDecimal::of($owed[$key] ?? '0.0000')
                ->plus($charge['hkd'])->toScale(4)->toString();
        }

        // What each period owes net of its own credits, which is the figure a transfer
        // has to cover. Signed: an annual-fee reversal makes a period owe the other way,
        // and that is exactly what lets one transfer close several periods at once.
        $net = [];

        foreach ($owed as $key => $amount) {
            $net[$key] = BigDecimal::of($amount)->minus($credited[$key] ?? '0.0000')->toScale(4)->toString();
        }

        // A period is keyed on its payment's date, so a payment matches by equality now.
        // Nearest-match would be a second guess at a date canonicalDueDates() already
        // settled, and would quietly move a payment onto a bill it did not pay.
        $periods = array_fill_keys(array_merge(array_keys($owed), array_keys($credited)), true);

        $periods = array_fill_keys(array_merge(array_keys($owed), array_keys($credited)), true);

        // A period with a transfer on its own date is settled by it. So the backlog search
        // can tell a period still looking for a payment from one that already has one,
        // rather than offering up every period on the card.
        $ownPayment = [];

        foreach ($payments as $payment) {
            $ownPayment[$payment['card'].'|'.$payment['date']] = true;
        }

        $matched = [];
        $unmatched = [];
        $shared = [];
        $taken = [];

        foreach ($payments as $payment) {
            $own = $payment['card'].'|'.$payment['date'];

            // One transfer can settle several periods: the old ledger booked it against
            // one of them and left the rest owing for ever. Two shapes, tried in turn.
            //
            // Across cards on one day, which is a single transfer settling two statements
            // that closed together. And across dates on one card, which is a transfer
            // settling a backlog after the ledger stopped recording each payment against
            // the period it belonged to.
            //
            // Either way only periods the ledger itself marked paid are offered: one
            // whose charges were never marked is money genuinely outstanding, and only
            // where the figures add up to the cent.
            $spread = $this->spreadAcrossCards($payment, $own, $net, $periods, $asserted, $ownPayment)
                ?? $this->spreadAcrossDates($payment, $own, $net, $asserted, $ownPayment);

            if ($spread !== null) {
                $shared[] = $payment;

                foreach ($spread as $key => $amount) {
                    $taken[$key] = true;
                    $matched[$key]['paid'] = BigDecimal::of($matched[$key]['paid'] ?? '0.0000')
                        ->plus($amount)->toScale(4)->toString();
                    // array_merge, not `+`: the union keeps the left operand's amount,
                    // which is the whole transfer, so every period would be paid the lot
                    // and the report would claim a settlement the data does not have.
                    $matched[$key]['rows'][] = array_merge($payment, ['amount' => $amount]);
                }

                continue;
            }

            if (! isset($periods[$own])) {
                $unmatched[] = $payment;

                continue;
            }

            $taken[$own] = true;
            $matched[$own]['paid'] = BigDecimal::of($matched[$own]['paid'] ?? '0.0000')
                ->plus($payment['amount'])->toScale(4)->toString();
            $matched[$own]['rows'][] = $payment;
        }

        $settled = 0;
        $open = [];

        foreach (array_unique(array_merge(array_keys($owed), array_keys($credited))) as $key) {
            [$card, $period] = explode('|', $key, 2);

            $owedDecimal = BigDecimal::of($owed[$key] ?? '0.0000');
            $credit = BigDecimal::of($credited[$key] ?? '0.0000');
            $paid = BigDecimal::of($matched[$key]['paid'] ?? '0.0000')->plus($credit);
            $short = $owedDecimal->minus($paid);

            if ($short->isEqualTo(BigDecimal::zero())) {
                $settled++;

                continue;
            }

            $open[] = sprintf(
                '  %-13s due %s  owed %10s  paid %10s  %s %10s',
                $card,
                $period,
                $owedDecimal->toScale(2)->toString(),
                $paid->toScale(2)->toString(),
                $short->isNegative() ? 'over' : 'short',
                $short->abs()->toScale(2)->toString()
            );
        }

        return [
            'matched' => $matched,
            'unmatched' => $unmatched,
            'shared' => $shared,
            'owed' => $owed,
            'credited' => $credited,
            'settled' => $settled,
            'open' => $open,
        ];
    }

    /**
     * The periods one transfer settled across two cards closing on the same day.
     *
     * The old ledger filed the SC ASIA MILES charge of 2018-06-09 and the ADVANCE one
     * under the same paid date, then booked a single 563.40 transfer against ADVANCE --
     * the two charges added together. Left on one card, ADVANCE reads overdrawn by 223.20
     * and SC ASIA MILES owes it for ever, and neither figure is wrong on its own.
     *
     * @param  array<string, string>  $net
     * @param  array<string, true>  $periods
     * @param  array<string, bool>  $asserted
     * @return array<string, string>|null period key => the share of the payment
     */
    private function spreadAcrossCards(array $payment, string $own, array $net, array $periods, array $asserted, array $ownPayment): ?array
    {
        $spread = [];

        if (isset($net[$own]) && ($asserted[$own] ?? false)) {
            $spread[$own] = $net[$own];
        }

        foreach (array_keys($periods) as $key) {
            [$card, $period] = explode('|', $key, 2);

            if ($period !== $payment['date'] || isset($spread[$key]) || ! ($asserted[$key] ?? false) || ! isset($net[$key])) {
                continue;
            }

            $spread[$key] = $net[$key];
        }

        return $this->closesThem($spread, $payment, $this->cardsIn($spread));
    }

    /**
     * The periods one transfer settled as a backlog on a single card.
     *
     * VISA's charges for 2019-07-18, 2019-08-16 and 2019-11-18 were each filed under the
     * date the ledger last recorded a payment, which left the first two owing -232.00 and
     * 227.99 -- and the third short by 4.01. The three net to 35.96, which is exactly the
     * 2019-11-18 transfer: one payment settling all three, recorded against the last.
     *
     * @param  array<string, string>  $net
     * @param  array<string, true>  $periods
     * @param  array<string, bool>  $asserted
     * @return array<string, string>|null
     */
    private function spreadAcrossDates(array $payment, string $own, array $net, array $asserted, array $ownPayment): ?array
    {
        $spread = [];

        if (isset($net[$own]) && ($asserted[$own] ?? false)) {
            $spread[$own] = $net[$own];
        }

        foreach ($net as $key => $amount) {
            [$card, $period] = explode('|', $key, 2);

            if ($card !== $payment['card'] || isset($spread[$key]) || ! ($asserted[$key] ?? false)) {
                continue;
            }

            // Only periods still looking for a payment, and only those that owe something:
            // a period netting nothing shares no transfer, and counting it would let any
            // payment "add up" to a period it never touched.
            if (isset($ownPayment[$key]) || BigDecimal::of($amount)->isZero()) {
                continue;
            }

            $spread[$key] = $amount;
        }

        return $this->closesThem($spread, $payment, $this->cardsIn($spread));
    }

    /** @param array<string, string> $spread */
    private function describeSpread(array $spread): string
    {
        $parts = [];

        foreach ($spread as $key => $amount) {
            $parts[] = $key.' owing '.$amount;
        }

        return implode('; ', $parts);
    }

    /**
     * Fold statements that one transfer settled into that transfer's own period.
     *
     * VISA's 2019-07-18, 2019-08-16 and 2019-11-18 carry a 300 annual-fee reversal against
     * 68 of charges, 227.99 and 39.97. Together they owe 35.96, which is exactly the
     * 2019-11-18 transfer: one payment settling all three, filed against the last.
     *
     * Splitting the payment between them cannot work, because the first owes the other
     * way and a payment is a magnitude. Merging them can: the reversal then offsets the
     * charges inside a single period, where a credit belongs, and one payment of 35.96
     * closes it. The three were settled together, so showing them as the one bill that was
     * paid is what happened.
     *
     * @param  list<array<string, mixed>>  $charges
     * @param  list<array<string, mixed>>  $payments
     * @return list<array<string, mixed>>
     */
    private function mergePeriodsPaidTogether(array $charges, array $payments): array
    {
        $ownPayment = [];

        foreach ($payments as $payment) {
            $ownPayment[$payment['card'].'|'.$payment['date']] = true;
        }

        $owed = [];
        $asserted = [];

        foreach ($charges as $charge) {
            $key = $charge['account'].'|'.$charge['meta']['due_date'];
            $asserted[$key] = ($asserted[$key] ?? true) && $charge['asserted'];

            if ($charge['type'] !== TransactionType::Charge->value) {
                // hkd carries its own sign, so a credit is added as it stands. Subtracting
                // it would turn VISA's -300.00 annual-fee reversal into +300.00 and leave
                // the period looking like it owed 368.00 rather than being owed 232.00.
                $owed[$key] = BigDecimal::of($owed[$key] ?? '0.0000')
                    ->plus($charge['hkd'])->toScale(4)->toString();

                continue;
            }

            $owed[$key] = BigDecimal::of($owed[$key] ?? '0.0000')
                ->plus($charge['hkd'])->toScale(4)->toString();
        }

        $moves = [];

        foreach ($payments as $payment) {
            $own = $payment['card'].'|'.$payment['date'];
            $spread = [];

            if (isset($owed[$own]) && ($asserted[$own] ?? false)) {
                $spread[$own] = $owed[$own];
            }

            foreach ($owed as $key => $amount) {
                [$card, $period] = explode('|', $key, 2);

                if ($card !== $payment['card'] || isset($spread[$key]) || ! ($asserted[$key] ?? false) || isset($ownPayment[$key])) {
                    continue;
                }

                if (! BigDecimal::of($amount)->isZero()) {
                    $spread[$key] = $amount;
                }
            }

            if (count($spread) < 2) {
                continue;
            }

            $total = BigDecimal::zero();
            $anyNegative = false;

            foreach ($spread as $amount) {
                $total = $total->plus($amount);
                $anyNegative = $anyNegative || ! BigDecimal::of($amount)->isPositive();
            }

            // Only where a credit is involved, which is what makes a split impossible and
            // a merge the only way through. Everything else is left to the split below.
            if (! $anyNegative || ! $total->isEqualTo(BigDecimal::of($payment['amount']))) {
                continue;
            }

            foreach (array_keys($spread) as $key) {
                if ($key !== $own) {
                    $moves[$key] = $own;
                }
            }

            $this->counts['merged_periods'] = ($this->counts['merged_periods'] ?? 0) + count($spread) - 1;

            $this->flag(sprintf(
                'The %s transfer of %s settles %d statements at once (%s). One of them carries a credit larger '
                .'than its charges, so the transfer cannot be split between them; they are shown as the one '
                .'bill that was actually paid.',
                $payment['date'],
                $payment['amount'],
                count($spread),
                implode(', ', array_map(fn (string $key) => explode('|', $key)[1], array_keys($spread)))
            ));
        }

        if ($moves === []) {
            return $charges;
        }

        foreach ($charges as $index => $charge) {
            $from = $charge['account'].'|'.$charge['meta']['due_date'];

            if (! isset($moves[$from])) {
                continue;
            }

            $charges[$index]['meta']['due_date'] = explode('|', $moves[$from])[1];
        }

        return $charges;
    }

    /**
     * @param  array<string, string>  $spread
     * @param  list<string>  $cards
     * @return array<string, string>|null
     */
    private function closesThem(array $spread, array $payment, array $cards): ?array
    {
        // Two or more periods that actually owe something: with one, a payment that adds
        // up is the ordinary case and there is nothing to share.
        if (count($spread) < 2) {
            return null;
        }

        $total = BigDecimal::zero();

        foreach ($spread as $amount) {
            // A period owing the other way cannot take a share. amount is a magnitude and a
            // payment is one, so the share would have to be a negative row -- which is how
            // a card refund crossed into the ledger as a credit nothing can hold. Such a
            // period is left as it stands and reported.
            if (! BigDecimal::of($amount)->isPositive()) {
                $this->counts['blocked_splits'] = ($this->counts['blocked_splits'] ?? 0) + 1;

                $this->flag(sprintf(
                    'The %s transfer of %s would settle %d periods that add up to it, but one of them owes the '
                    .'other way. A payment is a magnitude, so it cannot carry a negative share, and the periods '
                    .'are left standing: %s.',
                    $payment['date'],
                    $payment['amount'],
                    count($spread),
                    $this->describeSpread($spread)
                ));

                return null;
            }

            $total = $total->plus($amount);
        }

        if (! $total->isEqualTo(BigDecimal::of($payment['amount']))) {
            return null;
        }

        $this->counts['shared_payments'] = ($this->counts['shared_payments'] ?? 0) + 1;

        $this->flag(sprintf(
            'The %s transfer of %s is booked against one period in the old ledger but equals %d periods of '
            .'%s to the cent (%s), so it is split between them rather than left on one.',
            $payment['date'],
            $payment['amount'],
            count($spread),
            implode(' and ', $cards),
            implode(', ', array_map(fn (string $key) => explode('|', $key)[1], array_keys($spread)))
        ));

        return $spread;
    }

    /**
     * @param  array<string, string>  $spread
     * @return list<string>
     */
    private function cardsIn(array $spread): array
    {
        return array_values(array_unique(array_map(fn (string $key) => explode('|', $key)[0], array_keys($spread))));
    }

    /**
     * Buys and sells, from the cash ledger where it recorded them in words and from
     * stock_holding where it did not.
     *
     * The cash ledger is the better record and is preferred wherever both describe the
     * same day: it carries the quantity and the price, and stock_holding does not. It
     * still shows the 2022 Tracker Fund as open when the cash rows sell it on 2025-02-28,
     * and it holds 400 units of 4239 at a cost of 100 where the cash row bought 40,000
     * at 1.
     *
     * So a holding is backdated into the ledger only where the cash never described the
     * purchase at all -- 19 of them, from 2016 and 2017, before the ledger started writing
     * trades out in words. Those keep the holding's own date, quantity and cost.
     *
     * @param  list<array<string, mixed>>  $fromCash
     * @param  list<array<string, mixed>>  $holdings
     * @return list<array<string, mixed>>
     */
    private function planTrades(array $fromCash, array $holdings, LegacyDescription $parser): array
    {
        $trades = $fromCash;

        // A holding the cash ledger never described belongs to whichever brokerage was
        // trading first. The 2016-2017 holdings predate the Futu ledger and were bought
        // through the ADVANCE one, so they go there rather than to a default.
        $fallback = $this->earliestBrokerage($fromCash);

        // Cash trades still available to match a holding to, keyed by type, symbol and
        // quantity. Keying on the quantity is what lets the window be generous: two trades
        // in a fortnight for one symbol at different sizes are not the same trade, and
        // 6823.HK is held three times over, at 3,000, 3,000 and 5,000.
        $available = [];
        $anySize = [];

        foreach ($fromCash as $index => $trade) {
            $size = BigDecimal::of($trade['quantity'])->toScale(0)->toString();
            $entry = ['index' => $index, 'date' => $trade['date'], 'size' => $size];

            $available[$trade['type']][$trade['symbol']][$size][$index] = $entry;
            $anySize[$trade['type']][$trade['symbol']][$index] = $entry;
        }

        foreach ($holdings as $holding) {
            $symbol = $parser->symbol((string) $holding['code']);
            $bought = (string) $holding['date'];
            $size = BigDecimal::of((string) $holding['qty'])->toScale(0)->toString();

            // The second lot, group_num 2: the 2021-06-25 batch the account holder filed
            // as a group of its own. It is a grouping, not a position, and it is left out
            // rather than replayed -- though every one of it that the cash ledger described
            // is a trade either way, so what this actually drops is small.
            if ((int) $holding['group_num'] === self::SKIPPED_HOLDING_GROUP) {
                $this->counts['holdings_skipped'] = ($this->counts['holdings_skipped'] ?? 0) + 1;

                continue;
            }

            // The cash ledger wins wherever it describes the same trade. Matching the date
            // exactly is what let 1270.HK's 2017-03-07 holding be backdated on top of the
            // 2017-03-09 cash row for the same 15,000 shares, leaving a position twice its
            // size and a cost to match. A window alone is not enough either: the two
            // records are 8 to 10 days apart for the holdings of 2019-01-31.
            if ($this->claimedInCash('buy', $symbol, $size, $bought, $available, $anySize)) {
                continue;
            }

            $trades[] = [
                'type' => 'buy',
                'symbol' => $symbol,
                'quantity' => BigDecimal::of((string) $holding['qty'])->toScale(8)->toString(),
                'unit_price' => BigDecimal::of((string) $holding['cost'])->toScale(4)->toString(),
                'fees' => '0.0000',
                'discrepancy' => '0.0000',
                'date' => $bought,
                'brokerage' => $fallback,
            ];

            $this->counts['trades_from_holdings'] = ($this->counts['trades_from_holdings'] ?? 0) + 1;

            $sold = $holding['sold'] === null ? null : (string) $holding['sold'];

            if ($sold === null || $this->claimedInCash('sell', $symbol, $size, $sold, $available, $anySize)) {
                continue;
            }

            // A closed holding the cash ledger never described, so there is no price for
            // it. Reported rather than invented: a made-up sell price would quietly
            // restate what the position was worth.
            $this->flag(sprintf(
                'Holding %s bought %s was sold %s with no price in the cash ledger, so the sale is not migrated.',
                $symbol,
                $bought,
                $sold
            ));
        }

        usort($trades, fn (array $a, array $b) => [$a['date'], $a['symbol']] <=> [$b['date'], $b['symbol']]);

        $this->counts['trades_from_cash'] = count($fromCash);

        foreach ($trades as $trade) {
            $brokerage = $trade['brokerage'] ?? 'unassigned';
            $this->counts['trades_by_brokerage'][$brokerage] = ($this->counts['trades_by_brokerage'][$brokerage] ?? 0) + 1;
        }

        return $trades;
    }

    /**
     * Whether the cash ledger already records this trade, claiming the nearest match so two
     * holdings cannot both lay claim to one cash row.
     *
     * The two records disagree on the date, and by more than a day or two: the holding table
     * dates 1288.HK's sale to 2017-03-06 against 2017-03-08 in the cash ledger, and files
     * the holdings of 2019-01-31 against cash rows of 2019-02-08. Matching exactly reported
     * a price that had in fact migrated, and -- worse -- backdated holdings on top of the
     * cash rows for the same shares, leaving positions twice their size.
     *
     * The quantity is matched as well as the date, which is what makes a fortnight's window
     * safe: the same symbol bought twice in a fortnight at two sizes is two trades. Where no
     * cash row of that size exists, a row of any size near the date is taken instead --
     * 4239.HK is held as 400 at a cost of 100 where the cash ledger bought 40,000 at 1, the
     * same 40,000 recorded in different units, and matching on size alone left a phantom
     * 400 shares open against a position the ledger had already sold.
     *
     * @param  array<string, array<string, array<string, list<array{index: int, date: string, size: string}>>>>  $available
     * @param  array<string, array<string, array<int, array{index: int, date: string, size: string}>>>  $anySize
     */
    private function claimedInCash(string $type, string $symbol, string $size, string $date, array &$available, array &$anySize): bool
    {
        $on = Carbon::parse($date);

        $best = $this->nearestTrade($available[$type][$symbol][$size] ?? [], $on);

        if ($best !== null) {
            unset($available[$type][$symbol][$size][$best]);

            return true;
        }

        $loose = $this->nearestTrade($anySize[$type][$symbol] ?? [], $on);

        if ($loose === null) {
            return false;
        }

        $size = $anySize[$type][$symbol][$loose]['size'];

        unset($anySize[$type][$symbol][$loose], $available[$type][$symbol][$size][$loose]);

        $this->counts['holdings_matched_on_date'] = ($this->counts['holdings_matched_on_date'] ?? 0) + 1;

        return true;
    }

    /**
     * The key of the trade nearest a date, within the window.
     *
     * @param  array<array-key, array{date: string}>  $candidates
     */
    private function nearestTrade(array $candidates, Carbon $on): int|string|null
    {
        $best = null;
        $bestGap = PHP_INT_MAX;

        foreach ($candidates as $key => $candidate) {
            $gap = abs($on->diffInDays(Carbon::parse($candidate['date']), false));

            if ($gap > self::TRADE_DATE_SLACK || $gap >= $bestGap) {
                continue;
            }

            $bestGap = $gap;
            $best = $key;
        }

        return $best;
    }

    /**
     * The brokerage whose ledger records the earliest trade, which is where a holding the
     * cash ledger never described is taken to have been bought.
     *
     * @param  list<array<string, mixed>>  $fromCash
     */
    private function earliestBrokerage(array $fromCash): string
    {
        $earliest = null;
        $brokerage = (string) array_key_first(self::BROKERAGES);

        foreach ($fromCash as $trade) {
            if ($earliest !== null && $trade['date'] >= $earliest) {
                continue;
            }

            $earliest = $trade['date'];
            $brokerage = $trade['brokerage'] ?? $brokerage;
        }

        return $brokerage;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function planRecurring(array $rows): array
    {
        $rules = [];

        foreach ($rows as $row) {
            $old = $this->oldCards[(int) $row['card_id']] ?? null;
            $name = $old === null ? null : $this->cardName($old);

            if ($name === null || ! in_array($name, $this->plannedNames(), true)) {
                $this->flag("Recurring rule [{$row['description']}] names an unmapped card, so it is skipped.");

                continue;
            }

            $day = (int) $row['day'];
            $next = today()->startOfMonth();

            if ($next->copy()->day($day)->isPast()) {
                $next = $next->addMonthNoOverflow();
            }

            $rules[] = [
                'account' => $name,
                'category' => $this->oldCategory($row['cat_id']),
                'type' => TransactionType::Charge->value,
                'description' => (string) $row['description'],
                'amount' => (string) $row['amount'],
                'frequency' => Frequency::Monthly->value,
                // The old rule has no start date, and starting it at the beginning of the
                // record would make the next run write a pending row for every month since.
                'start_date' => $next->toDateString(),
            ];

            if ((int) $row['paid'] !== 0) {
                $this->counts['recurring_paid_column'] = true;
            }
        }

        return $rules;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function countPositive(array $rows, string $column): int
    {
        $count = 0;

        foreach ($rows as $row) {
            if (BigDecimal::of((string) $row[$column])->isPositive()) {
                $count++;
            }
        }

        return $count;
    }

    /** @return list<string> */
    private function plannedNames(): array
    {
        return array_column($this->plannedAccounts, 'name');
    }

    private function cardName(string $old): string
    {
        return self::CARD_NAMES[$old] ?? $old;
    }

    /** The brokerage settling into an old cash account, or null where there is none. */
    private function brokerageFor(string $cashAccount): ?string
    {
        return self::BROKERAGES[$cashAccount] ?? null;
    }

    private function cycleFor(string $card): CardStatementCycle
    {
        $meta = $this->accountMeta[$card];

        return new CardStatementCycle((int) $meta['statement_day'], (int) $meta['term_days']);
    }

    private function dueDateFor(string $card, string $chargeDate): string
    {
        return $this->cycleFor($card)->dueDateFor(Carbon::parse($chargeDate))->toDateString();
    }

    /** Not warn(): that is Command::warn(), which prints, and these are collected. */
    private function flag(string $message): void
    {
        $this->warnings[] = $message;
    }

    /** @param array<string, list<array<string, mixed>>> $data */
    private function banner(BudgetSource $source, array $data): void
    {
        $this->components->twoColumnDetail('Reading from', $this->sourceName());
        $this->components->twoColumnDetail('Writing to', $this->targetName());
        $this->components->twoColumnDetail('Source last written', $source->latestActivity() ?? 'unknown');
        $this->components->twoColumnDetail('Rows read', sprintf(
            '%d accounts, %d cards, %d categories, %d card charges, %d cash rows, %d holdings, %d recurring',
            count($data['accounts']),
            count($data['cards']),
            count($data['categories']),
            count($data['cardTransactions']),
            count($data['cashTransactions']),
            count($data['holdings']),
            count($data['recurring'])
        ));
    }

    /** @param array<string, mixed> $plan */
    private function report(array $plan): void
    {
        $settlements = $plan['settlements'];

        $this->newLine();
        $this->components->info('Accounts and categories');
        $this->components->twoColumnDetail('Accounts to create', (string) count($this->plannedAccounts));
        $this->components->twoColumnDetail('Categories to create', (string) count($this->plannedCategories));

        $this->newLine();
        $this->components->info('Cash ledger');
        $this->components->twoColumnDetail('Rows carried over', (string) count($plan['cash']));
        $this->components->twoColumnDetail('Dividends typed as dividends', (string) $plan['dividends']['typed']);
        $this->components->twoColumnDetail('Dividends left as deposits', (string) $plan['dividends']['plain']);
        $this->components->twoColumnDetail('Rows naming a foreign currency', (string) ($this->counts['foreign_cash'] ?? 0));
        $this->components->twoColumnDetail('Opening balances added', (string) ($this->counts['opening_balances'] ?? 0));

        foreach ($this->counts['opening_lines'] ?? [] as $line) {
            $this->line($line);
        }

        $this->newLine();
        $this->components->info('Card statements');
        $this->components->twoColumnDetail('Charges and credits', (string) count($plan['charges']));
        $this->components->twoColumnDetail('Periods keyed on their payment date', (string) ($this->counts['periods_on_payment_date'] ?? 0));
        $this->components->twoColumnDetail('Periods moved onto a nearby payment', (string) ($this->counts['periods_moved_to_payment'] ?? 0));
        $this->components->twoColumnDetail('Periods with no payment recorded', (string) ($this->counts['periods_without_payment'] ?? 0));
        $this->components->twoColumnDetail('Payments matched to a period', (string) count($settlements['matched']));
        $this->components->twoColumnDetail('Payments with no period found', (string) count($settlements['unmatched']));
        $this->components->twoColumnDetail('Periods fully settled', (string) $settlements['settled']);
        $this->components->twoColumnDetail('Periods still owing', (string) count($settlements['open']));

        foreach ($this->counts['foreign'] ?? [] as $ccy => $count) {
            $this->components->twoColumnDetail("Charges in {$ccy}", (string) $count);
        }

        $this->newLine();
        $this->components->info('Stocks');
        $this->components->twoColumnDetail('Trades from the cash ledger', (string) ($this->counts['trades_from_cash'] ?? 0));
        $this->components->twoColumnDetail('Buys backdated from stock_holding', (string) ($this->counts['trades_from_holdings'] ?? 0));
        $this->components->twoColumnDetail('Holdings skipped (group '.self::SKIPPED_HOLDING_GROUP.')', (string) ($this->counts['holdings_skipped'] ?? 0));

        foreach ($this->counts['trades_by_brokerage'] ?? [] as $brokerage => $count) {
            $this->components->twoColumnDetail("  on {$brokerage}", (string) $count);
        }

        $this->newLine();
        $this->components->info('Also');
        $this->components->twoColumnDetail('Recurring rules', (string) count($plan['recurring']));
        $this->components->twoColumnDetail('Credits written as payments', (string) ($this->counts['credits'] ?? 0));
        $this->components->twoColumnDetail('Instalment splits dropped', (string) $plan['splits']);

        if ($this->counts['credit_lines'] ?? []) {
            $this->newLine();
            $this->components->warn(sprintf(
                '%d charges were negative -- refunds, rebates and annual-fee reversals, %s HKD in all. A card has no '
                .'refund type and amount is a magnitude, so each is written as a payment, which lowers what the '
                .'period owes and the card\'s balance but is shown under "paid":',
                $this->counts['credits'],
                BigDecimal::of((string) $this->counts['credit_total'])->abs()->toScale(2)->toString()
            ));
            $this->line(implode(PHP_EOL, $this->counts['credit_lines']));
        }

        if ($settlements['open'] !== []) {
            $this->newLine();
            $this->components->warn(sprintf('%d periods are not fully paid and will show on Home:', count($settlements['open'])));
            $this->line(implode(PHP_EOL, array_slice($settlements['open'], 0, 25)));
            if (count($settlements['open']) > 25) {
                $this->line(sprintf('  ... and %d more', count($settlements['open']) - 25));
            }
        }

        if ($this->warnings !== []) {
            $this->newLine();
            $this->components->warn(sprintf('%d rows need a look:', count($this->warnings)));
            $this->line(implode(PHP_EOL, array_map(fn (string $w) => '  '.$w, $this->warnings)));
        }
    }

    /** @param array<string, mixed> $plan */
    private function write(array $plan): void
    {
        foreach ($this->plannedAccounts as $definition) {
            $account = Account::create([
                'name' => $definition['name'],
                'type' => $definition['type'],
                'ccy' => Currency::Hkd->value,
                'status' => AccountStatus::Active->value,
            ]);

            $this->accountIds[$definition['name']] = $account->id;
        }

        $this->step('accounts', count($this->accountIds));

        // A brokerage can only point at a row that exists, and ids are not known until
        // the rows are written, so the settlement link is necessarily a second pass.
        foreach ($this->plannedAccounts as $definition) {
            $meta = $this->accountMeta[$definition['name']];

            if ($meta === []) {
                continue;
            }

            $settlesInto = $meta['settlement'] ?? null;
            unset($meta['settlement']);

            if ($settlesInto !== null) {
                $meta['settlement_account_id'] = $this->accountIds[$settlesInto];
            }

            Account::find($this->accountIds[$definition['name']])->meta()->create(['meta' => $meta]);
        }

        foreach ($this->plannedCategories as $position => $name) {
            $oldId = array_search($position, $this->categoryPositions, true);

            $this->categoryIds[(int) $oldId] = Category::create(['name' => $name])->id;
        }

        $this->step('categories', count($this->categoryIds));

        $this->writeCash($plan);
        $this->writeCharges($plan);
        $this->writeSettlements($plan);
        $this->writeTrades($plan);
        $this->writeRecurring($plan);
    }

    /** @param array<string, mixed> $plan */
    private function writeCash(array $plan): void
    {
        foreach ($plan['cash'] as $row) {
            $this->insert($row);
        }

        $this->step('cash rows', count($plan['cash']));
    }

    /** @param array<string, mixed> $plan */
    private function writeCharges(array $plan): void
    {
        foreach ($plan['charges'] as $charge) {
            $this->insert($charge);
        }

        $this->step('card charges and credits', count($plan['charges']));
    }

    /** @param array<string, mixed> $plan */
    private function writeSettlements(array $plan): void
    {
        $settlements = $plan['settlements'];
        $written = 0;

        foreach ($settlements['matched'] as $key => $group) {
            [$card, $period] = explode('|', $key, 2);

            // A credit in the period counts as paid, since it is written as a payment and
            // CardStatement adds it in, so the payment that clears the period may be
            // smaller than the charges it covers.
            $owed = BigDecimal::of($settlements['owed'][$key])
                ->minus($settlements['credited'][$key] ?? '0.0000');
            $running = BigDecimal::zero();
            $clearedOn = null;

            foreach ($group['rows'] as $payment) {
                $running = $running->plus($payment['amount']);

                // Exactly, not at least: CardStatement::isSettled() tests owed against zero,
                // so a period left overdrawn by a payment that overshot is not a settled one
                // and must not be marked as one. The old grouping overlaps, so a payment
                // can land on a period another already covered, and that shows up here as
                // an overpaid period rather than as a wrong charge.
                if ($clearedOn === null && $running->isEqualTo($owed)) {
                    $clearedOn = $payment['date'];
                }
            }

            $bank = $this->accountIds[self::PAYING_BANK];

            foreach ($group['rows'] as $payment) {
                $paymentRow = Transaction::create([
                    'account_id' => $this->accountIds[$card],
                    'category_id' => null,
                    'date' => $payment['date'],
                    'type' => TransactionType::Payment->value,
                    'description' => sprintf('Statement %s', $period),
                    'amount' => $payment['amount'],
                    'ccy' => Currency::Hkd->value,
                    'status' => TransactionStatus::Posted->value,
                ]);

                // The old CARD PAYMENT row is the bank's half of this and is kept as it
                // was recorded rather than regenerated, so the amount and date that left
                // the account are the ones the ledger had.
                $transfer = Transaction::create([
                    'account_id' => $bank,
                    'category_id' => null,
                    'date' => $payment['date'],
                    'type' => TransactionType::Withdraw->value,
                    'description' => sprintf('Card payment [%s]', $card),
                    'amount' => $payment['amount'],
                    'ccy' => Currency::Hkd->value,
                    'status' => TransactionStatus::Posted->value,
                ]);

                $paymentRow->meta()->create([
                    'meta' => ['due_date' => $period, 'paired_transaction_id' => $transfer->id],
                ]);

                $transfer->meta()->create(['meta' => ['paired_transaction_id' => $paymentRow->id]]);

                if ($clearedOn !== null && $payment['date'] === $clearedOn) {
                    $this->markPeriodSettled($this->accountIds[$card], $period, $paymentRow->id);
                }

                $written++;
            }
        }

        $this->step('settlements', $written);
    }

    /** The charges of one card's one period, which is what a settled_by names. */
    private function markPeriodSettled(int $accountId, string $period, int $paymentId): void
    {
        $bags = DB::table('meta')
            ->join('transactions', function ($join) {
                $join->on('transactions.id', '=', 'meta.model_id')
                    ->where('meta.model_type', '=', Transaction::class);
            })
            ->where('transactions.account_id', $accountId)
            ->where('transactions.type', TransactionType::Charge->value)
            ->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(meta.meta, \'$.due_date\')) = ?', [$period])
            ->get(['meta.id', 'meta.meta']);

        foreach ($bags as $bag) {
            DB::table('meta')->where('id', $bag->id)->update([
                'meta' => json_encode(
                    array_merge(json_decode($bag->meta, true) ?: [], ['settled_by' => $paymentId]),
                    JSON_UNESCAPED_UNICODE
                ),
            ]);
        }
    }

    /** @param array<string, mixed> $plan */
    private function writeTrades(array $plan): void
    {
        $written = [];

        foreach ($plan['trades'] as $trade) {
            $brokerage = $trade['brokerage'] ?? null;

            if ($brokerage === null || ! isset($this->accountIds[$brokerage])) {
                $this->flag(sprintf('Trade [%s %s] on %s has no brokerage, so it is skipped.', $trade['type'], $trade['symbol'], $trade['date']));

                continue;
            }

            $data = TransactionData::from([
                'id' => null,
                'account_id' => $this->accountIds[$brokerage],
                'category_id' => null,
                'date' => $trade['date'],
                'type' => $trade['type'],
                'description' => sprintf('%s %s', ucfirst($trade['type']), $trade['symbol']),
                'amount' => null,
                'ccy' => Currency::Hkd->value,
                'status' => TransactionStatus::Posted->value,
                'meta_data' => [
                    'symbol' => $trade['symbol'],
                    'quantity' => $trade['quantity'],
                    'unit_price' => $trade['unit_price'],
                    'fees' => $trade['fees'],
                    // The cash moved as its own recorded row, so a second leg here would
                    // take the money out of the bank twice.
                    'no_cash' => true,
                ],
                'created_at' => null,
            ]);

            // Per brokerage, oldest first, so a sell follows the buys it sells from and
            // guardHoldings() sees the position it is actually selling out of.
            $written[$brokerage][] = $trade;
        }

        $count = 0;

        foreach ($written as $brokerage => $rows) {
            usort($rows, fn (array $a, array $b) => [$a['date'], $a['symbol']] <=> [$b['date'], $b['symbol']]);

            foreach ($rows as $trade) {
                $this->writeTrade($brokerage, $trade);
                $count++;
            }
        }

        $this->step('trades', $count);
    }

    /** @param array<string, mixed> $trade */
    private function writeTrade(string $brokerage, array $trade): void
    {
        $broker = Account::findOrFail($this->accountIds[$brokerage]);

        $data = TransactionData::from([
            'id' => null,
            'account_id' => $broker->id,
            'category_id' => null,
            'date' => $trade['date'],
            'type' => $trade['type'],
            'description' => sprintf('%s %s', ucfirst($trade['type']), $trade['symbol']),
            'amount' => null,
            'ccy' => $broker->ccy,
            'status' => TransactionStatus::Posted->value,
            'meta_data' => [
                'symbol' => $trade['symbol'],
                'quantity' => $trade['quantity'],
                'unit_price' => $trade['unit_price'],
                'fees' => $trade['fees'],
                // The cash moved as its own recorded row, so a second leg here would
                // take the money out of the bank twice.
                'no_cash' => true,
            ],
            'created_at' => null,
        ]);

        $data->guardHoldings();
        $data->write();
    }

    /** @param array<string, mixed> $plan */
    private function writeRecurring(array $plan): void
    {
        $now = now();

        foreach ($plan['recurring'] as $rule) {
            DB::table('recurring_transactions')->insert([
                'account_id' => $this->accountIds[$rule['account']],
                'category_id' => $rule['category'] === null ? null : $this->categoryIds[$rule['category']],
                'type' => $rule['type'],
                'description' => $rule['description'],
                'amount' => $rule['amount'],
                'ccy' => Currency::Hkd->value,
                'frequency' => $rule['frequency'],
                'start_date' => $rule['start_date'],
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->step('recurring rules', count($plan['recurring']));
    }

    /**
     * A planned row, with the account name and old category id resolved to the new ids
     * and the brokerage named rather than numbered.
     *
     * @param  array<string, mixed>  $row
     */
    private function insert(array $row): int
    {
        $meta = $row['meta'] ?? null;

        if (isset($meta['brokerage'])) {
            $meta['brokerage_account_id'] = $this->accountIds[$meta['brokerage']];
            unset($meta['brokerage']);
        }

        $transaction = Transaction::create([
            'account_id' => $this->accountIds[$row['account']],
            'category_id' => $row['category'] === null ? null : $this->categoryIds[$row['category']],
            'date' => $row['date'],
            'type' => $row['type'],
            'description' => $row['description'],
            'amount' => $row['amount'],
            'ccy' => $row['ccy'] ?? Currency::Hkd->value,
            'status' => $row['status'],
        ]);

        if ($meta !== null && $meta !== []) {
            $transaction->meta()->create(['meta' => $meta]);
        }

        return $transaction->id;
    }

    private function step(string $what, int $count): void
    {
        $this->components->twoColumnDetail('Wrote '.$what, (string) $count);
    }
}
