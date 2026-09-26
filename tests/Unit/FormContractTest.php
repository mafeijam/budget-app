<?php

namespace Tests\Unit;

use App\DTO\AccountData;
use App\Enums\AccountType;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
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
     * @return array<string, array{0: string}>
     */
    public static function accountTypes(): array
    {
        $cases = [];

        foreach (AccountType::cases() as $case) {
            $cases[$case->value] = [$case->value];
        }

        return $cases;
    }

    #[DataProvider('accountTypes')]
    public function test_a_field_the_server_requires_is_on_screen_for_every_account_type(string $type): void
    {
        // The hole the first three tests cannot see, and it is the same failure
        // that has already bitten this codebase twice.
        //
        // A control can exist in the template and still be unreachable. A v-if
        // gates it, and the v-if is a second, hand-maintained copy of a decision
        // the validation rules already make: meta_data.due carries
        // required_if:type,card, and the template hides the control behind
        // form.type === 'card'. Agreement today is two places agreeing by hand.
        //
        // So change the rule -- required_if:type,card,bond when the brokerage
        // starts carrying a due day, or a new type that turns out to need one --
        // and the server starts demanding a field the form does not show. The
        // save is rejected with "The due day field is required", the field is not
        // on screen, and the first test still passes because the v-model is
        // still there. The feature is unreachable and nothing in the suite fails.
        //
        // Derived on both sides rather than compared as literals, so widening a
        // required_if to two types is caught as readily as adding a case to the
        // enum.
        //
        // Strictly stronger than it needs to be, in one direction: a field the
        // server requires but no browser should send would fail here. That is
        // what SERVER_ONLY is for, and it is a two-entry list precisely so that
        // widening it is a visible edit. The alternative -- an allowlist of
        // exemptions -- is a list nobody reads until it is needed.
        $required = $this->requiredPaths($this->fields(AccountData::class), ['type' => $type]);
        $hidden = array_diff(
            $required,
            $this->visiblePaths('FormAccount.vue', ['type' => $type])
        );

        $this->assertSame(
            [],
            array_values($hidden),
            sprintf(
                'A %s account must send %s, but FormAccount.vue shows no control for %s '
                    .'when form.type is %s. The save is rejected with a field error the user '
                    .'has nothing on screen to fix.',
                $type,
                implode(', ', $hidden),
                implode(', ', $hidden),
                $type
            )
        );
    }

    #[DataProvider('accountTypes')]
    public function test_a_field_the_server_prohibits_is_off_screen_wherever_it_is_prohibited(string $type): void
    {
        // The other half of the same pair, and the reason the gap matters in both
        // directions.
        //
        // settlement_account_id is prohibited_unless:type,security, so it must not
        // be shown for any other type: a value the server refuses is worse than
        // no value at all, because the user cannot tell the two apart.
        //
        // What keeps this working today is not the template. It is
        // form-helpers.js:102, a watcher that nulls the field when the type is
        // not security -- a fourth hand-written copy of the same condition, in
        // shared JavaScript, invisible to every other test here. And it is
        // defensive: it only fires when oldVal is set and the form is dirty, so a
        // value arriving from anywhere else is not covered.
        //
        // This asserts the structural property instead, which needs no
        // cooperation from the watcher: if the field is off screen, the watcher
        // has nothing to clean up.
        //
        // A stricter reading than the rules require, and deliberately so. Showing
        // a prohibited field and clearing it instead is a legitimate design --
        // the user sees the link they are discarding rather than having it vanish
        // -- and it is what the watcher exists to support. It is not adopted here
        // because the watcher's own condition makes it partial: it fires only when
        // oldVal is set and the form is dirty, so a value arriving from a
        // restored form, a browser back-navigation or a direct request is not
        // covered, and the failure is a rejected save the user cannot act on.
        //
        // Should that design ever be wanted, this is the assertion to relax --
        // and relaxing it should come with a browser check that the clear
        // happens, since the PHP suite cannot see it either way.
        $prohibited = $this->prohibitedPaths($this->fields(AccountData::class), ['type' => $type]);
        $shown = array_intersect(
            $prohibited,
            $this->visiblePaths('FormAccount.vue', ['type' => $type])
        );

        $this->assertSame(
            [],
            array_values($shown),
            sprintf(
                'AccountData prohibits %s for a %s account, but FormAccount.vue shows the '
                    .'control anyway. Any value left in it fails the save over a field the '
                    .'user is being shown.',
                implode(', ', $shown),
                $type
            )
        );
    }

    public function test_every_field_the_server_can_reject_has_an_error_binding(): void
    {
        // A rejected save the user cannot diagnose.
        //
        // The other error assertion checks that a binding names a real field. This
        // is the other direction: that a field the server can reject has a binding
        // to show the message in. Without one the save fails, the response carries
        // the message, and the template never reads it -- the form simply does not
        // close, with nothing on screen to say why.
        //
        // Only fields with a declared rule. The enum-typed properties -- type,
        // status, ccy -- are excluded and deliberately so: they can be rejected by
        // a membership check derived from the type, but a browser cannot produce a
        // value outside the enum, because the dropdowns are fed from those same
        // enums. Only a hand-written request could, and a hand-written request
        // reads the JSON, not the form.
        $rejectable = array_keys(array_filter(
            $this->fields(AccountData::class),
            fn (array $rules) => $rules !== []
        ));

        $silent = array_diff($rejectable, $this->errorMessageKeys('FormAccount.vue'));

        $this->assertSame(
            [],
            array_values($silent),
            sprintf(
                'FormAccount.vue has no :error-message binding for %s, which AccountData '
                    .'can reject. The save fails and the message is never displayed.',
                implode(', ', $silent)
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
            array_keys($this->fields($dto)),
            fn (string $path) => ! in_array(explode('.', $path)[0], self::SERVER_ONLY, true)
        ));
    }

    /**
     * Every path a form is expected to bind, mapped to the rules that can reject
     * it.
     *
     * One walk serves all six assertions, which is the point: if the coverage
     * test and the visibility test read the field list from different traversals
     * they could disagree by a field, and the visibility test would then compare
     * one set of required fields against a differently-derived set of visible
     * ones and pass for the wrong reason.
     *
     * Recursion into nested Data objects is what turns AccountData's single
     * meta_data property into the two things the form actually edits,
     * meta_data.due and meta_data.statement_day. Stopping at the top level would
     * expect one control for a JSON bag the form has no single control for.
     *
     * A union or intersection type is treated as a leaf rather than descended
     * into, since there is no single class to recurse into. None of the current
     * DTOs use one; if one appears it will show up as a field the form has no
     * control for, which is the safe direction to fail.
     *
     * Rules are read off the class that owns the property, so meta_data.due's
     * rules come from AccountMetaData rather than from AccountData. A DTO with
     * no rules() of its own contributes paths with no rules, which is the same
     * thing for every assertion here.
     *
     * @return array<string, string[]>
     */
    private function fields(string $class, string $prefix = ''): array
    {
        $rules = $this->rulesFor($class);
        $paths = [];

        foreach ($this->constructorParams($class) as $name => $type) {
            $path = $prefix === '' ? $name : $prefix.'.'.$name;

            if ($type !== null && is_subclass_of($type, Data::class)) {
                $paths += $this->fields($type, $path);

                continue;
            }

            $paths[$path] = $rules[$name] ?? [];
        }

        return $paths;
    }

    /**
     * @return array<string, class-string|null>
     */
    private function constructorParams(string $class): array
    {
        $constructor = (new ReflectionClass($class))->getConstructor();
        $params = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            $params[$parameter->getName()] = $type instanceof ReflectionNamedType && ! $type->isBuiltin()
                ? $type->getName()
                : null;
        }

        return $params;
    }

    /**
     * A Data's declared rules, or nothing if it declares none.
     *
     * Invoked reflectively rather than called directly because the signature is
     * not uniform: AccountData::rules() takes the Request, to build
     * Rule::unique()->ignore() from the route's account, while
     * AccountMetaData::rules() takes nothing. A Data added later with a
     * different signature should be visible here as a failure, not as a silently
     * empty rule set that would make its fields look unconditionally required.
     *
     * The Request is a fabricated one. The only thing AccountData does with it is
     * ask for the route's account, and off a real route that returns null --
     * exactly what a create produces, where the uniqueness check correctly
     * ignores nothing. So the fabricated request produces the same rules the
     * real one would, without needing a database or a resolved route.
     *
     * @return array<string, mixed>
     */
    private function rulesFor(string $class): array
    {
        if (! method_exists($class, 'rules')) {
            return [];
        }

        $arguments = [];

        foreach ((new ReflectionMethod($class, 'rules'))->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType
                && ! $type->isBuiltin()
                && is_a($type->getName(), Request::class, true)
            ) {
                $arguments[] = Request::create('/accounts', 'POST');
            }
        }

        return (array) $class::rules(...$arguments);
    }

    /**
     * The fields the DTO demands be present, given a form's state.
     *
     * Bare `required` counts, as does a `required_if` whose condition holds.
     * Nothing else does: `exists`, `between` and `integer` reject a value that is
     * present, so a field carrying only those is reachable whenever its control
     * is on screen, whatever the control's own `min`/`max` happen to say.
     *
     * @param  array<string, string[]>  $fieldRules
     * @param  array<string, string>  $form
     * @return string[]
     */
    private function requiredPaths(array $fieldRules, array $form): array
    {
        $paths = [];

        foreach ($fieldRules as $path => $rules) {
            foreach ($rules as $rule) {
                // rules() is not a list of strings. Rule::unique() and friends
                // come back as objects, and handing one to str_starts_with() is a
                // TypeError. `name` happens to put `required` first so the object
                // is never reached, which is luck rather than design -- a field
                // whose rules began with a Rule object would take the whole test
                // file down with it.
                if (! is_string($rule)) {
                    continue;
                }

                if ($rule === 'required' || $this->requiredIf($rule, $form)) {
                    $paths[] = $path;
                    break;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * The fields the DTO refuses outright, given a form's state.
     *
     * @param  array<string, string[]>  $fieldRules
     * @param  array<string, string>  $form
     * @return string[]
     */
    private function prohibitedPaths(array $fieldRules, array $form): array
    {
        $paths = [];

        foreach ($fieldRules as $path => $rules) {
            foreach ($rules as $rule) {
                // Objects skipped for the reason given in requiredPaths().
                if (! is_string($rule)) {
                    continue;
                }

                if ($rule === 'prohibited'
                    || $this->prohibitedIf($rule, $form)
                    || $this->prohibitedUnless($rule, $form)
                ) {
                    $paths[] = $path;
                    break;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  array<string, string>  $form
     */
    private function requiredIf(string $rule, array $form): bool
    {
        $condition = $this->condition($rule, 'required_if', $form);

        return $condition !== null && in_array($form[$condition[0]], $condition[1], true);
    }

    /**
     * @param  array<string, string>  $form
     */
    private function prohibitedIf(string $rule, array $form): bool
    {
        $condition = $this->condition($rule, 'prohibited_if', $form);

        return $condition !== null && in_array($form[$condition[0]], $condition[1], true);
    }

    /**
     * @param  array<string, string>  $form
     */
    private function prohibitedUnless(string $rule, array $form): bool
    {
        $condition = $this->condition($rule, 'prohibited_unless', $form);

        return $condition !== null && ! in_array($form[$condition[0]], $condition[1], true);
    }

    /**
     * The field and values a conditional rule names, or null if the rule is not
     * conditional on a field this test varies.
     *
     * @param  array<string, string>  $form
     * @return array{0: string, 1: string[]}|null
     */
    private function condition(string $rule, string $prefix, array $form): ?array
    {
        // The colon is part of the prefix, so `prohibited_unless` cannot be picked
        // up as `prohibited_unless`-shaped by a call for `prohibited_if`.
        if (! str_starts_with($rule, $prefix.':')) {
            return null;
        }

        $parameters = array_map('trim', explode(',', substr($rule, strlen($prefix) + 1)));
        $field = (string) array_shift($parameters);

        // A conditional on some other field -- `required_if:owner_id,7` is a real
        // shape -- says nothing about whether the field is reachable from this
        // form for the state under test. Reported as null rather than as
        // unsatisfied on purpose: treating an unrelated condition as unsatisfied
        // would make requiredPaths return every field for every state, and
        // prohibitedPaths return every prohibited field for every state, which
        // buries the real signal in noise.
        return array_key_exists($field, $form) ? [$field, $parameters] : null;
    }

    /**
     * The fields the template offers, given a form's state.
     *
     * @param  array<string, string>  $form
     * @return string[]
     */
    private function visiblePaths(string $vue, array $form): array
    {
        $visible = [];

        foreach ($this->conditionalControls($vue) as [$path, $conditions]) {
            $shown = true;

            foreach ($conditions as $condition) {
                if (! $this->expressionHolds($condition, $form)) {
                    $shown = false;
                    break;
                }
            }

            if ($shown) {
                $visible[] = $path;
            }
        }

        return array_values(array_unique($visible));
    }

    /**
     * Every v-model in the template, each with the v-if conditions enclosing it.
     *
     * Depth-tracked rather than paired by indentation or by regex, because the
     * template nests a <template> inside a control -- the #no-option slot on the
     * settlement picker -- and a slot template carries no v-if. Pairing an opening
     * <template> with the next </template> would read that slot as the start of
     * the surrounding block and attribute the wrong condition to everything after
     * it.
     *
     * One entry per occurrence rather than per path, so two controls for the same
     * field under different conditions are judged separately instead of having
     * their conditions unioned into one that must hold for either to show.
     *
     * @return array<int, array{0: string, 1: string[]}>
     */
    private function conditionalControls(string $vue): array
    {
        $template = $this->template($vue);

        preg_match_all(
            '/<template[^>]*>|<\/template>|v-model="form\.([A-Za-z_]\w*(?:\.[A-Za-z_]\w*)*)"/',
            $template,
            $tokens,
            PREG_OFFSET_CAPTURE
        );

        $stack = [];
        $controls = [];

        foreach ($tokens[0] as $index => $token) {
            $text = $token[0];

            if (str_starts_with($text, '</template')) {
                array_pop($stack);

                continue;
            }

            if (str_starts_with($text, '<template')) {
                $stack[] = preg_match('/v-if="([^"]*)"/', $text, $condition) ? $condition[1] : null;

                continue;
            }

            // A slot template in between pushes null and is filtered out here,
            // which is what keeps it from resetting the conditions around it.
            $controls[] = [
                $tokens[1][$index][0],
                array_values(array_filter($stack, fn (?string $c) => $c !== null)),
            ];
        }

        return $controls;
    }

    /**
     * Whether a v-if expression holds for a given form state.
     *
     * A small evaluator, not a JavaScript interpreter. It handles the shapes a
     * visibility condition plausibly takes and refuses everything else by
     * failing the test, which matters more than breadth does here: silently
     * mis-evaluating a condition would have this test assert that a field is
     * visible when it is not, the exact inversion of what it exists to catch.
     *
     * Supported, with && binding tighter than || as in JavaScript:
     *
     *   form.type === 'card'   form.type !== 'card'   form.due == "15"   form.day > 3
     *   form.settlement_account_id   !form.settlement_account_id
     *
     * @param  array<string, string>  $form
     */
    private function expressionHolds(string $expression, array $form): bool
    {
        foreach (array_map('trim', explode('||', $expression)) as $disjunction) {
            $holds = true;

            foreach (array_map('trim', explode('&&', $disjunction)) as $term) {
                if (! $this->termHolds($term, $form)) {
                    $holds = false;
                    break;
                }
            }

            if ($holds) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $form
     */
    private function termHolds(string $term, array $form): bool
    {
        $pattern = '/^form\.([A-Za-z_]\w*(?:\.[A-Za-z_]\w*)*)\s*'
            .'(===|!==|==|!=)\s*'
            ."(?:'([^']*)'|\"([^\"]*)\"|(-?[0-9.]+))$/";

        // PREG_UNMATCHED_AS_NULL rather than matching first and testing for '',
        // so that `form.x === ''` is distinguishable from `form.x === 'card'`.
        //
        // Matched before the bare-path and negation cases below because
        // `form.type !== 'card'` opens with a character that also reads as a
        // negation.
        if (preg_match($pattern, $term, $m, PREG_UNMATCHED_AS_NULL)) {
            $expected = $m[3] ?? $m[4] ?? $m[5];
            $equal = (string) ($form[$m[1]] ?? '') === $expected;

            return $m[2] === '===' || $m[2] === '==' ? $equal : ! $equal;
        }

        if (str_starts_with($term, '!')) {
            return ! $this->termHolds(trim(substr($term, 1)), $form);
        }

        if (preg_match('/^form\.([A-Za-z_]\w*(?:\.[A-Za-z_]\w*)*)$/', $term, $m)) {
            $value = $form[$m[1]] ?? null;

            return $value !== null && $value !== '';
        }

        $this->fail(sprintf(
            'FormContractTest cannot evaluate the visibility condition `%s`, so the visibility '
                .'assertions cannot be trusted. It handles comparisons against a quoted string or '
                .'a number, a bare form path, a leading `!`, and && / || chains. Widen '
                .'termHolds() to cover this one.',
            $term
        ));
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
     * The field names the template has somewhere to *display* a message for.
     *
     * Narrower than errorKeys() on purpose. :error only flips the control red;
     * :error-message is what renders the sentence, and a form carrying the first
     * without the second rejects the save and says nothing about why. Found by
     * deleting an :error-message line and watching the test stay green, because
     * errorKeys() reads both attributes and the surviving :error binding still
     * counted.
     *
     * @return string[]
     */
    private function errorMessageKeys(string $vue): array
    {
        $template = $this->template($vue);

        $flat = $this->match($template, '/:error-message="form\.errors\.([A-Za-z_]\w*)"/', true);
        $nested = array_map(
            fn (string $key) => trim($key, '\'"'),
            $this->match($template, '/:error-message="form\.errors\[([^\]]+)\]"/', false)
        );

        return array_values(array_unique(array_merge($flat, $nested)));
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
