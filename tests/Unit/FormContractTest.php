<?php

namespace Tests\Unit;

use App\DTO\AccountData;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionNamedType;
use Spatie\LaravelData\Data;
use Tests\TestCase;

/**
 * Keeps a form template and the DTO it submits in agreement.
 *
 * The PHP suite is structurally unable to see this gap. A DTO property can be
 * fully specified, fully rule-tested, fully documented, and still have no
 * control in the browser -- at which point the server demands a field the user
 * has no way to supply and the feature is unreachable through the form. That is
 * not hypothetical here: settlement_account_id and meta_data.statement_day both
 * shipped that way, every securities account became uncreatable through the
 * browser, and 275 tests stayed green. A unit test on the DTO cannot fail,
 * because nothing about the DTO is wrong.
 *
 * So the check is here instead, in the one place that can see both sides.
 *
 * This is a contract test, not a generator. It asserts the template covers the
 * DTO; it does not build the template from it. Two things it deliberately does
 * not attempt, because both would be worse than nothing:
 *
 *  - Field order is not compared. The DTO's declaration order and the form's
 *    layout order are different concerns, and pinning them together would mean a
 *    layout change has to be justified in a DTO test.
 *
 *  - Visibility conditions are not derived from the rules. The template's
 *    v-if="form.type === 'card'" restates AccountMetaData's required_if:type,card,
 *    and nothing here checks they agree -- a validation rule does not describe a
 *    layout, and no amount of parsing turns one into the other. What is checked
 *    is that a condition reads a field the DTO actually has, because a v-if on a
 *    misspelled field is silently always false and a control behind it silently
 *    never appears.
 *
 * FormTransaction is absent from the provider on purpose. TransactionData has
 * 14 properties and FormTransaction.vue binds 2, one of which (form.name) is not
 * a property of TransactionData at all, plus an error binding to
 * form.errors.account where the field is account_id. Wiring it in today would
 * land that debt as a wall of failures. It joins when the form is built.
 */
class FormContractTest extends TestCase
{
    /**
     * DTO properties the browser neither supplies nor reads.
     *
     * Lives here rather than on the DTO because it is a statement about the
     * form, not about the data: `id` is the server's, `created_at` is stamped in
     * the constructor. Adding a property to this list forces a small, visible
     * edit here, which is the right friction for a judgement call.
     *
     * Matched on the top-level name, so a nested Data with its own `id` would be
     * excluded too. That is the wrong answer for a nested DTO in general; it is
     * right for the two that exist, neither of which has one.
     */
    private const SERVER_ONLY = ['id', 'created_at'];

    /**
     * @return array<string, array{0: string, 1: class-string}>
     */
    public static function forms(): array
    {
        return [
            'account' => ['FormAccount.vue', AccountData::class],
        ];
    }

    #[DataProvider('forms')]
    public function test_every_editable_field_has_a_control(string $vue, string $dto): void
    {
        // The assertion that matters, and the one nothing else in the suite can
        // make. A DTO property with no v-model is a field the server requires
        // and the user cannot reach.
        $missing = array_diff($this->expectedFields($dto), $this->controls($vue));

        $this->assertSame(
            [],
            array_values($missing),
            sprintf(
                '%s has no control for %s. The server reads %s but the form never sends it, '
                    .'so the field cannot be supplied through the browser.',
                $vue,
                implode(', ', $missing),
                implode(', ', $missing)
            )
        );
    }

    #[DataProvider('forms')]
    public function test_the_form_binds_no_field_the_dto_does_not_have(string $vue, string $dto): void
    {
        // The other direction, and the one that catches a rename: a v-model on a
        // field the DTO dropped binds undefined, so the value silently never
        // arrives rather than failing visibly.
        $expected = $this->expectedFields($dto);
        $stray = array_unique(array_merge($this->controls($vue), $this->conditions($vue)));

        $this->assertSame(
            [],
            array_values(array_diff($stray, $expected)),
            sprintf(
                '%s binds %s, which %s does not have. A v-model on a field the DTO '
                    .'dropped binds undefined, so the value never arrives.',
                $vue,
                implode(', ', array_diff($stray, $expected)),
                class_basename($dto)
            )
        );
    }

    #[DataProvider('forms')]
    public function test_every_error_binding_names_a_real_field(string $vue, string $dto): void
    {
        // Worth its own assertion because nothing catches it. Inertia types the
        // error bag as a plain string map, so form.errors.account is legal
        // everywhere and always -- it reads undefined, renders no message, and
        // the user is left with a rejected save and no reason. FormTransaction
        // has exactly this on account_id today.
        $expected = $this->expectedFields($dto);
        $stray = array_diff(array_unique($this->errorKeys($vue)), $expected);

        $this->assertSame(
            [],
            array_values($stray),
            sprintf(
                '%s reads errors for %s, which %s has no field for. The message will '
                    .'never render: an unknown error key reads undefined rather than failing.',
                $vue,
                implode(', ', $stray),
                class_basename($dto)
            )
        );
    }

