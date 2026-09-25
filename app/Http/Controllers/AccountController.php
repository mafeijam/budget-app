<?php

namespace App\Http\Controllers;

use App\DTO\AccountData;
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

        $params = $r->query() + ['sort' => 'created_at', 'dir' => 'desc'];

        $meta = [
            'form' => 'account-form',
            'path' => '/accounts',
        ];

        return inertia('account', compact('formEmpty', 'data', 'params', 'meta'));
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
