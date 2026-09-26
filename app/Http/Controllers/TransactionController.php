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

        // type and ccy ride along with each option because the form needs both and
        // cannot get them anywhere else. `type` is what lets the type picker offer
        // only what the chosen account accepts, which is the reason typeOptions is
        // keyed by account type at all; `ccy` is what a transaction defaults to,
        // which is right nearly every time.
        //
        // Not the paginated table set: a card on page two must still be selectable
        // while the form still looks complete.
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
            ->with('meta')
            ->orderBy($r->input('sort', 'created_at'), $r->input('dir', 'desc'))
            ->paginate($r->input('per_page', 5));

        $data = TransactionData::collect($transactions, PaginatedDataCollection::class);

        $options = compact('accounts', 'categories');

        // Which transaction types each account type accepts, keyed by it. Not a flat
        // list: the pairing is what makes a type legal at all, and offering "buy" on
        // a savings account means the user fills in a trade, submits, and is told by
        // TransactionData::guardAccountType() that it was never going to work. The
        // refusal is correct and the picker is still wrong.
        //
        // Derived from TransactionType::accountTypes() through isAllowedFor(), which
        // is the single place the pairing is defined -- so a type added to the enum
        // appears here and a type moved between account types moves with it. Sending
        // it grouped rather than making the browser hold the rule is the same
        // argument as the currency list on AccountController: the server is what
        // decides, and a copy in the template can only drift.
        $typeOptions = collect(AccountType::cases())
            ->mapWithKeys(fn (AccountType $accountType) => [
                $accountType->value => collect(TransactionType::cases())
                    ->filter(fn (TransactionType $type) => $type->isAllowedFor($accountType))
                    ->map(fn (TransactionType $type) => $type->value)
                    ->values()
                    ->all(),
            ])
            ->all();

        // Status and currency, same reason and same route as the account page's
        // equivalent lists. Plain values for status because it has no separate
        // display name; {label, value} for currency because Currency::label() is a
        // real thing that differs from the code.
        $statusOptions = array_column(TransactionStatus::cases(), 'value');

        $currencyOptions = collect(Currency::cases())
            ->map(fn (Currency $currency) => [
                'label' => $currency->label(),
                'value' => $currency->value,
            ])
            ->values();

        // What each card still owes, period by period.
        //
        // The second consumer of the query transactions_account_due_index used to
        // serve and no longer has an index for -- see create_transactions_table and
        // App\Support\CardStatement. One query per card rather than one for all of
        // them: grouping is by due_date, which is a fact about a single card's
        // statements, and merging the accounts first would total a figure across
        // cards that are separately owed and separately paid.
        //
        // Outstanding periods only, so a card with years of settled history does not
        // push the ones needing attention down the page. The payments that closed
        // them are in the list below.
        $statements = Account::query()
            ->where('type', AccountType::Card->value)
            ->where('status', 'active')
            ->orderBy('name')
            // with('meta') so the statement query does not re-read each card's bag, and
            // so settlementAccount() is not a second query per card. It is a plain
            // find() rather than a belongsTo because the link is a JSON path and
            // Eloquent cannot join on one -- see Account::settlementAccount().
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

        // Which bank each of those cards is paid from, keyed by card id.
        //
        // Sent so the settle dialog can name the account the money leaves *before*
        // the user commits, and so a card that has none shows a disabled control with
        // a reason rather than a button that fails on click. A second lookup of what
        // settle() will check, which is the duplication the cost is worth: the
        // alternative is the user finding out from an error message.
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
        // A transaction is two writes -- the row, then the bag -- and the second is
        // where a type-specific attribute lives, so a failure between them has to
        // leave neither. The same shape, and the same reasoning, as
        // AccountController::store().
        DB::beginTransaction();

        try {
            $transaction = Transaction::create($data->except('meta_data')->toArray());

            // Nulls dropped, falsy values kept. Plain filter() would drop both, and
            // TransactionMetaData allows fees of '0' -- so a trade that genuinely
            // cost nothing in commission would arrive with no fees key and read back
            // as missing rather than as zero. AccountController's filter() is
            // unharmed by the same distinction because nothing it stores can be zero.
            $meta = collect($data->meta_data?->all())->filter(fn ($value) => $value !== null);

            if ($meta->isNotEmpty()) {
                $transaction->meta()->create([
                    'meta' => $meta,
                ]);
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();

            // Rolling back is right; discarding the exception is not. Every failure
            // otherwise looked identical from the outside -- a 302 reading
            // "error db..." with no record of what went wrong. See
            // AccountErrorReportingTest, which is the same contract pinned for
            // accounts.
            report($e);

            return back()->with('message', 'error db...');
        }

        // type, not type->value: Transaction declares no casts, so the attribute
        // comes back the string the column holds. See MassAssignmentTest.
        return back()->with('message', "Transaction [{$transaction->type}] recorded");
    }

    public function update(Transaction $transaction, TransactionData $data)
    {
        // Same two writes and the same transaction as store(). The difference is the
        // bag: on a second write it is replaced rather than added, or a merchant the
        // user corrected would sit beside the one they replaced and the row would read
        // complete while carrying both.
        DB::beginTransaction();

        try {
            $transaction->update($data->except('meta_data')->toArray());

            $meta = collect($data->meta_data?->all())->filter(fn ($value) => $value !== null);

            if ($meta->isNotEmpty()) {
                // Keyed on the bag's own id, as AccountController does. Without it
                // updateOrCreate would search on the relation's foreign key with no
                // values to fill, find nothing, and try to insert -- which the meta
                // table's unique index on (model_id, model_type) then refuses.
                $transaction->meta()->updateOrCreate(
                    ['id' => $transaction->meta?->id],
                    ['meta' => $meta]
                );
            } else {
                // A payload with no bag is valid for a charge, and the statement
                // query groups on the bag's due_date. A bag left behind would keep
                // the charge in its period after the user had cleared the merchant,
                // so the absence has to be written rather than skipped.
                $transaction->meta()->delete();
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();

            report($e);

            return back()->with('message', 'error db...');
        }

        // fresh(), not the instance: the payload's type may have been a different one,
        // and the message should name what the row now is rather than what was sent.
        return back()->with('message', "Transaction [{$transaction->fresh()->type}] updated");
    }

    /**
     * Pay off one statement period of a card, in two rows.
     *
     * The amount is computed here and never taken from the request. The one figure
     * the client does send is the one the user was shown, and it is compared rather
     * than used: a payment recorded against a figure that has since changed would be
     * a payment the user did not agree to, and because both rows are written
     * together the mistake would be internally consistent and invisible.
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

        // The bank the money leaves. A card with no bank named cannot be settled, and
        // saying so is more use than guessing one: the account form is where it is
        // set, and the picker there lists only cash accounts in the card's currency.
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

        // Checked before the figure is compared, because this is the case where the
        // owed total is arithmetically right and still not a number to pay: the
        // issuer has not billed the pending rows, so paying the counted figure now
        // leaves the period owing the rest under someone who believes they have
        // settled it.
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

            // The link, written here rather than through TransactionMetaData because
            // the ids do not exist until both rows do -- and the DTO prohibits the
            // field, which is the point: this is the only place in the app allowed to
            // set it.
            //
            // On the card side the bag also carries the due_date, because that is the
            // key the statement query groups by and the payment is what zeroes it.
            // The transfer's bag carries nothing else: a bank has no statement
            // periods, and a due_date here would drop it into a card's arithmetic.
            $payment->meta()->create([
                'meta' => ['due_date' => $figures['due_date'], 'paired_transaction_id' => $transfer->id],
            ]);

            $transfer->meta()->create([
                'meta' => ['paired_transaction_id' => $payment->id],
            ]);

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();

            // Both rows or neither. A payment without the transfer is a card that
            // says it was paid while the bank says the money is still there, and
            // there is no field in either row that would show it.
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
        // A settlement is two rows and must not come apart. Deleting one half leaves a
        // payment that never happened: the card shows the period owing again while
        // the bank shows the money having left. Refused, the same shape as the
        // refusal to delete an account a card is paid from.
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
