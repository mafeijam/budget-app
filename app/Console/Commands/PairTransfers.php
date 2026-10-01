<?php

namespace App\Console\Commands;

use App\Enums\AccountType;
use App\Enums\Currency;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Support\Fx;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pairs the money that only moved between the cash accounts, which CashFlow cannot find by
 * itself, so it stops reading it as spending on one side and income on the other.
 *
 * CashFlow finds a transfer whose two rows are the same day and amount (transfersAmong()).
 * These are the ones that are not:
 *
 * - **An exchange into another currency**, "EXCHANGE HKD TO YEN 160,000": only the HKD side
 *   was entered, and the two sides are never the same amount. The yen arrives as a deposit on
 *   the currency's cash account -- made, named as the description names the currency, when
 *   there is none -- and the two rows are paired, which classify() reads as a transfer.
 * - **A transfer that landed on another day**, "TRANSFER TO FUTU" on the 2nd and "TRANSFER
 *   FROM SAVING" on the 3rd: paired by the account each names, the amount, and three days at
 *   most, the nearest first. An amount alone a day apart is not enough -- it paired a transfer
 *   with a card payment.
 *
 * Then trades:link-cash, for the trades whose bank row was entered apart from them. Without
 * --apply nothing is written, and a second run finds nothing to do: only unpaired rows are read.
 */
class PairTransfers extends Command
{
    protected $signature = 'transfers:pair
        {--apply : Write the pairs; without it the run only reports}
        {--fetch : Fetch the rate history of a currency account it makes, from Yahoo}';

    protected $description = 'Pair currency exchanges and day-apart transfers between cash accounts, then link trade cash';

    /** The days a transfer may take to land on the other account. */
    private const DAYS_APART = 3;

    /** A currency as the descriptions spell it, where that is not its code. */
    private const SPELLED = ['YEN' => 'JPY'];

    public function handle(): int
    {
        $cash = Account::query()->where('type', AccountType::Cash->value)->orderBy('id')->get();
        $exchanges = $this->exchanges($cash);
        $transfers = $this->transfers($cash);

        $this->line('Exchanges into another currency:');
        $this->table(
            ['Row', 'Date', 'From', 'Out', 'Into', 'In', 'Description'],
            array_map(fn (array $e) => [
                $e['out']->id, $e['out']->date, $e['out']->account->name, "{$e['out']->amount} {$e['out']->account->ccy}",
                $e['account']?->name ?? "{$e['name']} (new, {$e['ccy']})", "{$e['amount']} {$e['ccy']}", $e['out']->description,
            ], $exchanges)
        );

        $this->line('Transfers that landed on another day:');
        $this->table(
            ['Out', 'Date', 'From', 'In', 'Date', 'To', 'Amount'],
            array_map(fn (array $t) => [
                $t[0]->id, $t[0]->date, $t[0]->account->name, $t[1]->id, $t[1]->date, $t[1]->account->name, $t[0]->amount,
            ], $transfers)
        );

        $this->line(sprintf('%d exchange%s and %d transfer%s to pair.', count($exchanges), count($exchanges) === 1 ? '' : 's', count($transfers), count($transfers) === 1 ? '' : 's'));

        $created = [];

        if ($this->option('apply')) {
            // One transaction for the lot, as trades:link-cash: a run stopped halfway would
            // leave the next run's report not this one's.
            $created = DB::transaction(fn () => $this->write($exchanges, $transfers));

            $this->info(sprintf('Paired %d exchange%s and %d transfer%s.', count($exchanges), count($exchanges) === 1 ? '' : 's', count($transfers), count($transfers) === 1 ? '' : 's'));
        } else {
            $this->comment('Nothing written. Re-run with --apply to pair them.');
        }

        $this->newLine();
        $this->call('trades:link-cash', $this->option('apply') ? ['--apply' => true] : []);

        foreach ($created as $account) {
            if (! $this->option('fetch')) {
                $this->comment("Made [{$account->name}] in {$account->ccy}: fetch its rates with prices:fetch --history --symbol=".Fx::pair($account->ccy).', or every total leaves it out.');

                continue;
            }

            $this->call('prices:fetch', ['--history' => true, '--symbol' => [Fx::pair($account->ccy)]]);
        }

        return self::SUCCESS;
    }

    /**
     * Each unpaired withdrawal worded as an exchange out of its own account's currency, with
     * where its money goes.
     *
     * @param  Collection<int, Account>  $cash
     * @return list<array{out: Transaction, ccy: string, amount: string, name: string, account: ?Account}>
     */
    private function exchanges(Collection $cash): array
    {
        $codes = implode('|', [...array_column(Currency::cases(), 'value'), ...array_keys(self::SPELLED)]);
        $found = [];

        foreach ($this->unpaired($cash, TransactionType::Withdraw) as $out) {
            if (! preg_match("/^EXCHANGE\\s+([A-Z]{3})\\s+TO\\s+({$codes})\\s+([\\d,]+(?:\\.\\d+)?)\\b/i", (string) $out->description, $m)) {
                continue;
            }

            $word = strtoupper($m[2]);
            $ccy = self::SPELLED[$word] ?? $word;

            // Out of the account's own currency, into another: anything else is not this.
            if (strtoupper($m[1]) !== $out->account->ccy || $ccy === $out->account->ccy) {
                continue;
            }

            $found[] = [
                'out' => $out,
                'ccy' => $ccy,
                'amount' => str_replace(',', '', $m[3]),
                'name' => $word,
                'account' => $cash->first(fn (Account $account) => $account->ccy === $ccy),
            ];
        }

        return $found;
    }

