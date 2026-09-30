<?php

namespace Tests\Feature;

use App\DTO\RecurringTransactionData;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Finding recurring payments in the history, and putting the rules in line with them.
 *
 * The arithmetic is in RecurringPatternsTest; this is the half that touches the database,
 * writes anything, or answers over HTTP.
 */
class RecurringDetectTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    private Account $card;

    private Account $broker;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-30 12:00', 'Asia/Hong_Kong'));

        $this->bank = Account::create(['name' => 'Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $this->card = Account::create(['name' => 'Card', 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);
        $this->card->meta()->create(['meta' => ['term_days' => 15, 'statement_day' => 25]]);
        $this->broker = Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $this->broker->meta()->create(['meta' => ['settlement_account_id' => $this->bank->id]]);
        $this->category = Category::create(['name' => 'Subscriptions']);
    }

    public function test_the_button_reports_what_the_history_says_and_writes_nothing(): void
    {
        $this->monthly('KKBOX', '53.0000', $this->card);

        $this->post('/recurring/find')->assertSessionHas('findings');

        $this->assertSame(0, RecurringTransaction::count(), 'A report is not a decision.');

        $this->get('/recurring')->assertInertia(fn (Assert $page) => $page
            ->where('findings.0.verdict', 'new')
            ->where('findings.0.description', 'KKBOX')
            ->where('findings.0.day', 12)
            ->where('findings.0.occurrences', 3)
        );
    }

    public function test_a_page_visit_with_no_scan_has_no_findings(): void
    {
        $this->monthly('KKBOX', '53.0000', $this->card);

        $this->get('/recurring')->assertInertia(fn (Assert $page) => $page->where('findings', null));
    }

    public function test_applying_adds_a_rule_that_starts_on_its_next_payment(): void
    {
        $this->monthly('KKBOX', '53.0000', $this->card);

        $this->post('/recurring/find/apply')
            ->assertRedirect('/recurring')
            ->assertSessionHas('message', '1 added');

        $rule = RecurringTransaction::sole();

        // The recorder writes a pending row for every occurrence since start_date, so a rule
        // started on the first payment in the history would write one for every month since.
        $this->assertSame('2026-10-12', $rule->start_date);
        $this->assertSame('53.0000', $rule->amount);
        $this->assertSame($this->card->id, $rule->account_id);
        $this->assertNull($rule->last_recorded_on);
        $this->assertSame(0, Transaction::where('status', 'pending')->count());
    }

    public function test_a_rule_the_history_disagrees_with_is_brought_up_to_date(): void
    {
        $this->monthly('NETFLIX', '98.9800', $this->card, ['2026-07-10', '2026-08-10', '2026-09-10']);
        $rule = $this->rule(['description' => 'NETFLIX', 'amount' => '88.8800', 'start_date' => '2026-01-01']);

        $this->post('/recurring/find/apply')->assertSessionHas('message', '1 brought up to date');

        $this->assertSame('98.9800', $rule->refresh()->amount);
        $this->assertSame('2026-10-10', $rule->start_date);
    }

    public function test_a_rule_whose_payments_stopped_is_removed(): void
    {
        $this->rule(['description' => 'INSTALMENT', 'amount' => '2800.0000', 'start_date' => '2024-09-01']);

        $this->post('/recurring/find/apply')->assertSessionHas('message', '1 removed');

        $this->assertSame(0, RecurringTransaction::count());
    }

    public function test_the_page_carries_each_rules_group_its_cost_and_what_the_history_says(): void
    {
        $this->monthly('NETFLIX', '98.9800', $this->card, ['2026-07-10', '2026-08-10', '2026-09-10']);
        $netflix = $this->rule(['description' => 'NETFLIX', 'amount' => '88.8800', 'start_date' => '2026-01-10']);
        $salary = $this->rule([
            'account_id' => $this->bank->id, 'type' => 'deposit', 'description' => 'SALARY',
            'amount' => '1000.0000', 'start_date' => '2026-01-01',
        ]);
        $domain = $this->rule([
            'account_id' => $this->bank->id, 'type' => 'withdraw', 'description' => 'DOMAIN',
            'amount' => '120.0000', 'frequency' => 'yearly', 'start_date' => '2026-02-01',
        ]);

        $this->get('/recurring')->assertInertia(fn (Assert $page) => $page
            ->where("costs.rules.{$netflix->id}.group", 'cards')
            ->where("costs.rules.{$salary->id}.group", 'income')
            // A yearly rule is a twelfth of itself a month.
            ->where("costs.rules.{$domain->id}.monthly", '10.0000')
            ->where('costs.totals.income', '1000.0000')
            ->where('costs.totals.bills', '10.0000')
            ->where('costs.totals.cards', '88.8800')
            ->where('costs.totals.net', '901.1200')
            ->where('costs.yearly.cards', '1066.5600')
            // The yearly rules apart, still counted once in bills and net.
            ->where('costs.totals.annual', '10.0000')
            ->where('costs.yearly.annual', '120.0000')
            ->where("health.{$netflix->id}.verdict", 'differs')
            ->where("health.{$netflix->id}.amount", '98.9800')
            ->where("health.{$netflix->id}.last", '2026-09-10')
        );
    }

    public function test_one_rule_is_put_in_line_with_its_history_and_no_other(): void
    {
        $this->monthly('NETFLIX', '98.9800', $this->card, ['2026-07-10', '2026-08-10', '2026-09-10']);
        $this->monthly('KKBOX', '53.0000', $this->card);
        $netflix = $this->rule(['description' => 'NETFLIX', 'amount' => '88.8800', 'start_date' => '2026-01-10']);
        $kkbox = $this->rule(['description' => 'KKBOX', 'amount' => '50.0000', 'start_date' => '2026-01-12']);

        $this->post("/recurring/{$netflix->id}/adopt")->assertSessionHas('message', '1 brought up to date');

        $this->assertSame('98.9800', $netflix->refresh()->amount);
        $this->assertSame('50.0000', $kkbox->refresh()->amount, 'Only the rule asked about is touched.');

        $this->post("/recurring/{$netflix->id}/adopt")
            ->assertSessionHas('message', 'Recurring [NETFLIX] already matches its history');
    }

    public function test_a_rule_whose_payments_stopped_is_deleted_on_its_own(): void
    {
        $rule = $this->rule(['description' => 'INSTALMENT', 'amount' => '2800.0000', 'start_date' => '2024-09-01']);

        $this->get('/recurring')->assertInertia(fn (Assert $page) => $page->where("health.{$rule->id}.verdict", 'stopped'));

        $this->post("/recurring/{$rule->id}/adopt")->assertSessionHas('message', '1 removed');

        $this->assertSame(0, RecurringTransaction::count());
    }

    public function test_a_rule_the_history_agrees_with_is_left_alone(): void
    {
        $this->monthly('KKBOX', '53.0000', $this->card);
        $this->rule(['description' => 'KKBOX', 'amount' => '53.0000', 'start_date' => '2026-07-12']);

        $this->post('/recurring/find/apply')->assertSessionHas('message', 'Nothing to change');

        $this->assertSame(1, RecurringTransaction::count());
        $this->assertSame('2026-07-12', RecurringTransaction::sole()->start_date);
    }

    public function test_a_day_the_history_is_torn_between_is_settled_on_the_first(): void
    {
        // The 27th and the 28th equally often. The report says both, and the rule is written
        // for the earlier: a schedule that is on the 27th or the 28th is a schedule, and
        // refusing to write it down leaves a subscription with no rule at all.
        $this->charge('PCCW', '204.0000', ['2026-06-27', '2026-07-28', '2026-08-27', '2026-09-28']);
        $rule = $this->rule(['description' => 'PCCW', 'amount' => '199.0000', 'start_date' => '2026-01-27']);

        $this->post('/recurring/find')->assertSessionHas('findings', function (array $findings) {
            $this->assertSame(27, $findings[0]['day']);
            $this->assertSame([27, 28], $findings[0]['tied_days']);
            $this->assertSame('2026-10-27', $findings[0]['start_date']);

            return true;
        });

        $this->post('/recurring/find/apply')->assertSessionHas('message', '1 brought up to date');

        $this->assertSame('204.0000', $rule->refresh()->amount);
        $this->assertSame('2026-10-27', $rule->start_date);
    }

    public function test_the_other_side_of_a_card_payment_is_never_offered(): void
    {
        // Three months of paying the same card: three months of the bank side of a movement
        // between two of the user's own accounts, which is not a subscription and a rule for
        // it would pay a card from a figure the statement derived.
        foreach (['2026-07-08', '2026-08-08', '2026-09-08'] as $i => $date) {
            $charge = $this->post('/transactions', [
                'account_id' => $this->card->id,
                'category_id' => $this->category->id,
                'date' => '2026-06-08',
                'type' => TransactionType::Charge->value,
                'description' => 'Something',
                'amount' => '500.0000',
                'ccy' => 'HKD',
            ])->assertSessionHasNoErrors();

            $side = Transaction::create([
                'account_id' => $this->bank->id,
                'date' => $date,
                'type' => TransactionType::Withdraw->value,
                'description' => 'Card payment [Card]',
                'amount' => '500.0000',
                'ccy' => 'HKD',
                'status' => 'posted',
            ]);

            $side->meta()->create(['meta' => ['paired_transaction_id' => $i + 1]]);
            unset($charge);
        }

        $this->post('/recurring/find')->assertSessionHas('findings', function (array $findings) {
            $this->assertSame([], $findings, 'The bank side of a transfer is not a subscription.');

            return true;
        });
    }

    public function test_a_trade_is_never_offered(): void
    {
        // A buy carries a symbol, and canRecur() is false for every type that does.
        foreach (['2026-07-12', '2026-08-12', '2026-09-12'] as $date) {
            $this->post('/transactions', [
                'account_id' => $this->broker->id,
                'date' => $date,
                'type' => 'buy',
                'description' => 'Buy NVDA',
                'ccy' => 'HKD',
                'meta_data' => ['symbol' => 'NVDA', 'quantity' => '10', 'unit_price' => '100'],
            ])->assertSessionHasNoErrors();
        }

        $this->post('/recurring/find')->assertSessionHas('findings', function (array $findings) {
            $this->assertSame([], $findings);

            return true;
        });
    }

    public function test_a_rule_it_adds_is_one_the_form_would_have_accepted(): void
    {
        $this->monthly('KKBOX', '53.0000', $this->card);

        $this->post('/recurring/find/apply');

        $rule = RecurringTransaction::sole();

        // Written without the form, so the rules the form would have applied are checked here
        // instead: a rule the page could not edit is a rule the page cannot fix.
        $errors = Validator::make(
            $rule->only(['account_id', 'category_id', 'type', 'description', 'amount', 'ccy', 'frequency', 'start_date', 'end_date', 'active']),
            RecurringTransactionData::rules()
        )->errors();

        $this->assertSame([], $errors->all(), 'A rule written from history has to be one the form accepts.');
        $this->assertSame($this->category->id, $rule->category_id);
    }

    public function test_the_scan_asks_for_the_same_patterns_the_command_does(): void
    {
        $this->monthly('KKBOX', '53.0000', $this->card);

        $this->post('/recurring/find')->assertSessionHas('findings', fn (array $findings) => $findings[0]['description'] === 'KKBOX' && $findings[0]['amount'] === '53.0000');

        $this->artisan('recurring:detect')
            ->expectsOutputToContain('KKBOX')
            ->assertSuccessful();
    }

    public function test_the_command_writes_nothing_without_apply(): void
    {
        $this->monthly('KKBOX', '53.0000', $this->card);

        $this->artisan('recurring:detect')->assertSuccessful();

        $this->assertSame(0, RecurringTransaction::count());
    }

    public function test_the_command_applies_what_it_reports(): void
    {
        $this->monthly('KKBOX', '53.0000', $this->card);

        $this->artisan('recurring:detect --apply')
            ->expectsOutputToContain('1 added')
            ->assertSuccessful();

        $this->assertSame('2026-10-12', RecurringTransaction::sole()->start_date);
    }

    /**
     * @param  list<string>  $dates
     */
    public function test_a_foreign_subscription_on_a_card_is_written_with_what_the_card_owes(): void
    {
        // FLICKR PRO in USD on the HKD card: a rule without the HKD figure is refused the
        // first time it tries to record, so the rule takes the newest payment's.
        foreach ([['2025-04-18', '79.99', '620.00'], ['2026-04-18', '96.00', '766.59']] as [$date, $usd, $hkd]) {
            $this->post('/transactions', [
                'account_id' => $this->card->id,
                'category_id' => $this->category->id,
                'date' => $date,
                'type' => TransactionType::Charge->value,
                'description' => "FLICKR PRO 1 YEAR {$usd} USD",
                'amount' => $usd,
                'ccy' => 'USD',
                'meta_data' => ['card_amount' => $hkd],
            ])->assertSessionHasNoErrors();
        }

        $this->post('/recurring/find/apply')->assertSessionHasNoErrors();

        $rule = RecurringTransaction::sole();

        $this->assertSame('FLICKR PRO 1 YEAR', $rule->description);
        $this->assertSame('yearly', $rule->frequency);
        $this->assertSame('USD', $rule->ccy);
        $this->assertSame('766.5900', $rule->card_amount);
        $this->assertSame([], Validator::make(
            RecurringTransactionData::fromModel($rule)->toArray(),
            RecurringTransactionData::rules()
        )->errors()->all());
    }

    private function monthly(string $description, string $amount, Account $account, array $dates = ['2026-07-12', '2026-08-12', '2026-09-12']): void
    {
        foreach ($dates as $date) {
            $this->charge($description, $amount, [$date], $account);
        }
    }

    /**
     * @param  list<string>  $dates
     */
    private function charge(string $description, string $amount, array $dates, ?Account $account = null): void
    {
        $account ??= $this->card;

        foreach ($dates as $date) {
            $this->post('/transactions', [
                'account_id' => $account->id,
                'category_id' => $this->category->id,
                'date' => $date,
                'type' => TransactionType::Charge->value,
                'description' => $description,
                'amount' => $amount,
                'ccy' => 'HKD',
            ])->assertSessionHasNoErrors();
        }
    }

    /**
     * @param  array<string, mixed>  $over
     */
    private function rule(array $over = []): RecurringTransaction
    {
        return RecurringTransaction::create(array_merge([
            'account_id' => $this->card->id,
            'category_id' => null,
            'type' => TransactionType::Charge->value,
            'description' => 'RULE',
            'amount' => '10.0000',
            'ccy' => 'HKD',
            'frequency' => 'monthly',
            'start_date' => '2026-01-01',
            'active' => true,
        ], $over));
    }
}
