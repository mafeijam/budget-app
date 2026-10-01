<?php

namespace App\Http\Controllers;

use App\DTO\AccountData;
use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\Currency;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Support\AccountBalance;
use App\Support\CardStatement;
use App\Support\Fx;
use App\Support\NetWorth;
use App\Support\Positions;
use Brick\Math\BigDecimal;
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
        $form = self::formProps();

        // Every account on one page, grouped by type on screen: a household has a dozen, and
        // paged by ten the second page hid cards whose statements were due. Still a paginator,
        // because saving and deleting reload through it.
        $accounts = Account::query()
            ->with('meta')
            ->orderBy('name')
            ->paginate(max(1, Account::query()->count()));

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

        // The Net worth page's figures, so a section's subtotal is the figure that page shows.
        ['today' => $summary, 'trends' => $trends] = (new NetWorth)->accountTrends(today());

        $statements = $this->nextStatements();

        // Each account's latest row, pending included: the day it was last used.
        $lastUsed = Transaction::query()
            ->selectRaw('account_id, MAX(date) AS last_date')
            ->groupBy('account_id')
            ->pluck('last_date', 'account_id');
        $base = Fx::BASE->value;

        $params = ['sort' => 'name', 'dir' => 'asc'];

        return inertia('account', [...$form, ...compact(
            'data',
            'params',
            'balances',
            'marketValues',
            'summary',
            'trends',
            'statements',
            'lastUsed',
            'base',
            'refusals',
        )]);
    }

    /**
     * What FormAccount needs, and nothing of the list's: the accounts page sends it with the list, and
     * the Add menu asks for it alone (FormContextController) to open the form over any other
     * page. One method, so the dialog there cannot drift from the one here.
     *
     * @return array<string, mixed>
     */
    public static function formProps(): array
    {
        // In the base currency unless changed: nearly every account here is.
        $formEmpty = AccountData::empty([
            'status' => 'active',
            'ccy' => Fx::BASE->value,
        ]);

        $settlementOptions = Account::settlementOptions();

        $currencyOptions = collect(Currency::cases())
            ->map(fn (Currency $currency) => [
                'label' => $currency->label(),
                'value' => $currency->value,
            ])
            ->values();

        $typeOptions = array_column(AccountType::cases(), 'value');

        $statusOptions = array_column(AccountStatus::cases(), 'value');

        $meta = [
            'form' => 'account-form',
            'path' => '/accounts',
        ];

        return compact('formEmpty', 'meta', 'settlementOptions', 'currencyOptions', 'typeOptions', 'statusOptions');
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
     * Each card's earliest statement with something owed, and how many more are open after
     * it. An overpaid period is not settled either, but it is a credit rather than a bill,
     * so it is not the thing a row should say is due.
     *
     * @return array<int, array<string, mixed>>
     */
    private function nextStatements(): array
    {
        $cards = Account::query()->where('type', AccountType::Card->value)->with('meta')->get();
        $next = [];

        foreach (CardStatement::forAccounts($cards) as $cardId => $periods) {
            $owing = $periods
                ->filter(fn (CardStatement $period) => BigDecimal::of($period->owed())->isPositive())
                ->sortBy(fn (CardStatement $period) => $period->toArray()['due_date'])
                ->values();

            if ($owing->isNotEmpty()) {
                $next[$cardId] = [...$owing->first()->toArray(), 'more' => $owing->count() - 1];
            }
        }

        return $next;
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

        $dividends = Transaction::query()
            ->where('type', TransactionType::Dividend->value)
            ->with('meta')
            ->get(['id'])
            ->countBy(fn (Transaction $row) => (string) ($row->meta?->meta['brokerage_account_id'] ?? ''));

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

            $paid = (int) ($dividends[(string) $account->id] ?? 0);

            if ($paid > 0) {
                $refusals[$account->id] = sprintf(
                    'Account [%s] is named by %s dividend%s and cannot be deleted. Set it to '
                        .'inactive instead.',
                    $account->name,
                    $paid,
                    $paid === 1 ? '' : 's'
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
