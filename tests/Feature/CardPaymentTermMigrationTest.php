<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Support\CardStatementCycle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers the one-time rewrite of stored `due` values from a day of the month to
 * a number of days, under the name `term_days`.
 *
 * The rewrite exists because the meaning of the column changed underneath the
 * data, which is the one kind of change no amount of code review catches: the
 * stored 26 on a card closing on the 8th was the 26th of the month, and read
 * unchanged under the new model it becomes the 3rd of the following month.
 *
 * Fixtures in these tests are written with the legacy `due` key on purpose. That
 * is the state on disk before the migration runs, so a fixture using the new key
 * would silently test nothing -- the migration would skip it and the test would
 * pass for the wrong reason.
 *
 * The conversion is exact only when the due day falls after the statement day.
 * See the cases on termFor() below -- the lossy direction is a property of month
 * lengths, not a defect in the arithmetic, and no single term can preserve a
 * date that the old model resolved differently each month.
 */
class CardPaymentTermMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function card(string $name, array $legacyMeta): Account
    {
        $card = Account::create([
            'name' => $name,
            'status' => 'active',
            'type' => 'card',
            'ccy' => 'HKD',
        ]);

        $card->meta()->create(['meta' => $legacyMeta]);

        return $card;
    }

    private function meta(Account $card): array
    {
        return $card->meta()->firstOrFail()->meta->getArrayCopy();
    }

    /**
     * The migration, loaded fresh.
     *
     * By path rather than by name because it is an anonymous class, which is what
     * every migration in this project is and what this Laravel's Migrator expects:
     * getMigrationClass() derives a bare class name, so a namespaced named class
     * can never be resolved and a global one collides with nothing useful. An
     * anonymous class has no name to collide, and require is safe to repeat.
     */
    private function migration(): object
    {
        return require database_path(
            'migrations/2026_09_26_120000_convert_card_due_day_to_a_payment_term.php'
        );
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    public static function termForProvider(): array
    {
        return [
            // Due day after the statement day: the old due date was in the closing
            // month, so the interval is within a month and is the same number of
            // days whatever month it lands in.
            'due after statement, exact' => [26, 8, 18],
            'due the day after statement' => [9, 8, 1],

            // Due day on or before the statement day: the old due date fell in
            // the *next* month, so the interval is daysInMonth - S + D, and
            // daysInMonth is 28, 29, 30 or 31. 31 is the only value that can be
            // stored, and it leaves short months one or two days out.
            'due before statement, closest possible' => [15, 25, 21],
            'due on the statement day itself, so a whole month' => [8, 8, 31],
            'due the same day it closes' => [25, 25, 31],
            'closes on the last, due on the first' => [1, 31, 1],
        ];
    }

    #[DataProvider('termForProvider')]
    public function test_term_for_converts_a_due_day_into_a_number_of_days(
        int $dueDay,
        int $statementDay,
        int $expectedTerm
    ): void {
        $this->assertSame($expectedTerm, $this->migration()->termFor($dueDay, $statementDay));
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int, 3: string}>
     */
    public static function exactConversionProvider(): array
    {
        // A card closing on the 8th, due on the 26th. Every charge below lands on
        // a due date the old day-of-month rule produced, which is the whole
        // point: after the rewrite the data means what it meant yesterday.
        return [
            'charge inside a 31-day closing month' => [2026, 9, 10, '2026-10-26'],
            'charge inside a 30-day closing month' => [2026, 4, 20, '2026-05-26'],
            'charge inside a 28-day closing month' => [2026, 2, 15, '2026-03-26'],
            'charge inside a leap February' => [2024, 2, 20, '2024-03-26'],
        ];
    }

    #[DataProvider('exactConversionProvider')]
    public function test_a_due_day_after_the_statement_day_moves_no_due_date_at_all(
        int $year,
        int $month,
        int $day,
        string $expectedDueDate
    ): void {
        $card = $this->card("Exact {$year}-{$month}", ['due' => 26, 'statement_day' => 8]);

        $this->assertSame(26, $this->meta($card)['due'], 'Precondition: the legacy value is in place.');

        $this->migration()->up();

        $this->assertSame(18, $this->meta($card)['term_days']);
        $this->assertArrayNotHasKey('due', $this->meta($card));

        $cycle = CardStatementCycle::fromMeta($this->meta($card));

        $this->assertSame(
            $expectedDueDate,
            $cycle->dueDateFor(Carbon::parse(sprintf('%d-%02d-%02d', $year, $month, $day)))->toDateString()
        );
    }

    public function test_a_due_day_before_the_statement_day_is_rewritten_to_the_closest_term(): void
    {
        // Due on the 15th, closing on the 25th. No stored term reproduces the old
        // date in every month, because the interval from the 25th of one month to
        // the 15th of the next is daysInMonth - 10. 21 is exact for a 31-day
        // closing month and a day late otherwise -- the closest a single stored
        // value can get.
        $card = $this->card('Lossy', ['due' => 15, 'statement_day' => 25]);

        $this->migration()->up();

        $this->assertSame(21, $this->meta($card)['term_days']);

        $cycle = CardStatementCycle::fromMeta($this->meta($card));

        $this->assertSame(
            '2026-11-15',
            $cycle->dueDateFor(Carbon::parse('2026-09-26'))->toDateString(),
            'October is a 31-day month, so this is the one case that converts exactly.'
        );
        $this->assertSame(
            '2026-05-16',
            $cycle->dueDateFor(Carbon::parse('2026-03-26'))->toDateString(),
            'April has 30 days, so no term both fits in the column and lands on the 15th. One day '
                .'out is the closest a single stored value gets, and it errs late rather than early.'
        );
    }

    public function test_a_non_card_account_is_left_alone(): void
    {
        $account = Account::create(['name' => 'Cash', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $account->meta()->create(['meta' => ['note' => 'not a card']]);

        $this->migration()->up();

        $this->assertSame(['note' => 'not a card'], $account->meta()->firstOrFail()->meta->getArrayCopy());
    }

    public function test_a_meta_row_missing_either_number_is_left_alone(): void
    {
        // Nothing to convert a single number against, and inventing the other
        // half would fabricate card terms for a row that may predate them. The
        // legacy key is deliberately left in place: this row cannot produce a
        // cycle either way, and rewriting it would claim a conversion that never
        // happened.
        $onlyDue = $this->card('OnlyDue', ['due' => 26]);
        $onlyStatement = $this->card('OnlyStatement', ['statement_day' => 8]);

        $this->migration()->up();

        $this->assertSame(26, $this->meta($onlyDue)['due']);
        $this->assertArrayNotHasKey('term_days', $this->meta($onlyDue));
        $this->assertSame(8, $this->meta($onlyStatement)['statement_day']);
    }

    public function test_it_keeps_the_other_keys_in_the_bag(): void
    {
        // The column is a JSON bag other fields share, so the rewrite must touch
        // the card term and nothing else. Key *order* is deliberately not
        // asserted: renaming the key necessarily moves it, and nothing reads this
        // column positionally, so pinning the order would only make the test
        // refuse a correct rewrite.
        $card = $this->card('WithExtras', ['due' => 26, 'statement_day' => 8, 'nickname' => 'work card']);

        $this->migration()->up();

        $stored = $this->meta($card);

        $this->assertArrayNotHasKey('due', $stored, 'The legacy key must be gone, not left alongside.');
        $this->assertSame(18, $stored['term_days']);
        $this->assertSame(8, $stored['statement_day']);
        $this->assertSame('work card', $stored['nickname']);
    }

    public function test_a_category_meta_row_is_not_touched_by_an_account_of_the_same_id(): void
    {
        // The join is on id alone, so without the morph type a category sharing
        // an id with a card would be rewritten as if it were that card.
        $card = $this->card('Decoy', ['due' => 26, 'statement_day' => 8]);

        $category = Category::create(['name' => 'Same id']);
        DB::table('meta')->insert([
            'model_id' => $category->id,
            'model_type' => Category::class,
            'meta' => json_encode(['due' => 26, 'statement_day' => 8]),
        ]);

        $this->migration()->up();

        $stored = json_decode(
            DB::table('meta')->where('model_id', $category->id)
                ->where('model_type', Category::class)->value('meta'),
            true
        );

        $this->assertSame(26, $stored['due'], 'The category row keeps the legacy key, untouched.');
        $this->assertSame(18, $this->meta($card)['term_days'], 'The card itself is still converted.');
    }

    public function test_rolling_back_leaves_the_data_alone(): void
    {
        // There is no safe inverse. N + S restores the due day only when the two
        // sum to 31 or less; for a card closing on the 25th with a 21-day term it
        // would produce the 46th, and writing that back would be worse than
        // leaving the column as it stands. The migration is one-way by design, so
        // the rollback says so rather than guessing.
        $card = $this->card('OneWay', ['due' => 26, 'statement_day' => 8]);

        $migration = $this->migration();
        $migration->up();
        $this->assertSame(18, $this->meta($card)['term_days']);

        $migration->down();

        $this->assertSame(18, $this->meta($card)['term_days'], 'down() must not invent a value.');
    }
}
