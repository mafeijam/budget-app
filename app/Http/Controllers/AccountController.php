<?php

namespace App\Http\Controllers;

use App\DTO\AccountData;
use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\Currency;
use App\Models\Account;
use App\Models\Transaction;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\PaginatedDataCollection;

class AccountController extends Controller
{
    public function index(Request $r)
    {
        $formEmpty = AccountData::empty([
            'status' => 'active',
        ]);

        $accounts = Account::query()
            ->with('meta')
            ->orderBy($r->input('sort', 'created_at'), $r->input('dir', 'desc'))
            ->paginate($r->input('per_page', 5));

        $data = AccountData::collect($accounts, PaginatedDataCollection::class);

        // Cash only, and including inactive: every other type is refused by
        // AccountData, and a closed bank still holds history a dividend lands in.
        // Not the paginated set above, which would strand banks off page one.
        $settlementOptions = Account::query()
            ->where('type', AccountType::Cash->value)
            ->orderBy('name')
            ->get(['id', 'name', 'ccy'])
            // ccy in the label so an incompatible bank is recognisable before the
            // form refuses it.
            ->map(fn (Account $account) => [
                'label' => "{$account->name} ({$account->ccy})",
                'value' => $account->id,
            ])
            ->values();

        // Derived, not hardcoded in FormAccount.vue, which would drift *behind* the
        // enum. Enum order, so the likeliest currencies lead.
        $currencyOptions = collect(Currency::cases())
            ->map(fn (Currency $currency) => [
                'label' => $currency->label(),
                'value' => $currency->value,
            ])
            ->values();

        // Plain values, not {label, value} pairs: neither enum has a display name.
        $typeOptions = array_column(AccountType::cases(), 'value');

        $statusOptions = array_column(AccountStatus::cases(), 'value');

        $params = $r->query() + ['sort' => 'created_at', 'dir' => 'desc'];

        $meta = [
            'form' => 'account-form',
            'path' => '/accounts',
        ];

        return inertia('account', compact(
            'formEmpty',
            'data',
            'params',
            'meta',
            'settlementOptions',
            'currencyOptions',
            'typeOptions',
            'statusOptions',
        ));
    }

    public function store(AccountData $data)
    {
        DB::beginTransaction();

        try {
            $account = Account::create($data->except('meta_data')->toArray());

            $meta = collect($data->meta_data->all())->filter();

            if ($meta->isNotEmpty()) {
                $account->meta()->create([
                    'meta' => $meta,
                ]);
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();

            // Roll back but do not discard: every failure would otherwise look alike
            // from the outside. See AccountErrorReportingTest.
            report($e);

            return back()->with('message', 'error db...');
        }

        return back()->with('message', "Account [$account->name] created");
    }

    public function update(Account $account, AccountData $data)
    {
        DB::beginTransaction();

        try {
            $account->update($data->except('meta_data')->toArray());

            $meta = collect($data->meta_data?->all())->filter();

            if ($meta->isNotEmpty()) {
                $account->meta()->updateOrCreate(
                    ['id' => $account->meta?->id],
                    ['meta' => $meta]
                );
            } else {
                $account->meta()->delete();
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();

            // See store() above: report() logs the cause, the flash is unchanged.
            report($e);

            return back()->with('message', 'error db...');
        }

        return back()->with('message', "Account [$account->name] updated");

    }

    public function destroy(Account $account)
    {
        // The foreign key would refuse this, but only now the table has rows. Checked
        // first: an account with two faults should not report only one.
        $transactions = Transaction::where('account_id', $account->id)->count();

        if ($transactions > 0) {
            return back()->with('message', sprintf(
                'Account [%s] has %s transaction%s and cannot be deleted',
                $account->name,
                $transactions,
                $transactions === 1 ? '' : 's'
            ));
        }

        // The foreign key's referential check, done here because a JSON value carries
        // no constraint. Refuse rather than cascade or clear. JSON_UNQUOTE because
        // the value may be a number or the string a select emits, which MySQL does
        // not consider equal.
        $settledInto = Account::query()
            ->whereHas('meta', fn ($query) => $query->whereRaw(
                'JSON_UNQUOTE(JSON_EXTRACT(meta.meta, \'$.settlement_account_id\')) = ?',
                [(string) $account->id]
            ))
            ->pluck('name');

        if ($settledInto->isNotEmpty()) {
            return back()->with('message', sprintf(
                'Account [%s] is the settlement account for [%s] and cannot be deleted',
                $account->name,
                $settledInto->implode('], [')
            ));
        }

        $account->meta()->delete();
        $account->delete();

        return back()->with('message', "Account [$account->name] deleted");
    }
}
