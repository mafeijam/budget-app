<?php

namespace App\Support;

use App\DTO\RecurringTransactionData;
use App\Models\Account;
use App\Models\RecurringTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes each recurring transaction's due occurrences as pending rows, which count
 * toward no balance until they are posted -- so a figure that changed this month is
 * corrected before it moves anything.
 */
class RecurringPayments
{
    /**
     * Every active rule, through $through.
     *
     * @return array{recorded: int, refusals: list<string>}
     */
    public static function recordDue(Carbon $through): array
    {
        $recorded = 0;
        $refusals = [];

        foreach (RecurringTransaction::where('active', true)->orderBy('id')->get() as $rule) {
            $result = self::record($rule, $through);

            $recorded += $result['recorded'];

            if ($result['refusal'] !== null) {
                $refusals[] = $result['refusal'];
            }
        }

        return compact('recorded', 'refusals');
    }

    /**
     * Stops at the first occurrence refused rather than skipping it, so the rule's next
     * date stays in the past on the recurring page instead of a payment vanishing.
     *
     * @return array{recorded: int, refusal: ?string}
     */
    public static function record(RecurringTransaction $rule, Carbon $through): array
    {
        $recorded = 0;

        if (! $rule->active) {
            return ['recorded' => 0, 'refusal' => null];
        }

        foreach ($rule->dueThrough($through) as $date) {
            try {
                $data = RecurringTransactionData::fromModel($rule)->transactionOn($date);
                $data->guardNewChargePeriod(Account::with('meta')->find($rule->account_id));
            } catch (ValidationException $e) {
                return [
                    'recorded' => $recorded,
                    'refusal' => sprintf(
                        '[%s] was not recorded for %s: %s',
                        $rule->description,
                        $date,
                        collect($e->errors())->flatten()->first()
                    ),
                ];
            }

            DB::transaction(function () use ($data, $rule, $date) {
                $data->write();

                $rule->last_recorded_on = $date;
                $rule->save();
            });

            $recorded++;
        }

        return ['recorded' => $recorded, 'refusal' => null];
    }
}