    #[DataProvider('forms')]
    public function test_every_form_reference_uses_a_recognised_syntax(string $vue, string $dto): void
    {
        // The fail-closed guard, and the reason the other three can be trusted.
        // They parse the template with three regexes covering the syntaxes in use
        // today; a fourth would be silently skipped, quietly weakening every
        // assertion above without any of them noticing. So the total number of
        // `form.` references has to equal the number the three accounted for. A
        // mismatch means something is being read that this test cannot see, and
        // the right response is to fail rather than to keep going.
        $template = $this->template($vue);

        $seen = count($this->controls($vue, false))
            + count($this->conditions($vue, false))
            + count($this->errorKeys($vue, false));

        $this->assertSame(
            substr_count($template, 'form.'),
            $seen,
            sprintf(
                '%s has %d `form.` references but only %d are v-model, v-if or an '
                    .'error binding. Something in the template is read in a syntax this '
                    .'test does not parse, so the assertions above are not seeing all '
                    .'of it. Widen the regexes before trusting them.',
                $vue,
                substr_count($template, 'form.'),
                $seen
            )
        );
    }

    /**
     * The leaf paths a form is expected to bind, derived from the DTO.
     *
     * Dotted rather than nested, because that is how the error bag keys them
     * (`meta_data.due`) even though the form binds them nested
     * (`form.meta_data.due`). One spelling on both sides of every comparison.
     *
     * @return string[]
     */
    private function expectedFields(string $dto): array
    {
        return array_values(array_filter(
            $this->leafPaths($dto),
            fn (string $path) => ! in_array(explode('.', $path)[0], self::SERVER_ONLY, true)
        ));
    }

    /**
     * Every path reachable through a Data's constructor, recursing into nested
     * Data objects.
     *
     * Recursion is the point: AccountData's meta_data is one property, but what
     * the form edits is meta_data.due and meta_data.statement_day. Stopping at
     * the top level would expect one control for a JSON bag the form has no
     * single control for.
     *
     * A union or intersection type is treated as a leaf rather than descended
     * into, since there is no single class to recurse into. None of the current
     * DTOs use one; if one appears it will show up as a field the form has no
     * control for, which is the safe direction to fail.
     *
     * @return string[]
     */
    private function leafPaths(string $class, string $prefix = ''): array
    {
        $constructor = (new ReflectionClass($class))->getConstructor();

        if ($constructor === null) {
            return [];
        }

        $paths = [];

        foreach ($constructor->getParameters() as $parameter) {
            $path = $prefix === '' ? $parameter->getName() : $prefix.'.'.$parameter->getName();
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType
                && ! $type->isBuiltin()
                && is_subclass_of($type->getName(), Data::class)
            ) {
                $paths = array_merge($paths, $this->leafPaths($type->getName(), $path));

                continue;
            }

            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * The paths a v-model binds: the fields a user can actually edit.
     *
     * @return string[]
     */
    private function controls(string $vue, bool $unique = true): array
    {
        return $this->match(
            $this->template($vue),
            '/v-model="form\.([A-Za-z_]\w*(?:\.[A-Za-z_]\w*)*)"/',
            $unique
        );
    }

    /**
     * The paths a v-if reads.
     *
     * Checked as valid field references but not as controls, and the difference
     * is deliberate: form.type appears in a v-if twice and a v-model once, so
     * counting reads as controls would let a read stand in for a missing input.
     *
     * @return string[]
     */
    private function conditions(string $vue, bool $unique = true): array
    {
        return $this->match(
            $this->template($vue),
            '/v-if="form\.([A-Za-z_]\w*(?:\.[A-Za-z_]\w*)*)/',
            $unique
        );
    }

    /**
     * The field names the template reads errors for.
     *
     * Two syntaxes, because the template uses both: a dot for a flat field and
     * a bracket for a dotted path, which is how a nested error key has to be
     * written. Every key appears twice, once in :error and once in
     * :error-message, so callers that compare sets want them deduplicated.
     *
     * The bracket form is matched as "anything up to the closing bracket" and the
     * quotes trimmed afterwards, rather than by naming them in the pattern. A
     * quote inside a character class has to survive two layers of PHP string
     * escaping to reach PCRE intact, and getting it wrong does not throw -- the
     * pattern silently matches nothing, which is the same as not having the
     * assertion at all.
     *
     * @return string[]
     */
    private function errorKeys(string $vue, bool $unique = true): array
    {
        $template = $this->template($vue);

        $flat = $this->match($template, '/form\.errors\.([A-Za-z_]\w*)/', $unique);
        $nested = array_map(
            fn (string $key) => trim($key, '\'"'),
            $this->match($template, '/form\.errors\[([^\]]+)\]/', false)
        );

        $keys = array_merge($flat, $nested);

        return $unique ? array_values(array_unique($keys)) : $keys;
    }

    /**
     * @return string[]
     */
    private function match(string $subject, string $pattern, bool $unique): array
    {
        preg_match_all($pattern, $subject, $matches);

        return $unique ? array_values(array_unique($matches[1])) : $matches[1];
    }

    /**
     * A form template, without its script block.
     *
     * Only the template is read. The script holds the same references -- the
     * useWatchTarget watcher reads form.type and writes
     * form.settlement_account_id -- and those are shared composables rather than
     * anything this form owns, so folding them in would attribute one file's
     * behaviour to another. It is a known gap, not an oversight.
     */
    private function template(string $vue): string
    {
        $path = resource_path("js/components/Form/{$vue}");

        $this->assertFileExists($path, "No such form component: {$path}");

        $source = (string) file_get_contents($path);

        // before_needle = true. The single-argument form returns everything from
        // the needle onward, which is the script block -- the opposite of what is
        // wanted here, and it fails silently: the template's v-models go
        // unread and the coverage assertion reports every field as missing.
        $template = strstr($source, '<script', true);

        $this->assertNotFalse($template, "{$vue} has no <script> block, so it has no template either.");
        $this->assertStringContainsString('v-model', $template, "{$vue} has no v-model in its template.");

        return $template;
    }
}
