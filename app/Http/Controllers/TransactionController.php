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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\PaginatedDataCollection;

class TransactionController extends Controller
{
    public function index(Request $r)
    {
        $formEmpty = TransactionData::empty();

        $meta = [
            'form' => 'transaction-form',
            'path' => '/transactions',
        ];

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
            // ...$category->toArray(),
            'label' => $category->name,
            'value' => $category->id,
        ]);

        $transactions = Transaction::query()
            // For account_name, which the accessor reads -- otherwise a query per row.
            ->with(['meta', 'account'])
            ->orderBy($r->input('sort', 'created_at'), $r->input('dir', 'desc'))
            ->paginate($r->input('per_page', 5));

        $data = TransactionData::collect($transactions, PaginatedDataCollection::class);

        $options = compact('accounts', 'categories');

        // The pairing is what makes a type legal, so a flat list would offer "buy" on
        // a savings account only to refuse it. Derived from accountTypes(), the one
        // place that pairing lives.
        $typeOptions = collect(AccountType::cases())
            ->mapWithKeys(fn (AccountType $accountType) => [
                $accountType->value => collect(TransactionType::cases())
                    ->filter(fn (TransactionType $type) => $type->isAllowedFor($accountType))
                    ->map(fn (TransactionType $type) => $type->value)
                    ->values()
                    ->all(),
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
        // belongs to a single card's statements. The second consumer of a query the
        // due index no longer serves -- see create_transactions_table.
        $statements = Account::query()
            ->where('type', AccountType::Card->value)
            ->where('status', 'active')
            ->orderBy('name')
            // with('meta') so settlementAccount() is not a second query per card; a
            // plain find() because the link is a JSON path Eloquent cannot join on.
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

        // So the settle dialog can name the bank *before* the user commits, and a
        // card with none shows a disabled control rather than a button that fails.
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
            'typeOptions',
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
        // The bag is replaced rather than added, or a corrected merchant would sit
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

        DB::beginTransaction();

        try {
            $payment = Transaction::create([
                'account_id' => $account->id,
                'category_id' => null,
                'date' => today()->toDateString(),
                'type' => TransactionType::Payment->value,
                'description' => sprintf('Statement %s', $figures['due_date']),
                'amount' => $owed,
                'ccy' => $account->ccy,
                'status' => TransactionStatus::Posted->value,
            ]);

            $transfer = Transaction::create([
                'account_id' => $bank->id,
                'category_id' => null,
                'date' => today()->toDateString(),
                'type' => TransactionType::Transfer->value,
                'description' => sprintf('Card payment [%s]', $account->name),
                'amount' => $owed,
                'ccy' => $account->ccy,
                'status' => TransactionStatus::Posted->value,
            ]);

            // Written here, not through the DTO, which prohibits the field: the ids do
            // not exist until both rows do. The card side also carries the due_date the
            // statement query groups by; the transfer's carries nothing, or a bank
            // would fall into a card's arithmetic.
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
        // A settlement is two rows and must not come apart; deleting one half invents
        // a payment that never happened.
        $pairedId = $transaction->meta?->meta?->getArrayCopy()['paired_transaction_id'] ?? null;

        if ($pairedId !== null) {
            return back()->with('message', sprintf(
                'Transaction [%d] is half of a card settlement with [%d] and cannot be deleted on its own',
                $transaction->id,
                $pairedId
            ));
        }

        $transaction->meta()->delete();
        $transaction->delete();

        return back()->with('message', "Transaction [{$transaction->type}] deleted");
    }
}
