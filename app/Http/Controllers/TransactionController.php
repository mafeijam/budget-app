<?php

namespace App\Http\Controllers;

use App\DTO\TransactionData;
use App\Enums\AccountType;
use App\Enums\Currency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
}