    /**
     * Each "TRANSFER TO <account>" with the "TRANSFER FROM <its account>" on that account,
     * the same amount, a day or more and DAYS_APART at most away, the nearest first. The
     * same day is CashFlow's to find, and is left alone.
     *
     * @param  Collection<int, Account>  $cash
     * @return list<array{0: Transaction, 1: Transaction}>
     */
    private function transfers(Collection $cash): array
    {
        $byName = $cash->keyBy(fn (Account $account) => mb_strtoupper($account->name));
        $withdrawals = $this->unpaired($cash, TransactionType::Withdraw);
        $deposits = $this->unpaired($cash, TransactionType::Deposit);

        // A row with an opposite on its own day is CashFlow's transfer already, and pairing it
        // across days instead crossed two transfers of the same amount a day apart: the 10th's
        // withdrawal with the 11th's deposit, each with its own same-day partner.
        $sameDay = fn (Transaction $row, Collection $others) => $others->contains(fn (Transaction $other) => $other->date === $row->date
            && $other->amount === $row->amount
            && $other->account->ccy === $row->account->ccy);
        $deposits = $deposits->reject(fn (Transaction $row) => $sameDay($row, $withdrawals))->values();
        $claimed = [];
        $pairs = [];

        foreach ($withdrawals as $out) {
            if ($sameDay($out, $deposits) || $sameDay($out, $this->unpaired($cash, TransactionType::Deposit))
                || ! preg_match('/^TRANSFER\s+TO\s+(.+?)\s*$/i', (string) $out->description, $m)) {
                continue;
            }

            $to = $byName[mb_strtoupper($m[1])] ?? null;

            if ($to === null || $to->id === $out->account_id || $to->ccy !== $out->account->ccy) {
                continue;
            }

            $from = mb_strtoupper($out->account->name);
            $day = Carbon::parse($out->date);

            $in = $deposits
                ->filter(fn (Transaction $row) => $row->account_id === $to->id
                    && ! isset($claimed[$row->id])
                    && $row->amount === $out->amount
                    && $row->date !== $out->date
                    && abs($day->diffInDays(Carbon::parse($row->date))) <= self::DAYS_APART
                    && preg_match('/^TRANSFER\s+FROM\s+'.preg_quote($from, '/').'\s*$/i', (string) $row->description))
                ->sortBy(fn (Transaction $row) => abs($day->diffInDays(Carbon::parse($row->date))))
                ->first();

            if ($in !== null) {
                $claimed[$in->id] = true;
                $pairs[] = [$out, $in];
            }
        }

        return $pairs;
    }

    /**
     * The rows of a type on the cash accounts that nothing pairs yet.
     *
     * @param  Collection<int, Account>  $cash
     * @return Collection<int, Transaction>
     */
    private function unpaired(Collection $cash, TransactionType $type): Collection
    {
        return Transaction::query()
            ->with(['meta', 'account'])
            ->whereIn('account_id', $cash->pluck('id'))
            ->where('type', $type->value)
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->filter(fn (Transaction $row) => ($row->meta?->meta['paired_transaction_id'] ?? null) === null)
            ->values();
    }

    /**
     * @param  list<array{out: Transaction, ccy: string, amount: string, name: string, account: ?Account}>  $exchanges
     * @param  list<array{0: Transaction, 1: Transaction}>  $transfers
     * @return list<Account> the currency accounts it had to make
     */
    private function write(array $exchanges, array $transfers): array
    {
        $made = [];

        foreach ($exchanges as $exchange) {
            $account = $exchange['account'] ?? $made[$exchange['ccy']] ??= Account::create([
                'name' => $exchange['name'],
                'status' => 'active',
                'type' => AccountType::Cash->value,
                'ccy' => $exchange['ccy'],
            ]);

            $in = Transaction::create([
                'account_id' => $account->id,
                'category_id' => null,
                'date' => $exchange['out']->date,
                'type' => TransactionType::Deposit->value,
                'description' => $exchange['out']->description,
                'amount' => $exchange['amount'],
                'ccy' => $exchange['ccy'],
                'status' => $exchange['out']->status,
            ]);

            $this->pair($exchange['out'], $in);
        }

        foreach ($transfers as [$out, $in]) {
            $this->pair($out, $in);
        }

        return array_values($made);
    }

    /** Each row's bag names the other, as settle() and TradeCash pair theirs. */
    private function pair(Transaction $a, Transaction $b): void
    {
        foreach ([[$a, $b], [$b, $a]] as [$row, $other]) {
            $bag = $row->meta?->meta?->getArrayCopy() ?? [];
            $bag['paired_transaction_id'] = $other->id;
            $row->meta()->updateOrCreate(['id' => $row->meta?->id], ['meta' => $bag]);
        }
    }
}
