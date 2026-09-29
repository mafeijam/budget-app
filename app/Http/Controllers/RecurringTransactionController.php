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
use App\Support\RecurringApply;
use App\Support\RecurringPayments;
use App\Support\RecurringScan;
use Illuminate\Http\Request;
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
        // today() is Hong Kong's day, not the browser's.
        $formEmpty = RecurringTransactionData::empty([
            'frequency' => Frequency::Monthly->value,
            'start_date' => today()->toDateString(),
            'active' => true,
        ]);

        $sort = in_array($r->input('sort'), self::SORTABLE, true) ? $r->input('sort') : self::DEFAULT_SORT;
        $dir = $r->input('dir') === 'desc' ? 'desc' : 'asc';

        $rules = RecurringTransaction::query()
            ->orderBy($sort, $dir)
            ->orderBy('id')
            ->paginate($r->input('per_page', self::PER_PAGE));

        // Before Data::collect(), which replaces the paginator's models with DTOs.
        $nextDates = $rules->getCollection()
            ->mapWithKeys(fn (RecurringTransaction $rule) => [$rule->id => $rule->nextDate()])
            ->all();

        $data = RecurringTransactionData::collect($rules, PaginatedDataCollection::class);

        // Per account type, and only the types that can repeat, so a brokerage gets none.
        $typeOptions = collect(AccountType::cases())
            ->mapWithKeys(fn (AccountType $accountType) => [
                $accountType->value => collect(TransactionType::cases())
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

        $params = array_merge($r->query(), ['sort' => $sort, 'dir' => $dir]);

        $meta = [
            'form' => 'recurring-form',
            'path' => '/recurring',
            'sort' => ['by' => self::DEFAULT_SORT, 'dir' => 'asc'],
        ];

        return inertia('recurring', [
            ...compact(
                'formEmpty',
                'data',
                'params',
                'meta',
                'options',
                'nextDates',
                'typeOptions',
                'typeDefaults',
                'currencyOptions',
                'frequencyOptions',
            ),
            // Only after a find, and only for the one request it flashes into: the dialog
            // opens on what the scan found, and every other visit has none to show.
            'findings' => $r->session()->get('findings'),
        ]);
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

        // To the first page rather than back: the table is ten rows sorted by start date, and
        // a page of ten is quite likely to be showing the same ten rows it was showing before
        // -- the rules just created sort after the ones just corrected, and there are more of
        // them than fit. Landing on the top of a refreshed table is the point of applying.
        return to_route('recurring.index')->with('message', ucfirst(RecurringApply::summary($done)));
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
