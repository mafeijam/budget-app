<?php

namespace App\Http\Controllers;

use App\DTO\RecurringTransactionData;
use App\Enums\AccountType;
use App\Enums\Currency;
use App\Enums\Frequency;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringTransaction;
use App\Support\Fx;
use App\Support\RecurringApply;
use App\Support\RecurringPayments;
use App\Support\RecurringScan;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Spatie\LaravelData\PaginatedDataCollection;

class RecurringTransactionController extends Controller
{
    private const DEFAULT_SORT = 'start_date';

    private const SORTABLE = ['description', 'type', 'amount', 'frequency', 'start_date', 'active'];

    /**
     * A pair of years. A yearly rule needs three payments to be found at all, so anything
     * shorter cannot find one however long it looks.
     */
    private const FIND_MONTHS = 24;

    public function index(Request $r)
    {
        $form = self::formProps();

        $sort = in_array($r->input('sort'), self::SORTABLE, true) ? $r->input('sort') : self::DEFAULT_SORT;
        $dir = $r->input('dir') === 'desc' ? 'desc' : 'asc';

        // Every rule on one page, grouped on screen: paged by ten, seven of seventeen were a
        // click away. Still a paginator, because saving and deleting reload through it.
        $rules = RecurringTransaction::query()
            ->with('account')
            ->orderBy($sort, $dir)
            ->orderBy('id')
            ->paginate(max(1, RecurringTransaction::query()->count()));

        $costs = $this->costs($rules->getCollection());
        $base = Fx::BASE->value;
        $health = $this->health();

        // Before Data::collect(), which replaces the paginator's models with DTOs.
        $nextDates = $rules->getCollection()
            ->mapWithKeys(fn (RecurringTransaction $rule) => [$rule->id => $rule->nextDate()])
            ->all();

        $data = RecurringTransactionData::collect($rules, PaginatedDataCollection::class);

        $params = array_merge($r->query(), ['sort' => $sort, 'dir' => $dir]);

        $meta = [...$form['meta'], 'sort' => ['by' => self::DEFAULT_SORT, 'dir' => 'asc']];

        return inertia('recurring', [
            ...$form,
            ...compact(
                'data',
                'params',
                'meta',
                'nextDates',
                'costs',
                'health',
                'base',
            ),
            // Only after a find, and only for the one request it flashes into: the dialog
            // opens on what the scan found, and every other visit has none to show.
            'findings' => $r->session()->get('findings'),
        ]);
    }

    /**
     * What FormRecurring needs, and nothing of the list's: the recurring page sends it with the list, and
     * the Add menu asks for it alone (FormContextController) to open the form over any other
     * page. One method, so the dialog there cannot drift from the one here.
     *
     * @return array<string, mixed>
     */
    public static function formProps(): array
    {
        // today() is Hong Kong's day, not the browser's.
        $formEmpty = RecurringTransactionData::empty([
            'frequency' => Frequency::Monthly->value,
            'start_date' => today()->toDateString(),
            'active' => true,
        ]);

        // Per account type, and only the types that can repeat, so a brokerage gets none.
        $typeOptions = collect(AccountType::cases())
            ->mapWithKeys(fn (AccountType $accountType) => [
                $accountType->value => collect(TransactionType::offeredOrder())
                    ->filter(fn (TransactionType $type) => $type->canRecur() && $type->isAllowedFor($accountType))
                    ->map(fn (TransactionType $type) => $type->value)
                    ->values()
                    ->all(),
            ])
            ->filter()
            ->all();

        $typeDefaults = collect($typeOptions)
            ->map(fn (array $types, string $accountType) => AccountType::from($accountType)->defaultTransactionType()->value)
            ->all();

        // Every account a type is offered for, so the table can name a closed one too.
        $accounts = Account::query()
            ->whereIn('type', array_keys($typeOptions))
            ->orderBy('name')
            ->get()
            ->map(fn (Account $account) => [
                'label' => $account->name,
                'value' => $account->id,
                'type' => $account->type,
                'ccy' => $account->ccy,
                'active' => $account->status === 'active',
            ]);

        $categories = Category::orderBy('name')->get()->map(fn (Category $category) => [
            'label' => $category->name,
            'value' => $category->id,
        ]);

        $currencyOptions = collect(Currency::cases())
            ->map(fn (Currency $currency) => [
                'label' => $currency->label(),
                'value' => $currency->value,
            ])
            ->values();

        $frequencyOptions = array_column(Frequency::cases(), 'value');

        $options = compact('accounts', 'categories');

        $meta = ['form' => 'recurring-form', 'path' => '/recurring'];

        return compact('formEmpty', 'meta', 'options', 'typeOptions', 'typeDefaults', 'currencyOptions', 'frequencyOptions');
    }

