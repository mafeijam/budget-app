<?php

namespace App\Support;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;

/**
 * What is still owed on each loan on a day, from the rows loans:tag named as its drawdown and
 * repayments. No loan account: the money borrowed landed in a cash account and the repayments
 * left one, so a loan is a reading of rows already there.
 *
 * The meta keys, all server-owned (see TransactionData::SERVER_LINKS):
 *
 * - `loan`: the loan's name, on every row of it.
 * - `loan_principal`: on a repayment, the part of it that paid the loan down. The rest of the
 *   row is interest and stays spending; counting the whole payment left the tax loan 7,776
 *   overpaid, and net worth owed money for good.
 * - `loan_borrowed` and `loan_repaid_before`: on the first recorded repayment of a loan whose
 *   drawdown predates the records, so what was owed when they start is the two apart.
 *
 * A tagged row with no principal is the drawdown, its own amount the money borrowed.
 */
class Loans
{
    public const KEYS = ['loan', 'loan_principal', 'loan_borrowed', 'loan_repaid_before'];

    /** @var list<array{loan: string, date: string, ccy: string, change: BigDecimal}> oldest first */
    private array $moves = [];

    public function __construct()
    {
        $rows = self::tagged()
            ->with(['meta', 'account'])
            ->whereIn('status', TransactionStatus::countingTowardBalance())
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $bag = $row->meta->meta;
            $move = fn (string $date, BigDecimal $change) => $this->moves[] = [
                'loan' => $bag['loan'], 'date' => $date, 'ccy' => $row->account->ccy, 'change' => $change,
            ];

            // Owed from the day the records start, not from the payment that states it.
            if (isset($bag['loan_borrowed'])) {
                $move($row->date, BigDecimal::of($bag['loan_borrowed'])->minus($bag['loan_repaid_before'] ?? '0'));
            }

            if (! isset($bag['loan_principal'])) {
                // A card states its own amount, so a drawdown onto one is card_amount.
                $move($row->date, BigDecimal::of($bag['card_amount'] ?? $row->amount));

                continue;
            }

            /* A card instalment is repaid on its statement's due date, when the money leaves
               the cash, not on the day it is charged. Net worth leaves card debt out, so on
               the charge date the instalment came off the loan and went nowhere, and net worth
               read a month of it high. */
            $move($bag['due_date'] ?? $row->date, BigDecimal::of($bag['loan_principal'])->negated());
        }

        // A due date is weeks after the charge, so the moves are put back in date order for
        // owedOn(), which stops at the first one past the day.
        usort($this->moves, fn (array $a, array $b) => $a['date'] <=> $b['date']);
    }

    /** @return Builder<Transaction> */
    public static function tagged(): Builder
    {
        return Transaction::query()->whereHas('meta', fn (Builder $meta) => $meta->whereNotNull('meta->loan'));
    }

    /**
     * Each loan still owed at the end of a day, in its own currency. A loan paid off is left
     * out, so an empty list is nothing owed.
     *
     * @return list<array{name: string, ccy: string, owed: BigDecimal}>
     */
    public function owedOn(string $day): array
    {
        $owed = [];

        foreach ($this->moves as $move) {
            if ($move['date'] > $day) {
                break;
            }

            $owed[$move['loan']] ??= ['name' => $move['loan'], 'ccy' => $move['ccy'], 'owed' => BigDecimal::zero()];
            $owed[$move['loan']]['owed'] = $owed[$move['loan']]['owed']->plus($move['change']);
        }

        return array_values(array_filter($owed, fn (array $loan) => ! $loan['owed']->isZero()));
    }
}
