<?php

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rewrites stored card `due` values from a day of the month to a number of days,
 * under the name `term_days`.
 *
 * Both the name and the meaning changed, so both happen here. The name had to
 * change because `due` reads as a day of the month and now holds an interval --
 * a field whose name contradicts its contents is a field that needs a comment
 * explaining it, and the name is the cheaper place to say it. The meaning had to
 * change under the data, which is the one change no test on new code catches:
 * the same stored 26 read as 26 days after the closing once it became a term.
 * Nothing errors and nothing looks wrong -- the due dates just quietly move.
 *
 * Renaming and reconverting are one migration rather than two on purpose. A
 * migration that only renamed the key would leave the column named
 * `term_days` while holding a day of the month, which is the one state in which
 * the data is actively misleading rather than merely stale.
 *
 * The conversion is exact only when the due day falls after the statement day,
 * which is when the old due date lay in the closing month and the interval is
 * the same in every month. Otherwise the old due date lay in the following
 * month, making the interval daysInMonth - S + D, and since daysInMonth varies
 * no single stored term reproduces it: 21 is exact for a 31-day closing month
 * and a day or two out in the others. That case is rare and the rewrite errs
 * late rather than early, so a card is never presented as due before it is.
 *
 * One-way on purpose. N + S recovers the due day only when the sum is 31 or
 * less; for a card closing on the 25th with a 21-day term it would produce the
 * 46th, so the reverse would write back a value that never existed.
 */
return new class extends Migration
{
    /**
     * The key this migration replaces, and the one it writes. Named as constants
     * so the read and the write cannot drift apart, which is the whole risk in
     * a migration that renames the thing it is reading.
     */
    private const LEGACY_KEY = 'due';

    private const KEY = 'term_days';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $accountType = (new Account)->getMorphClass();

        $rows = DB::table('meta')
            ->join('accounts', function ($join) use ($accountType) {
                $join->on('meta.model_id', '=', 'accounts.id')
                    ->where('meta.model_type', '=', $accountType);
            })
            ->where('accounts.type', '=', 'card')
            ->select(['meta.id', 'meta.meta'])
            ->get();

        foreach ($rows as $row) {
            $meta = json_decode($row->meta, true);

            if (! is_array($meta)) {
                continue;
            }

            $due = $meta[self::LEGACY_KEY] ?? null;
            $statementDay = $meta['statement_day'] ?? null;

            // Nothing to convert a single number against. A card row predating
            // statement_day would have its terms invented here, and those terms
            // would then decide real due dates.
            if (! is_numeric($due) || ! is_numeric($statementDay)) {
                continue;
            }

            $term = self::termFor((int) $due, (int) $statementDay);

            unset($meta[self::LEGACY_KEY]);
            $meta[self::KEY] = $term;

            DB::table('meta')->where('id', $row->id)->update(['meta' => json_encode($meta)]);
        }
    }

    /**
     * The number of days from a statement closing to the due day it used to name.
     *
     * Due day after the statement day: the old due date was in the closing month,
     * so the interval is D - S and holds in every month length.
     *
     * Due day on or before the statement day: the old due date was in the
     * following month, so the interval is daysInMonth - S + D. 31 is the longest
     * a month can be and so the only value that can be stored, which makes the
     * result exact for a 31-day closing month and late by the shortfall in the
     * shorter ones.
     */
    public static function termFor(int $dueDay, int $statementDay): int
    {
        return $dueDay > $statementDay
            ? $dueDay - $statementDay
            : 31 - $statementDay + $dueDay;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally empty. See the class docblock: there is no inverse that
        // is correct for every row, and a wrong one is worse than none. Rolling
        // back the code that reads the column as a term, and leaving the data as
        // terms, at least fails visibly on the old model rather than quietly
        // inventing due days.
    }
};