    /**
     * What the last two years of cash and card movements look like, as rules. Reads and
     * writes nothing, so the report can be looked at before anything is adopted.
     */
    public function find(Request $r)
    {
        $findings = RecurringScan::findings((int) $r->integer('months', self::FIND_MONTHS));

        return back()->with('findings', $findings);
    }

    /**
     * Put the rules in line with the history. Scanned again rather than acting on what the
     * dialog was holding: a report a minute old is a report about a ledger that may have
     * moved, and the scan is cheap enough not to be worth the risk of trusting it.
     *
     * Nothing is recorded here. The recorder writes what is due, and a rule this leaves
     * alone can owe months of it -- so a button pressed to look at the history would have
     * written pending transactions for a rule nobody touched. That is what Run now and the
     * nightly command are for.
     */
    public function applyFindings(Request $r)
    {
        $findings = RecurringScan::findings((int) $r->integer('months', self::FIND_MONTHS));
        $done = RecurringApply::apply($findings);

        // To the index rather than back, so a find opened from anywhere lands on the rules it
        // just wrote.
        return to_route('recurring.index')->with('message', ucfirst(RecurringApply::summary($done)));
    }

    /**
     * Each rule's group and what it comes to a month and a year in the base currency, and
     * each group's monthly total over the active rules. A yearly rule is a twelfth a month.
     *
     * The group is read from the enums, not a list: money in is income, a card's charge a
     * card charge -- rent on a card as much as a streaming plan -- and cash going out a bill.
     *
     * @param  Collection<int, RecurringTransaction>  $rules
     * @return array{rules: array<int, array{group: string, monthly: ?string, yearly: ?string}>, totals: array<string, string>, yearly: array<string, string>, unconverted: list<string>}
     */
    private function costs($rules): array
    {
        $fx = Fx::for($rules->pluck('ccy')->unique()->all());
        $day = today()->toDateString();
        $zero = BigDecimal::zero();
        $totals = ['income' => $zero, 'bills' => $zero, 'cards' => $zero];
        $annual = $zero;
        $each = [];
        $unconverted = [];

        foreach ($rules as $rule) {
            $accountType = AccountType::tryFrom((string) $rule->account?->type);
            $sign = $accountType === null ? 0 : TransactionType::from($rule->type)->movesBalanceOn($accountType);
            $group = $sign > 0 ? 'income' : ($accountType === AccountType::Card ? 'cards' : 'bills');

            $amount = BigDecimal::of((string) $rule->amount);
            $yearly = Frequency::from($rule->frequency) === Frequency::Yearly;
            $monthly = $yearly
                ? $amount->dividedBy(12, 4, RoundingMode::HalfUp)
                : $amount;
            $base = $fx->toBase((string) $monthly, $rule->ccy, $day);

            if ($base === null) {
                $unconverted[] = $rule->ccy;
            } elseif ($rule->active && ($rule->end_date === null || $rule->end_date >= $day)) {
                $totals[$group] = $totals[$group]->plus($base);

                if ($yearly && $group !== 'income') {
                    $annual = $annual->plus($base);
                }
            }

            $each[$rule->id] = [
                'group' => $group,
                'monthly' => $base === null ? null : (string) $base,
                'yearly' => $base === null ? null : (string) $base->multipliedBy(12)->toScale(4),
            ];
        }

        $totals['net'] = $totals['income']->minus($totals['bills'])->minus($totals['cards']);
        // The yearly bills and charges again, already inside bills and cards: the page lists
        // them apart, and adding this to net would take them off twice.
        $totals['annual'] = $annual;

        return [
            'rules' => $each,
            'totals' => array_map(fn (BigDecimal $total) => (string) $total->toScale(4), $totals),
            'yearly' => array_map(fn (BigDecimal $total) => (string) $total->multipliedBy(12)->toScale(4), $totals),
            'unconverted' => array_values(array_unique($unconverted)),
        ];
    }

