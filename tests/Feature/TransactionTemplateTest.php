<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringTransaction;
use App\Models\TransactionTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A saved set of the transaction form's values: what it stores, what it refuses, and
 * what it does when the account or the category it names goes away.
 *
 * The point of most of these is the same point. A payload is whatever the browser sent,
 * so the keys a template keeps are the only thing standing between the form and a stored
 * copy of a server-owned value -- `id` naming a row nobody is editing, a due date the
 * card's own terms decide. Asserting the stored shape rather than the rule that produces
 * it means a field added to TransactionData later is caught here rather than trusted.
 */
class TransactionTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bank = Account::create(['name' => 'Bank USD', 'status' => 'active', 'type' => 'cash', 'ccy' => 'USD']);
        $this->category = Category::create(['name' => 'Groceries']);
    }

    public function test_it_saves_the_forms_values_and_names_itself_after_them(): void
    {
        $this->post('/transaction-templates', $this->body('Rent'))->assertSessionHasNoErrors();

        $template = TransactionTemplate::firstOrFail();

        $this->assertSame('Rent', $template->name);
        $this->assertSame($this->bank->id, $template->account_id);
        $this->assertSame($this->category->id, $template->category_id);
        $this->assertSame('withdraw', $template->payload['type']);
        $this->assertSame('1200.0000', $template->payload['amount']);
        $this->assertSame('Monthly rent', $template->payload['description']);
        $this->assertSame('USD', $template->payload['ccy']);
    }

    public function test_it_keeps_only_the_keys_a_template_may_store(): void
    {
        $this->post('/transaction-templates', $this->body('Rent'))->assertSessionHasNoErrors();

        $payload = TransactionTemplate::firstOrFail()->payload;

        // The server's own, and the two the payload carries nowhere: a template naming a
        // row or a day is filling a field the form would then submit as fact.
        $this->assertArrayNotHasKey('id', $payload);
        $this->assertArrayNotHasKey('date', $payload);
        $this->assertArrayNotHasKey('created_at', $payload);
        $this->assertArrayNotHasKey('account_id', $payload);
        $this->assertArrayNotHasKey('category_id', $payload);
        $this->assertArrayNotHasKey('account_name', $payload);

        // And in the bag, which is where the rest of the exclusions live.
        $bag = $payload['meta_data'];

        foreach (['due_date', 'paired_transaction_id', 'settled_by'] as $serverOwned) {
            $this->assertArrayNotHasKey(
                $serverOwned,
                $bag,
                "A template stored meta_data.{$serverOwned}, which the server derives or owns."
            );
        }

        // The trade figures, which are the reason a template is worth having at all.
        $this->post('/transaction-templates', $this->body('NVDA', [
            'category_id' => null,
            'payload' => [
                'type' => 'buy',
                'description' => 'Buy NVDA',
                'amount' => null,
                'ccy' => 'USD',
                'status' => 'posted',
                'meta_data' => [
                    'symbol' => 'NVDA',
                    'quantity' => '10',
                    'unit_price' => '100',
                    'fees' => '5',
                    'due_date' => null,
                    'paired_transaction_id' => null,
                    'settled_by' => null,
                    'no_cash' => null,
                ],
            ],
        ]))->assertSessionHasNoErrors();

        $trade = TransactionTemplate::where('name', 'NVDA')->firstOrFail();

        $this->assertSame('10', $trade->payload['meta_data']['quantity']);
        $this->assertSame('100', $trade->payload['meta_data']['unit_price']);
        // Null kept rather than dropped: an absent unit_price reads as '0' in
        // derivedAmount(), so a buy's cost would come off a field nobody was shown.
        $this->assertArrayHasKey('amount', $trade->payload);
        $this->assertNull($trade->payload['amount']);
    }

    public function test_a_name_already_in_use_is_given_a_number_rather_than_refused(): void
    {
        $this->post('/transaction-templates', $this->body('Rent'))->assertSessionHasNoErrors();
        $this->post('/transaction-templates', $this->body('Rent'))->assertSessionHasNoErrors();
        $this->post('/transaction-templates', $this->body('Rent'))->assertSessionHasNoErrors();

        // Two templates of the same thing is a normal thing to want, so the save succeeds
        // and the name says which is which -- rather than the second one being an error
        // the user has to invent a different name to get past.
        $this->assertSame(
            ['Rent', 'Rent 2', 'Rent 3'],
            TransactionTemplate::orderBy('name')->pluck('name')->all()
        );
    }

    public function test_a_name_already_in_use_differing_only_in_case_is_the_same_name(): void
    {
        // The column's collation decides this, not the string comparison PHP would do,
        // so the uniquifier has to ask the database. Two entries reading "Rent" and
        // "rent" in a menu of five names is the drift this is about.
        $this->post('/transaction-templates', $this->body('Rent'))->assertSessionHasNoErrors();
        $this->post('/transaction-templates', $this->body('rent'))->assertSessionHasNoErrors();

        $this->assertSame(
            ['Rent', 'rent 2'],
            TransactionTemplate::orderBy('name')->pluck('name')->all()
        );
    }

    public function test_a_name_at_the_column_limit_still_fits_once_numbered(): void
    {
        $long = str_repeat('a', 255);

        $this->post('/transaction-templates', $this->body($long))->assertSessionHasNoErrors();
        $this->post('/transaction-templates', $this->body($long))->assertSessionHasNoErrors();

        $names = TransactionTemplate::orderBy('id')->pluck('name')->all();

        $this->assertCount(2, $names);
        $this->assertSame(255, mb_strlen($names[0]));
        $this->assertSame(255, mb_strlen($names[1]));
        $this->assertStringEndsWith(' 2', $names[1]);
    }

    public function test_updating_replaces_the_values_and_keeps_the_name_it_had(): void
    {
        $this->post('/transaction-templates', $this->body('Rent'))->assertSessionHasNoErrors();

        $template = TransactionTemplate::firstOrFail();

        $this->put("/transaction-templates/{$template->id}", $this->body('Rent', [
            'payload' => $this->payload(['description' => 'Rent, paid late', 'amount' => '1400.0000']),
        ]))->assertSessionHasNoErrors();

        $template->refresh();

        // The name is taken by this very template, so numbering it would rename "Rent" to
        // "Rent 2" on the first update and "Rent 3" on the next.
        $this->assertSame('Rent', $template->name);
        $this->assertSame('Rent, paid late', $template->payload['description']);
        $this->assertSame('1400.0000', $template->payload['amount']);
    }

    public function test_an_update_replaces_the_payload_rather_than_merging_into_it(): void
    {
        $this->post('/transaction-templates', $this->body('Rent'))->assertSessionHasNoErrors();

        $template = TransactionTemplate::firstOrFail();

        // Cleared in the form, so cleared here. Merging would keep the old amount on a
        // key the user left null and the template would go on filling it in.
        $this->put("/transaction-templates/{$template->id}", $this->body('Rent', [
            'category_id' => null,
            'payload' => $this->payload(['amount' => null]),
        ]))->assertSessionHasNoErrors();

        $template->refresh();

        $this->assertNull($template->payload['amount']);
        $this->assertNull($template->category_id);
    }

    public function test_it_needs_a_name_an_account_and_a_type(): void
    {
        // Each of these is a request that could be refused, and each refusal lands in an
        // error bag belonging to a dialog that has already closed -- so what is tested
        // here is that the server refuses them at all. The account's message is the one
        // asserted in full, since attributes() is what turns "account_id" into something
        // a person would recognise.
        $this->post('/transaction-templates', ['account_id' => $this->bank->id, 'payload' => ['type' => 'withdraw']])
            ->assertSessionHasErrors(['name' => 'The name field is required.']);

        $this->post('/transaction-templates', ['name' => 'Rent', 'payload' => ['type' => 'withdraw']])
            ->assertSessionHasErrors(['account_id' => 'The account field is required.']);

        $this->post('/transaction-templates', ['name' => 'Rent', 'account_id' => $this->bank->id, 'payload' => []])
            ->assertSessionHasErrors(['payload.type' => 'The type field is required.']);

        // A type the enum does not know: the payload arrives as an array, so unlike
        // TransactionData nothing derives the membership for it.
        $this->post('/transaction-templates', [
            'name' => 'Rent',
            'account_id' => $this->bank->id,
            'payload' => ['type' => 'banana'],
        ])->assertSessionHasErrors('payload.type');

        $this->assertSame(0, TransactionTemplate::count());
    }

    public function test_deleting_the_account_takes_its_templates_with_it(): void
    {
        $this->post('/transaction-templates', $this->body('Rent'))->assertSessionHasNoErrors();

        $this->assertSame(1, TransactionTemplate::count());

        // A template naming an account that no longer exists would fill a field the form
        // cannot show and refuse the save with an error about a field the user cannot
        // clear. The cascade means that case cannot be reached at all.
        $this->delete("/accounts/{$this->bank->id}");

        $this->assertSame(0, TransactionTemplate::count());
    }

    public function test_deleting_the_category_takes_its_templates_with_it(): void
    {
        $this->post('/transaction-templates', $this->body('Rent'))->assertSessionHasNoErrors();

        $this->assertSame(1, TransactionTemplate::count());

        $this->delete("/categories/{$this->category->id}");

        $this->assertSame(0, TransactionTemplate::count());
    }

    public function test_deleting_a_template_leaves_its_account_and_category_alone(): void
    {
        $this->post('/transaction-templates', $this->body('Rent'))->assertSessionHasNoErrors();

        $this->delete('/transaction-templates/'.TransactionTemplate::firstOrFail()->id)
            ->assertSessionHas('message', 'Template [Rent] deleted');

        $this->assertSame(0, TransactionTemplate::count());
        $this->assertNotNull($this->bank->fresh());
        $this->assertNotNull($this->category->fresh());
    }

    public function test_the_transactions_page_sends_them_for_the_form_to_offer(): void
    {
        $this->post('/transaction-templates', $this->body('Rent'))->assertSessionHasNoErrors();

        // Read on the page rather than fetched when the dialog opens: a menu that has to
        // be fetched is a menu that is not there when the form is.
        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->component('transaction')
            ->has('templates', 1)
            ->where('templates.0.name', 'Rent')
            ->where('templates.0.account_name', 'Bank USD')
            ->where('templates.0.payload.type', 'withdraw')
        );
    }

    // ---------------------------------------------------------------------

    /** A template body as the form sends it: the whole form, keys and all. */
    public function test_a_recurring_rule_is_read_as_a_template_and_never_written_to(): void
    {
        $rule = RecurringTransaction::create([
            'account_id' => $this->bank->id,
            'category_id' => $this->category->id,
            'type' => 'withdraw',
            'description' => 'Monthly rent',
            'amount' => '3200.0000',
            'ccy' => 'USD',
            'frequency' => 'monthly',
            'start_date' => '2026-10-01',
        ]);

        $this->post('/transaction-templates', $this->body('Rent'))->assertSessionHasNoErrors();

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('templates', function (Collection $templates) use ($rule) {
                $derived = collect($templates)->firstWhere('derived', true);
                $saved = collect($templates)->firstWhere('derived', false);

                // Read as a template: enough for the form to fill itself from.
                $this->assertSame('rule-'.$rule->id, $derived['key']);
                $this->assertSame('Monthly rent', $derived['name']);
                $this->assertSame($this->bank->id, $derived['account_id']);
                $this->assertSame($this->category->id, $derived['category_id']);
                $this->assertSame([
                    'type' => 'withdraw',
                    'description' => 'Monthly rent',
                    'amount' => '3200.0000',
                    'ccy' => 'USD',
                    'status' => 'posted',
                ], $derived['payload']);

                // The rule's day, so the form can date the payment to it.
                $this->assertSame(['frequency' => 'monthly', 'day' => 1, 'month' => 10], $derived['schedule']);

                // And nothing to write to. An id here is what the form's update and the
                // menu's delete buttons are both keyed on, so an id is a row that can be
                // overwritten or removed by a button meant for a template.
                $this->assertNull($derived['id']);

                $this->assertSame('Rent', $saved['name']);
                $this->assertNotNull($saved['id']);

                return true;
            })
        );
    }

    public function test_a_paused_rule_is_not_offered_as_a_template(): void
    {
        RecurringTransaction::create([
            'account_id' => $this->bank->id, 'category_id' => null, 'type' => 'withdraw',
            'description' => 'Paused', 'amount' => '10.0000', 'ccy' => 'USD',
            'frequency' => 'monthly', 'start_date' => '2026-10-01', 'active' => false,
        ]);

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('templates', fn (Collection $templates) => $templates->isEmpty())
        );
    }

    private function body(string $name, array $overrides = []): array
    {
        return array_merge([
            'name' => $name,
            'account_id' => $this->bank->id,
            'category_id' => $this->category->id,
            'payload' => $this->payload(),
        ], $overrides);
    }

    /**
     * The payload half of a body, which is the form as it stands -- every field, and the
     * bag with every key in it. The fields a template must not store are sent as readily
     * as the ones it must, because what is being tested is that the server drops them.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'id' => null,
            'account_id' => $this->bank->id,
            'category_id' => $this->category->id,
            'date' => '2026-03-01',
            'type' => 'withdraw',
            'description' => 'Monthly rent',
            'amount' => '1200.0000',
            'ccy' => 'USD',
            'status' => 'posted',
            'created_at' => null,
            'meta_data' => [
                'symbol' => null,
                'quantity' => null,
                'unit_price' => null,
                'fees' => null,
                'due_date' => null,
                'card_amount' => null,
                'no_cash' => null,
                'paired_transaction_id' => null,
                'settled_by' => null,
            ],
        ], $overrides);
    }
}
