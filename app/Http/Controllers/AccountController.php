<?php

namespace App\Http\Controllers;

use App\DTO\AccountData;
use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\Currency;
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
            // ccy in the label because AccountData now refuses a brokerage that
            // settles into a differently-denominated bank, so the label is what
            // makes an incompatible target recognisable before the form refuses
            // it. Were the label just the name, a user would pick a bank, submit,
            // and be told the pairing is wrong without being told which two
            // currencies clashed.
            ->map(fn (Account $account) => [
                'label' => "{$account->name} ({$account->ccy})",
                'value' => $account->id,
            ])
            ->values();

        // The currency dropdown's options.
        //
        // Sent from the server rather than written into FormAccount.vue as a
        // literal, which is what the type and status pickers used to do. The
        // AccountType docblock claims that hardcoding "can no longer drift ahead
        // of the server" -- true, because the server rejects what the browser
        // offers -- but it can still drift *behind*: adding a case to the enum
        // would leave the dropdown short by one with nothing failing. Deriving
        // the list here removes the second failure mode as well.
        //
        // In enum declaration order rather than alphabetical, so the two
        // currencies the user is most likely to want lead and adding a case
        // lands where it was written.
        $currencyOptions = collect(Currency::cases())
            ->map(fn (Currency $currency) => [
                'label' => $currency->label(),
                'value' => $currency->value,
            ])
            ->values();

        // The type and status pickers' options, for the same reason and by the
        // same route. These two were the last option lists still written into the
        // template, which left a case added to either enum accepted by the server
        // and unoffered by the form -- a brokerage type the user could not
        // create, with no test failing anywhere.
        //
        // Plain value arrays, not {label, value} pairs like currencyOptions,
        // because these enums have no separate display name: the value is what
        // the user reads. Adding a label() to either enum is a visible change to
        // the form's wording, and belongs in a commit about wording rather than
        // one about staying in sync. The two shapes differ because the data
        // differs.
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
