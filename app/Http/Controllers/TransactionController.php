<?php

namespace App\Http\Controllers;

use App\DTO\TransactionData;
use App\DTO\TransactionMetaData;
use App\Enums\AccountType;
use App\Enums\Currency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\CardStatement;
use Brick\Math\BigDecimal;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\PaginatedDataCollection;

class TransactionController extends Controller
{
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
        $formEmpty = TransactionData::empty(['date' => today()->toDateString()]);

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

        $transactions = Transaction::query()
            // For account_name, which the accessor reads -- otherwise a query per row.
            ->with(['meta', 'account'])
            ->orderBy($r->input('sort', 'created_at'), $r->input('dir', 'desc'))
            ->paginate($r->input('per_page', 5));

        // What each row on this page would take with it, keyed by the row that would
        // take it along -- so the delete confirmation can name the other half of a card
        // settlement before the user agrees to remove it.
        //
        // Read off the models, before Data::collect() below. That call maps the
        // paginator through and leaves DTOs where the models were, so a query
        // returning this paginator a few lines later hands over TransactionData and
        // nothing says so -- a 500 on a page with rows in it, an empty page otherwise.
        $linked = $this->linkedCounterparts($transactions->getCollection());

        $data = TransactionData::collect($transactions, PaginatedDataCollection::class);

        $options = compact('accounts', 'categories');

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

        // What each card still owes. One query per card, not one for all: due_date
        // belongs to a single card's statements.
        //
        // Inactive cards included, unlike the account picker above. Closing a card does
        // not unpaid it, and settle() has never checked status, so filtering them out
        // here would leave a debt the user could neither see nor discharge.
        $statements = Account::query()
            ->where('type', AccountType::Card->value)
            ->orderBy('name')
            // with('meta') so settlementAccount() is not a second query per card.
            ->with('meta')
            ->get()
            ->map(fn (Account $card) => [
                'card' => [
                    'id' => $card->id,
                    'name' => $card->name,
                    'ccy' => $card->ccy,
                ],
                'periods' => CardStatement::outstandingFor($card)
                    ->map(fn (CardStatement $statement) => [
                        'first_charge_date' => $statement->firstChargeDate,
                        'last_charge_date' => $statement->lastChargeDate,
                        'due_date' => $statement->dueDate,
                        'charge_count' => $statement->chargeCount,
                        'payment_count' => $statement->paymentCount,
                        'pending_count' => $statement->pendingCount,
                        'charged' => $statement->charged,
                        'paid' => $statement->paid,
                        'owed' => $statement->owed(),
                    ])
                    ->all(),
            ])
            // A card with nothing outstanding is not worth a heading.
            ->filter(fn (array $group) => $group['periods'] !== [])
            ->values();

        // So the settle dialog can name the bank *before* the user commits, and a card
        // with none shows a disabled control rather than a button that fails.
        $cardBanks = Account::query()
            ->where('type', AccountType::Card->value)
            ->with('meta')
            ->get()
            ->filter(fn (Account $card) => $card->settlementAccount() !== null)
            ->mapWithKeys(fn (Account $card) => [
                $card->id => $card->settlementAccount()->name,
            ])
            ->all();

        $params = $r->query() + ['sort' => 'created_at', 'dir' => 'desc'];

        $meta = [
            'form' => 'transaction-form',
            'path' => '/transactions',
        ];

        return inertia('transaction', compact(
            'formEmpty',
            'data',
            'params',
            'meta',
            'options',
            'statements',
            'cardBanks',
            'linked',
            'typeOptions',
            'typeDefaults',
            'statusOptions',
            'currencyOptions',
        ));
    }

    public function store(TransactionData $data)
    {
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
        ]);

        $refuse = fn (string $message) => throw ValidationException::withMessages(['due_date' => $message]);

        if ($account->type !== AccountType::Card->value) {
            $refuse(sprintf(
                'Account [%s] is a %s account. Only a card has a statement to settle.',
                $account->name,
                $account->type
            ));
        }

        // A card with no bank named cannot be settled; say so rather than guess one.
        $bank = $account->settlementAccount();

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

            // Written here, not through the DTO, which prohibits the field: the ids do
            // not exist until both rows do. The transfer's bag carries no due_date, or a
            // bank would fall into a card's arithmetic.
            $payment->meta()->create([
                'meta' => ['due_date' => $figures['due_date'], 'paired_transaction_id' => $transfer->id],
            ]);

            $transfer->meta()->create([
                'meta' => ['paired_transaction_id' => $payment->id],
            ]);

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

    public function destroy(Transaction $transaction)
    {
        // Read the pairing, and the settlement's period, before anything is deleted:
        // both live in bags, and both bags are about to go.
        $pairedId = $transaction->meta?->meta?->getArrayCopy()['paired_transaction_id'] ?? null;
        $partner = $pairedId === null ? null : Transaction::with('meta')->find($pairedId);
        $period = $this->settlementPeriod($transaction, $partner);

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

        return back()->with('message', $period === null
            ? 'Card settlement deleted in full: 2 transactions'
            : sprintf('Card settlement [%s] deleted in full: 2 transactions', $period));
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
            ->with('account')
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
