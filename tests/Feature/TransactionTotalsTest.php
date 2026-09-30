<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsACard;
use Tests\TestCase;

/**
 * The base-currency total under the transactions list.
 *
 * The per-currency strips beside it are TransactionFilterTest's, and are unchanged: a
 * currency is reported in its own money and the strips are not a second opinion on that.
 * What is here is the one row that adds them up, which only appears when the filter
 * holds more than one currency.
 *
 * A foreign charge states the card's own currency in card_amount, and that is the figure
 * AccountBalance and CashFlow both sum. So the total needs no rate and no fetch: the row
 * already carries the number, for the card that took the charge. What it does not carry
 * is a number for any other kind of foreign row, and those are named rather than counted.
 */
class TransactionTotalsTest extends TestCase
{
    use BuildsACard, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCard();
    }

    public function test_a_foreign_charge_is_totalled_at_the_figure_its_card_states(): void
    {
        // 79.88 Australian dollars on an HKD card, which states 624.00 as what it is owed.
        // The total is the card's figure: adding the charge's own 79.88 to a list of HKD
        // rows would answer a different question, and one nobody asked.
        $this->foreignCharge('2026-01-05', 'AUD', '79.8800', '624.0000');
        $this->cash('2026-01-06', 'deposit', '1000.0000', 'HKD');

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('baseTotals', [
                'count' => 2,
                'in' => '1000.0000',
                'out' => '624.0000',
                'net' => '376.0000',
                'trades' => '0.0000',
            ])
            // Unchanged by all of this: the strip still reports the charge in its own money.
            ->where('totals.0.ccy', 'AUD')
            ->where('totals.0.out', '79.8800')
            ->where('totals.1.ccy', 'HKD')
            ->where('totals.1.in', '1000.0000')
        );
    }

    public function test_a_list_of_one_currency_has_no_base_total(): void
    {
        // The common case, and the row would repeat the strip beneath it in other words.
        $this->cash('2026-01-05', 'deposit', '1000.0000', 'HKD');
        $this->cash('2026-01-06', 'withdraw', '250.0000', 'HKD');

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('baseTotals', null)
            ->where('unconverted', [])
            ->has('totals', 1)
        );
    }

    public function test_the_base_total_is_absent_until_a_second_currency_appears(): void
    {
        $this->cash('2026-01-05', 'deposit', '1000.0000', 'HKD');

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('baseTotals', null)
        );

        $this->foreignCharge('2026-01-06', 'AUD', '79.8800', '624.0000');

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('baseTotals.count', 2)
        );
    }

    public function test_a_trade_is_kept_out_of_the_base_net(): void
    {
        // As on every strip: a brokerage row moves no balance, so it is totalled apart
        // rather than netted. Netting it would make a purchase look like income.
        $this->cash('2026-01-05', 'deposit', '1000.0000', 'HKD');
        $this->trade('2026-01-06', '500.0000', 'USD');

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('baseTotals', [
                'count' => 1,
                'in' => '1000.0000',
                'out' => '0.0000',
                'net' => '1000.0000',
                'trades' => '0.0000',
            ])
            // Named, because nothing on the page would otherwise say a row went missing.
            ->where('unconverted', ['USD'])
        );
    }

    public function test_a_foreign_row_with_no_base_figure_is_left_out_and_named(): void
    {
        // A deposit into a US bank. There is no card to state an HKD figure and no rate
        // is asked for, so the row says nothing in HKD -- and the total must not pretend
        // otherwise, which one-for-one would do.
        $usBank = Account::create(['name' => 'US Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'USD']);
        $this->cashOn($usBank, '2026-01-05', 'deposit', '14450.5300', 'USD');
        $this->cash('2026-01-06', 'deposit', '1000.0000', 'HKD');

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('baseTotals', [
                'count' => 1,
                'in' => '1000.0000',
                'out' => '0.0000',
                'net' => '1000.0000',
                'trades' => '0.0000',
            ])
            ->where('unconverted', ['USD'])
        );
    }

    public function test_a_card_own_currency_charge_is_not_folded_into_the_base_total(): void
    {
        // A US card, charged in dollars. Its card_amount is dollars, so reading that as a
        // base figure would put 50 into a row labelled HKD and make the total wrong by a
        // factor of eight -- with nothing on the page to show which figure was used.
        $usCard = Account::create(['name' => 'US Card', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);
        $usCard->meta()->create(['meta' => ['term_days' => 15, 'statement_day' => 25]]);
        $this->foreignChargeOn($usCard, '2026-01-05', 'USD', '50.0000', '50.0000');
        $this->cash('2026-01-06', 'deposit', '1000.0000', 'HKD');

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('baseTotals', [
                'count' => 1,
                'in' => '1000.0000',
                'out' => '0.0000',
                'net' => '1000.0000',
                'trades' => '0.0000',
            ])
            ->where('unconverted', ['USD'])
            // The strip still reports it, in dollars, where it belongs. Sorted by code,
            // so HKD leads and the dollar strip is the second.
            ->where('totals.1.ccy', 'USD')
            ->where('totals.1.out', '50.0000')
        );
    }

    public function test_a_pending_row_is_left_out_of_the_totals(): void
    {
        // A pending row moves no balance, so it is not a figure the reader has yet. The
        // list above still shows it, which is why the card has to say it is left out:
        // a count of one beside a table of two rows reads as a mistake otherwise.
        $this->foreignCharge('2026-01-05', 'AUD', '79.8800', '624.0000', 'posted');
        $this->foreignCharge('2026-01-07', 'AUD', '10.0000', '78.0000', 'pending');
        $this->cash('2026-01-06', 'deposit', '1000.0000', 'HKD');

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('baseTotals', [
                'count' => 2,
                'in' => '1000.0000',
                'out' => '624.0000',
                'net' => '376.0000',
                'trades' => '0.0000',
            ])
            ->where('totals.0.count', 1)
            ->where('totals.0.out', '79.8800')
        );
    }

    public function test_a_list_of_pending_rows_alone_has_no_base_total(): void
    {
        // Nothing left to total, and no currencies but one, so there is no card either way.
        $this->foreignCharge('2026-01-05', 'AUD', '79.8800', '624.0000', 'pending');

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('baseTotals', null)
            ->where('totals', [])
        );
    }

    public function test_the_base_is_sent_as_a_prop_rather_than_written_into_the_page(): void
    {
        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('base', 'HKD')
        );
    }

    /** A charge on the HKD card, in a currency it is not, stating the card's own figure. */
    private function foreignCharge(string $date, string $ccy, string $amount, string $cardAmount, string $status = 'posted'): Transaction
    {
        return $this->foreignChargeOn($this->card, $date, $ccy, $amount, $cardAmount, $status);
    }

    private function foreignChargeOn(Account $card, string $date, string $ccy, string $amount, string $cardAmount, string $status = 'posted'): Transaction
    {
        $charge = Transaction::create([
            'account_id' => $card->id,
            'category_id' => $this->category,
            'date' => $date,
            'type' => 'charge',
            'description' => 'Cafe',
            'amount' => $amount,
            'ccy' => $ccy,
            'status' => $status,
        ]);

        $charge->meta()->create(['meta' => ['due_date' => '2026-02-09', 'card_amount' => $cardAmount]]);

        return $charge;
    }

    private function cash(string $date, string $type, string $amount, string $ccy): Transaction
    {
        return $this->cashOn($this->bank, $date, $type, $amount, $ccy);
    }

    private function cashOn(Account $account, string $date, string $type, string $amount, string $ccy): Transaction
    {
        return Transaction::create([
            'account_id' => $account->id,
            'category_id' => null,
            'date' => $date,
            'type' => $type,
            'description' => 'Money',
            'amount' => $amount,
            'ccy' => $ccy,
            'status' => 'posted',
        ]);
    }

    private function trade(string $date, string $amount, string $ccy): Transaction
    {
        $brokerage = Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => $ccy]);

        return Transaction::create([
            'account_id' => $brokerage->id,
            'category_id' => null,
            'date' => $date,
            'type' => 'buy',
            'description' => 'Shares',
            'amount' => $amount,
            'ccy' => $ccy,
            'status' => 'posted',
        ]);
    }
}
