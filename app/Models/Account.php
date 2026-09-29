<?php

namespace App\Models;

use App\Enums\AccountType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class Account extends Model
{
    use HasFactory;
    use HasMeta;

    /**
     * Not `id`: AccountData round-trips it and the controller passes the DTO straight to
     * create(), so a client could renumber a row. MassAssignmentTest pins this to the table.
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
     * The bank a brokerage settles into or a card is paid from. A method, not a
     * belongsTo, since the link is in the meta bag: one query per call, no eager loading.
     */
    public function settlementAccount(): ?self
    {
        $id = $this->meta?->meta['settlement_account_id'] ?? null;

        return $id === null ? null : self::find($id);
    }

    /**
     * One rule for AccountData and settle(), so the dialog and the form cannot disagree.
     * Takes the type and currency rather than an account, which AccountData may not have yet.
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

        // Refused rather than converted: unlike a charge, a bank has no stated figure
        // in the other currency, so the pairing would be miscounted.
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
     * A brokerage settles into a bank; a card is paid from one.
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
     * Inactive banks included: a card with no other bank would have nowhere to be paid
     * from. $ccy narrows the settle dialog's list; the account form cannot narrow, as its
     * currency changes while it is filled in, so its labels carry the currency instead.
     *
     * @return Collection<int, array{label: string, value: int}>
     */
    public static function settlementOptions(?string $ccy = null): Collection
    {
        return self::query()
            ->where('type', AccountType::Cash->value)
            ->when($ccy, fn (Builder $query) => $query->where('ccy', $ccy))
            ->orderBy('name')
            ->get(['id', 'name', 'ccy'])
            ->map(fn (self $account) => [
                'label' => "{$account->name} ({$account->ccy})",
                'value' => $account->id,
            ])
            ->values();
    }
}
