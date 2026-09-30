<?php

namespace App\Console\Commands;

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Links a migrated trade to the bank row that already records its money, the pairing
 * TradeCash writes for a trade entered here.
 *
 * The migration kept the old ledger's cash row as typed and put the trade on the brokerage
 * with no_cash, so the bank was not charged twice. Unpaired, CashFlow reads that bank row
 * as spending rather than money invested: a HKD 100,000 buy was a month's worst spending.
 * Linked, the balance is unchanged -- the bank row was there all along -- and the row is
 * counted as invested.
 *
 * A match is the brokerage's settlement account, the trade's date and amount, and the
 * direction the trade moves cash, on a row no other trade has claimed. Anything else is
 * reported and left alone: a cash row that differs from quantity x price was flagged at
 * migration and needs a person. Only trades still marked no_cash with no partner are read,
 * so a second run finds nothing to do.
 */
class LinkTradeCash extends Command
{
    protected $signature = 'trades:link-cash
        {--apply : Write the links; without it the run only reports}';

    protected $description = 'Link migrated trades to the bank rows that record their cash, so cash flow counts them as invested';

    public function handle(): int
    {
        $trades = Transaction::query()
            ->with(['meta', 'account.meta'])
            ->whereIn('type', array_map(
                fn (TransactionType $type) => $type->value,
                array_filter(TransactionType::cases(), fn (TransactionType $type) => $type->derivesAmount())
            ))
            ->whereHas('account', fn ($q) => $q->where('type', AccountType::Security->value))
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->filter(fn (Transaction $trade) => ($trade->meta?->meta['no_cash'] ?? null) === true
                && ($trade->meta?->meta['paired_transaction_id'] ?? null) === null);

        $claimed = [];
        $links = [];
        $unmatched = [];

        foreach ($trades as $trade) {
            $bank = $trade->account->settlementAccount();
            $cash = $bank === null ? null : $this->cashRowFor($trade, $bank, $claimed);

            if ($cash === null) {
                $unmatched[] = [$trade->id, $trade->date, $trade->account->name, $trade->type, $trade->amount,
                    $bank === null ? 'brokerage names no settlement account' : 'no unclaimed bank row on the day for this amount'];

                continue;
            }

            $claimed[$cash->id] = true;
            $links[] = [$trade, $cash];
        }

        $this->table(
            ['Trade', 'Date', 'Brokerage', 'Type', 'Amount', 'Bank row', 'Bank', 'Description'],
            array_map(fn (array $pair) => [
                $pair[0]->id, $pair[0]->date, $pair[0]->account->name, $pair[0]->type, $pair[0]->amount,
                $pair[1]->id, $pair[1]->account->name, $pair[1]->description,
            ], $links)
        );

        if ($unmatched !== []) {
            $this->warn(sprintf('%d trade%s with no bank row to link, left as they are:', count($unmatched), count($unmatched) === 1 ? '' : 's'));
            $this->table(['Trade', 'Date', 'Brokerage', 'Type', 'Amount', 'Why'], $unmatched);
        }

        $this->line(sprintf('%d unlinked trades: %d to link, %d without a match.', $trades->count(), count($links), count($unmatched)));

        if (! $this->option('apply')) {
            $this->comment('Nothing written. Re-run with --apply to link them.');

            return self::SUCCESS;
        }

        // One transaction for the lot: a run stopped halfway would leave some trades linked
        // and the report of the next run no longer the report of this one.
        DB::transaction(function () use ($links) {
            foreach ($links as [$trade, $cash]) {
                $bag = $trade->meta->meta->getArrayCopy();
                unset($bag['no_cash']);
                $bag['paired_transaction_id'] = $cash->id;
                $trade->meta->update(['meta' => $bag]);

                $cashBag = $cash->meta?->meta?->getArrayCopy() ?? [];
                $cashBag['paired_transaction_id'] = $trade->id;
                $cash->meta()->updateOrCreate(['id' => $cash->meta?->id], ['meta' => $cashBag]);
            }
        });

        $this->info(sprintf('Linked %d trade%s.', count($links), count($links) === 1 ? '' : 's'));

        return self::SUCCESS;
    }

    /** @param array<int, true> $claimed bank rows already matched to an earlier trade */
    private function cashRowFor(Transaction $trade, Account $bank, array $claimed): ?Transaction
    {
        $direction = $trade->type === TransactionType::Buy->value
            ? TransactionType::Withdraw->value
            : TransactionType::Deposit->value;

        return Transaction::query()
            ->with(['meta', 'account'])
            ->where('account_id', $bank->id)
            ->where('date', $trade->date)
            ->where('type', $direction)
            ->where('amount', $trade->amount)
            ->orderBy('id')
            ->get()
            // A row already paired is some other trade's or a card settlement's.
            ->first(fn (Transaction $row) => ! isset($claimed[$row->id])
                && ($row->meta?->meta['paired_transaction_id'] ?? null) === null);
    }
}
