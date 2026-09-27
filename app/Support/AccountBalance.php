<?php

namespace App\Support;

use App\DTO\AccountData;
use App\Enums\AccountType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What each account is worth: money in a bank, a card's position against it.
 *
 * A net position, so every row in the column points the same way. A card owing
 * 45.25 reads -45.25, because the user is down that much -- which is the opposite
 * sign to CardStatement::owed(), and deliberately so. That is a period's debt and
 * stays positive; this is an account's standing and is negative when it owes. The
 * two are computed separately and the statement query carries its own CASE, so
 * neither reads the other's answer.
 *
 * One query for a whole page of accounts rather than one per account. A balance is a
 * sum over every row its account has, so it cannot be read off the page the way
 * account_name is; reading it per account would make a five-row page five scans.
 * Account::settlementAccount() is already one query per call and cannot avoid it, and
 * this can.
 *
 * The signs come from TransactionType::movesBalanceOn() and the list of statuses
 * from TransactionStatus::countingTowardBalance(), both read from the enums rather
 * than written here, so a case added to either is counted rather than left out --
 * and a balance that quietly excluded a state nobody told it to is the failure this
 * app has been bitten by before.
 *
 * Card totals read the stated card-currency figure where a charge has one, through
 * CardStatement::cardCurrencySql(), so a USD charge on an HKD card contributes what
 * the card actually owes rather than the raw foreign amount.
 *
 * Money is never a float, for the reason given in App\Support\CardStatement.
 */
class AccountBalance
{
    /**
     * The balance of every account in a set that has one, keyed by account id.
     *
     * Every account whose type has a balance is present, at '0.0000' where it has no
     * transactions: a bank with nothing in it holds nothing, and leaving it out would
     * make the table's blank mean "not computed" where it should mean "nothing".
     * An account with no balance at all -- a securities account, per
     * AccountType::hasBalance() -- is absent rather than zero.
     *
     * @param  Collection<int, Account|AccountData>  $accounts
     * @return array<int, string> account id => a decimal at the amount column's scale
     */
    public static function forAccounts(Collection $accounts): array
    {
        $zero = '0.0000';

        $balances = [];

        foreach ($accounts as $account) {
            if (self::typeOf($account)->hasBalance()) {
                $balances[$account->id] = $zero;
            }
        }

        if ($balances === []) {
            return [];
        }

        $signs = self::signCases();
        $types = array_keys($signs);

        if ($types === []) {
            return $balances;
        }

        $cases = implode(' ', $signs);
        $counting = implode("', '", TransactionStatus::countingTowardBalance());
        $placeholders = implode(', ', array_fill(0, count($balances), '?'));

        $rows = DB::select(
            // LEFT JOIN, because a charge on a card with no statement day has no bag
            // and an inner join would drop it from the total -- the charge would be
            // missing from what the card owes with nothing reporting it.
            "SELECT t.account_id,
                    SUM(CASE {$cases} ELSE 0 END) AS balance
               FROM transactions t
               LEFT JOIN meta m ON m.model_id = t.id AND m.model_type = ?
              WHERE t.account_id IN ({$placeholders})
                AND t.status IN ('{$counting}')
                AND t.type IN ('".implode("', '", $types)."')
           GROUP BY t.account_id",
            // Transaction::class, not Account::class: the bag read is the
            // transaction's, and the morph is what tells the two apart.
            [Transaction::class, ...array_keys($balances)]
        );

        foreach ($rows as $row) {
            $balances[(int) $row->account_id] = self::decimal($row->balance);
        }

        return $balances;
    }

    /**
     * The SUM's CASE arms, keyed by transaction type.
     *
     * An array rather than the finished SQL so that the same set drives both the CASE
     * and the WHERE: a type in the query is a type the arithmetic knows what to do
     * with, and neither can grow without the other.
     *
     * @return array<string, string> type value => `WHEN 'type' THEN ±figure`
     */
    private static function signCases(): array
    {
        $cases = [];

        foreach (TransactionType::cases() as $type) {
            foreach ($type->accountTypes() as $accountType) {
                if (! $accountType->hasBalance()) {
                    continue;
                }

                $sign = $type->movesBalanceOn($accountType);

                if ($sign === 0) {
                    continue;
                }

                // A charge is the one type read through its bag. The expression is
                // CardStatement's, so a card total and a statement panel cannot
                // disagree about what a cross-currency charge is worth.
                $figure = $type === TransactionType::Charge
                    ? CardStatement::cardCurrencySql()
                    : 't.amount';

                $cases[$type->value] = sprintf(
                    "WHEN t.type = '%s' THEN %s%s",
                    $type->value,
                    $sign < 0 ? '-' : '',
                    $figure
                );
            }
        }

        return $cases;
    }

    /**
     * The account's type, whether it arrived as a model or as a DTO.
     *
     * Both are accepted because the caller's collection is not stable: a model carries
     * `type` as the string the column holds, and AccountData::collect() replaces a
     * paginator's collection with DTOs, whose `type` is already the enum. Reading one
     * shape and being handed the other is a 500, and it is a 500 that appears only
     * once someone reorders the controller -- so both are taken, and neither the
     * caller nor this class has to know which it got.
     */
    private static function typeOf(Account|AccountData $account): AccountType
    {
        return $account->type instanceof AccountType
            ? $account->type
            : AccountType::from($account->type);
    }

    /**
     * A decimal at exactly the amount column's scale, as a plain string.
     *
     * The same normalisation CardStatement does, and for the same reason: an
     * account whose rows all cancelled comes back as an integer 0 and would read
     * "0" beside "120.0000", so every figure in this column has one shape.
     */
    private static function decimal(int|string $value): string
    {
        return BigDecimal::of((string) $value)->toScale(4)->toString();
    }
}
