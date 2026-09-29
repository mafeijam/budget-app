<?php

namespace App\Support;

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads the history a recurring rule can be spotted in. The only one of the three that
 * knows about tables, so the command and the button cannot drift into asking different
 * questions of the same ledger.
 */
class RecurringScan
{
    /**
     * A pair of years by default, which is as long as the question is worth asking: a yearly
     * rule needs three payments to be found, so a 24-month window cannot find one at all.
     *
     * @return list<array<string, mixed>>
     */
    public static function findings(int $months = 24, ?string $today = null): array
    {
        return RecurringPatterns::find(
            self::rows($months),
            self::rules(),
            $today ?? today()->toDateString()
        );
    }

    /**
     * Cash and card movements, from the types the enum says can recur, and without the other
     * side of a transfer: a card payment's row on the bank is half of a movement between two
     * of the user's own accounts, and a rule written for it would pay a card from a figure
     * the statement derived.
     *
     * The types come from the enum rather than from a list here, so a type that stops being
     * repeatable stops being scanned without this file being opened.
     *
     * @return list<array{account_id: int, account: string, type: string, description: string, ccy: string, date: string, amount: string, category_id: ?int}>
     */
    public static function rows(int $months): array
    {
        $types = collect(TransactionType::cases())
            ->filter(fn (TransactionType $type) => $type->canRecur())
            ->map(fn (TransactionType $type) => $type->value)
            ->all();

        return Transaction::query()
            ->with(['account', 'meta'])
            ->where('date', '>=', today()->subMonthsNoOverflow($months)->toDateString())
            ->whereIn('type', $types)
            ->whereHas('account', fn (Builder $query) => $query->whereIn('type', [
                AccountType::Cash->value,
                AccountType::Card->value,
            ]))
            ->orderBy('date')
            ->get()
            // In PHP and not in the query: the bag is a row on another table, and a JSON path
            // cannot be indexed, so this is a pass over what has been read either way.
            ->reject(fn (Transaction $row) => isset($row->meta?->meta['paired_transaction_id']))
            ->map(fn (Transaction $row) => [
                'account_id' => $row->account_id,
                'account' => $row->account->name,
                'type' => $row->type,
                'description' => $row->description,
                'ccy' => $row->ccy,
                'date' => $row->date,
                'amount' => $row->amount,
                'category_id' => $row->category_id,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, account_id: int, account: string, description: string, amount: string, frequency: string, start_date: string}>
     */
    public static function rules(): array
    {
        return RecurringTransaction::query()
            ->with('account')
            ->orderBy('id')
            ->get()
            ->map(fn (RecurringTransaction $rule) => [
                'id' => $rule->id,
                'account_id' => $rule->account_id,
                'account' => $rule->account->name,
                'description' => $rule->description,
                'amount' => $rule->amount,
                'frequency' => $rule->frequency,
                'start_date' => $rule->start_date,
            ])
            ->all();
    }
}