    /**
     * What the history says of each rule, from the scan the Find button runs: the last
     * payment it matched, and where the rule has drifted -- a new figure or day, or no
     * payment for long enough to have stopped -- the figures to correct it with.
     *
     * @return array<int, array{verdict: string, last: ?string, amount: ?string, day: ?int, flags: list<string>}>
     */
    private function health(): array
    {
        $health = [];

        foreach (RecurringScan::findings(self::FIND_MONTHS) as $finding) {
            if ($finding['rule_id'] === null) {
                continue;
            }

            $health[$finding['rule_id']] = [
                'verdict' => $finding['verdict'],
                'last' => $finding['last'] ?? null,
                'amount' => $finding['amount'] ?? null,
                'day' => $finding['day'] ?? null,
                'flags' => $finding['flags'],
            ];
        }

        return $health;
    }

    /**
     * One rule put in line with its history: the correction the scan found, or its deletion
     * when the payments stopped. Scanned again rather than trusting the page's figures, as
     * applyFindings() is, and only this rule's finding is applied.
     */
    public function adopt(RecurringTransaction $recurringTransaction)
    {
        $finding = collect(RecurringScan::findings(self::FIND_MONTHS))
            ->first(fn (array $f) => $f['rule_id'] === $recurringTransaction->id
                && in_array($f['verdict'], ['differs', 'stopped'], true));

        if ($finding === null) {
            return back()->with('message', "Recurring [{$recurringTransaction->description}] already matches its history");
        }

        return back()->with('message', ucfirst(RecurringApply::summary(RecurringApply::apply([$finding]))));
    }

    public function store(RecurringTransactionData $data)
    {
        $rule = RecurringTransaction::create($data->except('id')->toArray());

        return $this->recordAndReport($rule, "Recurring [{$rule->description}] saved");
    }

    public function update(RecurringTransaction $recurringTransaction, RecurringTransactionData $data)
    {
        $resuming = ! $recurringTransaction->active && $data->active;

        $recurringTransaction->fill($data->except('id')->toArray());

        // A pause skips what fell due during it rather than catching up on resuming.
        if ($resuming) {
            $yesterday = today()->subDay()->toDateString();

            $recurringTransaction->last_recorded_on = max($recurringTransaction->last_recorded_on ?? $yesterday, $yesterday);
        }

        $recurringTransaction->save();

        return $this->recordAndReport($recurringTransaction, "Recurring [{$recurringTransaction->description}] updated");
    }

    /** The scheduled recurring:record, now: every active rule due through today. */
    public function run()
    {
        $result = RecurringPayments::recordDue(today());

        $message = $result['recorded'] === 0
            ? 'Nothing due to record'
            : sprintf('%d pending transaction%s recorded', $result['recorded'], $result['recorded'] === 1 ? '' : 's');

        return back()->with('message', implode('; ', [$message, ...$result['refusals']]));
    }

    /** What it already wrote stays: those rows are history, and each is deleted on its own. */
    public function destroy(RecurringTransaction $recurringTransaction)
    {
        $recurringTransaction->delete();

        return back()->with('message', "Recurring [{$recurringTransaction->description}] deleted");
    }

    /** Due today is written now rather than at the next scheduled run. */
    private function recordAndReport(RecurringTransaction $rule, string $message)
    {
        // Refreshed so today's row is built from the stored figures, as every later one is.
        $result = RecurringPayments::record($rule->refresh(), today());

        if ($result['refusal'] !== null) {
            return back()->with('message', "{$message}, but {$result['refusal']}");
        }

        if ($result['recorded'] > 0) {
            $message .= sprintf(
                ', %d pending transaction%s recorded',
                $result['recorded'],
                $result['recorded'] === 1 ? '' : 's'
            );
        }

        return back()->with('message', $message);
    }
}
