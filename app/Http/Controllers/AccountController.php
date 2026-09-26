<?php

namespace App\Http\Controllers;

use App\DTO\AccountData;
use App\Enums\AccountType;
use App\Models\Account;
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

        // The settlement picker's options. Filtered to cash because every other
        // type is refused by AccountData, so offering one would offer a choice
        // that cannot be submitted. Inactive accounts are kept: status is
        // orthogonal to settlement and a closed bank still holds the history a
        // dividend arrives into.
        //
        // Deliberately not the paginated result set. The table above pages at 5,
        // so reusing it would make every bank off the first page unselectable
        // while the form still looked complete. Cash accounts are one-per-bank
        // and few, so an unbounded list is the right trade against a picker that
        // silently cannot reach a valid target.
        $settlementOptions = Account::query()
            ->where('type', AccountType::Cash->value)
            ->orderBy('name')
            ->get(['id', 'name', 'ccy'])
            // ccy in the label because nothing stops a brokerage settling into a
            // bank holding a different currency, and the rate that covers the
            // difference is not wired up yet. Showing it surfaces the choice
            // rather than hiding a conversion the user is not making.
            ->map(fn (Account $account) => [
                'label' => "{$account->name} ({$account->ccy})",
                'value' => $account->id,
            ])
            ->values();

        $params = $r->query() + ['sort' => 'created_at', 'dir' => 'desc'];

        $meta = [
            'form' => 'account-form',
            'path' => '/accounts',
        ];

        return inertia('account', compact('formEmpty', 'data', 'params', 'meta', 'settlementOptions'));
    }

    public function store(AccountData $data)
    {
        // return AccountData::getValidationRules(request()->all());
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

            // Rolling back is right -- these are multi-write operations and a
            // half-applied account is worse than none. Discarding the exception
            // is not: every failure below looked identical from the outside, a
            // 302 reading "error db...", with no record of what actually went
            // wrong. report() sends it to the exception handler, which logs it
            // like any other error. The user-facing response is unchanged.
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

                // $account->meta?->update(['meta' => $meta]);
            } else {
                $account->meta()->delete();
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();

            // See store() above. report() logs the cause; the generic flash is
            // kept so the existing frontend behaviour is untouched.
            report($e);

            return back()->with('message', 'error db...');
        }

        return back()->with('message', "Account [$account->name] updated");

    }

    public function destroy(Account $account)
    {
        $account->meta()->delete();
        $account->delete();

        sleep(1);

        return back()->with('message', "Account [$account->name] deleted");
    }
}
