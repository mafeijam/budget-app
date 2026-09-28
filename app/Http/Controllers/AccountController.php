<?php

namespace App\Http\Controllers;

use App\DTO\AccountData;
use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\Currency;
use App\Models\Account;
use App\Models\Transaction;
use App\Support\AccountBalance;
use App\Support\Positions;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
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

        // One query for the page rather than one per row. A securities account is
        // absent from the map, which is how the column knows to leave it blank.
        $balances = AccountBalance::forAccounts($accounts->getCollection());

        // A brokerage has no balance -- AccountType::hasBalance() -- so its Balance cell
        // shows what its holdings are worth instead, from the valuation the Positions
        // page totals, with how many holdings had no price. Read by id from the models:
        // the collection holds AccountData by now, and valued() reads an Account.
        $marketValues = Account::query()
            ->whereIn('id', $accounts->getCollection()->pluck('id'))
            ->where('type', AccountType::Security->value)
            ->get()
            ->mapWithKeys(fn (Account $broker) => [
                $broker->id => Arr::only(Positions::valued($broker)['totals'], ['market_value', 'unpriced', 'open']),
            ])
            ->all();

        // Why each account on this page cannot be deleted, so the button says so rather
        // than asking for a confirmation the server then turns down.
        $refusals = $this->deleteRefusals($accounts->getCollection());

        // Cash accounts only, and not the paginated set above, which would strand
        // banks off page one. On the model rather than here, because the settle dialog
        // offers the same list and two copies would drift.
        $settlementOptions = Account::settlementOptions();

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
            'balances',
            'marketValues',
            'refusals',
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

    /**
     * Why each account in a set cannot be deleted, keyed by id, for the ones that cannot.
     *
     * One method for destroy() and for the index, which sends these so the delete
     * button can be disabled and say why before the user is asked to confirm -- the
     * same sentence in both places, so the tooltip and the refusal cannot drift. Each
     * refusal names the way out, since a refusal without one is a dead end.
     *
     * Two queries for any number of accounts, not two per row: the index asks this
     * for a whole page.
     *
     * Models or DTOs alike, and so no type on the rows below: the index passes the
     * paginator's collection after Data::collect() has replaced its models with
     * AccountData, and only id and name are read.
     *
     * @param  Collection<int, Account|AccountData>  $accounts
     * @return array<int, string>
     */
    private function deleteRefusals(Collection $accounts): array
    {
        $ids = $accounts->pluck('id')->all();

        // The foreign key would refuse this, but only now the table has rows. Checked
        // first: an account with two faults should not report only one.
        $transactions = Transaction::query()
            ->whereIn('account_id', $ids)
            ->selectRaw('account_id, COUNT(*) AS n')
            ->groupBy('account_id')
            ->pluck('n', 'account_id');

        // The foreign key's referential check, done here because a JSON value carries
        // no constraint. Keyed by the target as a string, because the stored value may
        // be a number or the string a select emits, and the two must match alike.
        $settlers = Account::query()
            ->with('meta')
            ->get()
            ->filter(fn (Account $settler) => in_array(
                (string) ($settler->meta?->meta['settlement_account_id'] ?? ''),
                array_map('strval', $ids),
                true
            ))
            ->groupBy(fn (Account $settler) => (string) $settler->meta->meta['settlement_account_id']);

        $refusals = [];

        foreach ($accounts as $account) {
            $count = (int) ($transactions[$account->id] ?? 0);

            if ($count > 0) {
                $refusals[$account->id] = sprintf(
                    'Account [%s] has %s transaction%s and cannot be deleted. Set it to inactive '
                        .'instead, which keeps its history and hides it from new transactions.',
                    $account->name,
                    $count,
                    $count === 1 ? '' : 's'
                );

                continue;
            }

            $names = $settlers->get((string) $account->id)?->pluck('name');

            if ($names?->isNotEmpty()) {
                $refusals[$account->id] = sprintf(
                    'Account [%s] is the settlement account for [%s] and cannot be deleted. '
                        .'Point %s at another account first.',
                    $account->name,
                    $names->implode('], ['),
                    $names->count() === 1 ? 'it' : 'them'
                );
            }
        }

        return $refusals;
    }

    public function destroy(Account $account)
    {
        $refusal = $this->deleteRefusals(collect([$account]))[$account->id] ?? null;

        if ($refusal !== null) {
            return back()->with('message', $refusal);
        }

        $account->meta()->delete();
        $account->delete();

        return back()->with('message', "Account [$account->name] deleted");
    }
}
