<?php

namespace App\Models;

use App\Enums\AccountType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class Account extends Model
{
    use HasFactory;
    use HasMeta;

    /**
     * The attributes a client may set: the table's columns less `id` and the two
     * timestamps, which are the database's to assign.
     *
     * `id` is here by omission rather than by accident. AccountData carries one,
     * because the edit form round-trips the whole table row, and the controller
     * hands that DTO straight to create() and update() -- so the id in the payload
     * is a number the client chose. Listing the rest and not this is what stops a
     * row being renumbered onto a free id, which would move it with nothing
     * recording that it had. Timestamps are set by Eloquent on save, which assigns
     * them through setAttribute rather than through fill(), so leaving them out
     * costs nothing and keeps a client from backdating created_at.
     *
     * MassAssignmentTest asserts this list against the accounts table, so a column
     * added to the migration and forgotten here fails rather than silently
     * ceasing to be written.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'status',
        'type',
        'ccy',
    ];

    /**
     * The cash account a securities account settles through.
     *
     * A method rather than a belongsTo, because the link lives in the meta bag
     * and Eloquent cannot join on a JSON path. That is the cost of keeping it
     * there: one query per call, no eager loading, and any "which brokerages
     * settle into this bank" question has to reach into the JSON. What it buys
     * is that a new account type needing a pointer to another account needs no
     * migration. Null for a cash or card account, which AccountMetaData prohibits
     * the field for rather than merely leaving it unset.
     */
    public function settlementAccount(): ?self
    {
        $id = $this->meta?->meta['settlement_account_id'] ?? null;

        return $id === null ? null : self::find($id);
    }

    /**
     * Reject a settlement target that is not a cash account in the settler's currency.
     *
     * Shared with TransactionController::settle(), which now lets the user name the
     * account a card is paid from, and with AccountData. A second copy of the rule
     * would let the settle dialog offer a target the account form refuses, or the other
     * way round, and neither would say so.
     *
     * Static and taking the settler's type and currency rather than the account itself,
     * because AccountData is a DTO and has no row: it validates a payload for an account
     * that may not exist yet. What a row adds -- refusing a target that is the account
     * itself -- is checked by `different:id` in the form's rules and by settle()'s own
     * refusal, which each have the id to hand.
     *
     * A method rather than a rule because only the database knows what the target is.
     *
     * @param  array{0: string, 1: string}  $wording  the subject and the verb, which
     *                                                differ per account type
     *
     * @throws ValidationException
     */
    public static function guardSettledFrom(self $target, string $type, string $ccy, array $wording): void
    {
        [$subject, $verb] = $wording;

        if ($target->type !== AccountType::Cash->value) {
            throw ValidationException::withMessages([
                'settlement_account_id' => sprintf(
                    'A %s can only %s a cash account, not a %s account.',
                    $subject,
                    $verb,
                    $target->type
                ),
            ]);
        }

        // Checked after the type: a wrong-type target is the more fundamental mismatch,
        // and naming its currency would imply converting could fix it.
        //
        // Refuse rather than convert. A *charge* in another currency is fine because
        // the user states it in the card's own currency (card_amount, which
        // CardStatement sums). A *bank* in another currency has no such figure and
        // nothing here converts between them, so the pairing is left unusable rather
        // than quietly miscounted.
        if ($target->ccy !== $ccy) {
            throw ValidationException::withMessages([
                'settlement_account_id' => sprintf(
                    'A %s %s cannot %s a %s account.',
                    $ccy,
                    $subject,
                    $verb,
                    $target->ccy
                ),
            ]);
        }
    }

    /**
     * How this account is named, and the verb, in a refusal about its settlement
     * target. Spelled out because a brokerage settles into a bank while a card is paid
     * from one, and each message puts the verb in a different slot. A cash account is
     * named rather than defaulted to, so a new case fails loudly rather than quietly
     * refusing every target.
     *
     * @return array{0: string, 1: string}
     */
    public static function settlementWording(string $type): array
    {
        return match (AccountType::from($type)) {
            AccountType::Security => ['brokerage', 'settle into'],
            AccountType::Card => ['card', 'be paid from'],
            AccountType::Cash => ['cash account', 'settle into'],
        };
    }

    /**
     * Every account that may be a settlement target, for a picker.
     *
     * Cash only, and including inactive: a closed bank still holds history, and leaving
     * it out would give a card with no other option nowhere to be paid from. Not
     * filtered by currency, because the label carries it -- an incompatible bank is then
     * recognisable before it is refused rather than silently missing from the list.
     *
     * @return Collection<int, array{label: string, value: int}>
     */
    public static function settlementOptions(): Collection
    {
        return self::query()
            ->where('type', AccountType::Cash->value)
            ->orderBy('name')
            ->get(['id', 'name', 'ccy'])
            ->map(fn (self $account) => [
                'label' => "{$account->name} ({$account->ccy})",
                'value' => $account->id,
            ])
            ->values();
    }
}
