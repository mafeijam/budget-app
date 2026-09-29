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

        // A securities account is absent from the map, so its column stays blank.
        $balances = AccountBalance::forAccounts($accounts->getCollection());

        // Re-read as models: the collection holds AccountData by now, and valued()
        // needs an Account.
        $marketValues = Account::query()
            ->whereIn('id', $accounts->getCollection()->pluck('id'))
            ->where('type', AccountType::Security->value)
            ->get()
            ->mapWithKeys(fn (Account $broker) => [
                $broker->id => Arr::only(Positions::valued($broker)['totals'], ['market_value', 'unpriced', 'open']),
            ])
            ->all();

        $refusals = $this->deleteRefusals($accounts->getCollection());

        $settlementOptions = Account::settlementOptions();

        $currencyOptions = collect(Currency::cases())
            ->map(fn (Currency $currency) => [
                'label' => $currency->label(),
                'value' => $currency->value,
            ])
            ->values();

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

            // Otherwise every failure looks alike behind the flash.
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

            report($e);

            return back()->with('message', 'error db...');
        }

        return back()->with('message', "Account [$account->name] updated");
    }

    /**
     * Shared with destroy() so the button's tooltip and the refusal cannot drift.
     * Rows may be DTOs: the index calls this after Data::collect().
     *
     * @param  Collection<int, Account|AccountData>  $accounts
     * @return array<int, string>
     */
    private function deleteRefusals(Collection $accounts): array
    {
        $ids = $accounts->pluck('id')->all();

        $transactions = Transaction::query()
            ->whereIn('account_id', $ids)
            ->selectRaw('account_id, COUNT(*) AS n')
            ->groupBy('account_id')
            ->pluck('n', 'account_id');

        // A JSON value has no foreign key. Compared as strings: the stored id may be a
        // number or the string a select emits.
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
