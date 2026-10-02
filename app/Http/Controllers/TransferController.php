<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Transaction;
use App\Support\Transfer;
use Brick\Math\BigDecimal;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Money moved between two cash accounts, as one action rather than a withdrawal and a deposit
 * entered apart and paired afterwards by transfers:pair. See App\Support\Transfer.
 */
class TransferController extends Controller
{
    public function store(Request $r)
    {
        $input = $this->validated($r);

        DB::transaction(fn () => Transfer::write($input));

        return back()->with('message', $this->recorded($input, 'recorded'));
    }

    /** Either half names the transfer; both are rewritten. */
    public function update(Transaction $transaction, Request $r)
    {
        $pair = Transfer::of($transaction);

        if ($pair === null) {
            throw ValidationException::withMessages(['from_account_id' => 'This transaction is not one half of a transfer.']);
        }

        $input = $this->validated($r);

        try {
            DB::transaction(fn () => Transfer::write($input, $pair));
        } catch (Exception $e) {
            report($e);

            return back()->with('message', 'error db...');
        }

        return back()->with('message', $this->recorded($input, 'updated'));
    }

    /**
     * @return array{from: Account, to: Account, date: string, amount: string, amount_in: string, description: ?string, status: string}
     */
    private function validated(Request $r): array
    {
        $cash = Rule::exists('accounts', 'id')->where('type', AccountType::Cash->value);
        $money = ['decimal:0,4', 'gt:0', 'max:99999999.9999'];

        $input = $r->validate([
            'from_account_id' => ['required', 'integer', $cash],
            'to_account_id' => ['required', 'integer', $cash, 'different:from_account_id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', ...$money],
            'amount_in' => ['nullable', ...$money],
            'description' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(TransactionStatus::class)],
        ], [
            'to_account_id.different' => 'Pick another account to move the money into.',
        ], [
            'from_account_id' => 'from account',
            'to_account_id' => 'to account',
            'amount_in' => 'amount received',
        ]);

        $from = Account::findOrFail($input['from_account_id']);
        $to = Account::findOrFail($input['to_account_id']);
        $amountIn = $input['amount_in'] ?? null;

        // Between currencies the two sides are two figures, and only the user knows the second:
        // made up from a rate, it would put a yen balance a few hundred off with nothing to say so.
        if ($from->ccy !== $to->ccy && $amountIn === null) {
            throw ValidationException::withMessages(['amount_in' => "Enter how much arrived in {$to->ccy}."]);
        }

        // In one currency the two sides are the same money.
        if ($from->ccy === $to->ccy) {
            if ($amountIn !== null && ! BigDecimal::of($amountIn)->isEqualTo($input['amount'])) {
                throw ValidationException::withMessages(['amount_in' => 'Both accounts are '.$from->ccy.', so the amount received is the amount sent.']);
            }

            $amountIn = $input['amount'];
        }

        return [
            'from' => $from,
            'to' => $to,
            'date' => $input['date'],
            'amount' => (string) $input['amount'],
            'amount_in' => (string) $amountIn,
            'description' => $input['description'] ?? null,
            'status' => $input['status'] ?? TransactionStatus::Posted->value,
        ];
    }

    private function recorded(array $input, string $done): string
    {
        return $input['from']->ccy === $input['to']->ccy
            ? "Transfer of {$input['amount']} {$input['from']->ccy} from [{$input['from']->name}] to [{$input['to']->name}] {$done}"
            : "Exchange of {$input['amount']} {$input['from']->ccy} into {$input['amount_in']} {$input['to']->ccy} {$done}";
    }
}
