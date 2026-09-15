<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module;

use App\Engine\Module\DependencyResolver;
use App\Engine\Module\ModuleContext;
use App\Engine\Module\ModuleDefinition;
use App\Engine\Module\ModuleException;
use App\Engine\Module\ModuleKind;
use App\Engine\Module\ModuleStage;
use App\Tests\Support\TestCase;

/**
 * The order modules register in, and the five ways that order cannot exist.
 *
 * Built from contexts in memory rather than fixture directories, so each test
 * says its whole scenario in a few lines: which modules exist, what they
 * declare, and what the answer must be.
 */
final class DependencyResolverTest extends TestCase
{
    /**
     * A module as the resolver sees one: loaded, versioned, with declarations.
     *
     * @param array<string, string> $requires   id => constraint
     * @param array<string, string> $optionally id => constraint
     */
    private function module(
        string $id,
        ?string $version = '1.0.0',
        array $requires = [],
        array $optionally = [],
    ): ModuleContext {
        [$kind, $directory] = \str_contains($id, '/') ? \explode('/', $id, 2) : [$id, $id];

        $context = new ModuleContext(
            ModuleDefinition::create(ModuleKind::from($kind), '/modules/' . $id, $directory),
        );
        $context->enterStage(ModuleStage::Loading);

        if ($version !== null) {
            $context->version($version);
        }

        foreach ($requires as $target => $constraint) {
            $context->requires($target, $constraint);
        }

        foreach ($optionally as $target => $constraint) {
            $context->optionally($target, $constraint);
        }

        return $context;
    }

    /**
     * @param list<ModuleContext> $modules
     * @param list<string>        $disabled
     *
     * @return list<string>
     */
    private function resolve(array $modules, array $disabled = []): array
    {
        return (new DependencyResolver())->resolve($modules, $disabled);
    }

    // ---- ordering ----------------------------------------------------------

    /** An application that declares nothing registers exactly as it always did. */
    public function test_with_no_declarations_the_order_is_unchanged(): void
    {
        self::assertSame(
            ['shared', 'plugins/Alpha', 'plugins/Beta', 'gateways/Zeta'],
            $this->resolve([
                $this->module('shared'),
                $this->module('plugins/Alpha'),
                $this->module('plugins/Beta'),
                $this->module('gateways/Zeta'),
            ]),
        );
    }

    public function test_a_module_registers_after_what_it_requires(): void
    {
        self::assertSame(
            ['shared', 'plugins/Billing', 'plugins/Accounts'],
            $this->resolve([
                $this->module('shared'),
                $this->module('plugins/Accounts', requires: ['plugins/Billing' => '^1.0']),
                $this->module('plugins/Billing'),
            ]),
        );
    }

    /**
     * The stable part of the stable sort.
     *
     * Only the module that has to wait moves. Everything with no reason to
     * change position keeps it, so adding one declaration does not reshuffle
     * the registration order of modules that never mentioned it.
     */
    public function test_only_the_module_that_has_to_wait_moves(): void
    {
        self::assertSame(
            ['plugins/Beta', 'plugins/Charlie', 'plugins/Delta', 'plugins/Alpha', 'plugins/Echo'],
            $this->resolve([
                $this->module('plugins/Alpha', requires: ['plugins/Delta' => '*']),
                $this->module('plugins/Beta'),
                $this->module('plugins/Charlie'),
                $this->module('plugins/Delta'),
                $this->module('plugins/Echo'),
            ]),
        );
    }

    /** The specification's own example, end to end. */
    public function test_a_chain_resolves_in_dependency_order(): void
    {
        self::assertSame(
            ['shared', 'plugins/Billing', 'plugins/Payment', 'gateways/Stripe'],
            $this->resolve([
                $this->module('shared'),
                $this->module('plugins/Billing', requires: ['shared' => '^1.0']),
                $this->module('plugins/Payment', requires: ['plugins/Billing' => '^1.0']),
                $this->module('gateways/Stripe', requires: ['plugins/Payment' => '^1.0']),
            ]),
        );
    }

    /**
     * Shared first, gateways last, whatever is declared.
     *
     * This is what lets every module rely on shared without declaring it, and
     * it holds by construction: a dependency pointing at a later kind is
     * refused, so the sort can only ever move modules within their own kind.
     */
    public function test_kind_order_is_never_broken(): void
    {
        $order = $this->resolve([
            $this->module('shared'),
            $this->module('plugins/Alpha', requires: ['plugins/Zulu' => '*']),
            $this->module('plugins/Zulu'),
            $this->module('gateways/Aardvark', requires: ['plugins/Zulu' => '*']),
        ]);

        self::assertSame('shared', $order[0]);
        self::assertSame('gateways/Aardvark', $order[3]);
        self::assertSame(['plugins/Zulu', 'plugins/Alpha'], \array_slice($order, 1, 2));
    }

    public function test_an_optional_dependency_that_is_present_orders_registration(): void
    {
        self::assertSame(
            ['plugins/Crm', 'plugins/Accounts'],
            $this->resolve([
                $this->module('plugins/Accounts', optionally: ['plugins/Crm' => '^1.0']),
                $this->module('plugins/Crm'),
            ]),
        );
    }

    public function test_the_same_declarations_always_give_the_same_order(): void
    {
        $modules = [
            $this->module('shared'),
            $this->module('plugins/C', requires: ['plugins/A' => '*']),
            $this->module('plugins/A', requires: ['plugins/B' => '*']),
            $this->module('plugins/B'),
        ];

        $first = $this->resolve($modules);

        for ($i = 0; $i < 5; ++$i) {
            self::assertSame($first, $this->resolve($modules));
        }
    }

    // ---- missing -------------------------------------------------------------

    public function test_a_missing_required_module_refuses_to_boot(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('Module "plugins/Payment" requires "plugins/Billing ^1.0", which is not installed');

        $this->resolve([
            $this->module('plugins/Payment', requires: ['plugins/Billing' => '^1.0']),
        ]);
    }

    public function test_a_likely_typo_gets_a_suggestion(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('Did you mean "plugins/Billing"?');

        $this->resolve([
            $this->module('plugins/Billing'),
            $this->module('plugins/Payment', requires: ['plugins/Biling' => '*']),
        ]);
    }

    public function test_a_missing_optional_module_is_simply_absent(): void
    {
        self::assertSame(
            ['plugins/Accounts'],
            $this->resolve([$this->module('plugins/Accounts', optionally: ['plugins/Crm' => '^1.0'])]),
        );
    }

    // ---- disabled ------------------------------------------------------------

    /**
     * Disabled is not missing, and the message says which.
     *
     * "Not installed" sends somebody to composer or a deploy script; "disabled"
     * sends them to one line of configuration. The fixes are different, so the
     * errors are.
     */
    public function test_a_disabled_required_module_says_disabled_not_missing(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('installed but disabled');

        $this->resolve(
            [$this->module('plugins/Payment', requires: ['plugins/Billing' => '*'])],
            ['plugins/Billing'],
        );
    }

    public function test_a_disabled_optional_module_is_simply_absent(): void
    {
        self::assertSame(
            ['plugins/Accounts'],
            $this->resolve(
                [$this->module('plugins/Accounts', optionally: ['plugins/Crm' => '*'])],
                ['plugins/Crm'],
            ),
        );
    }

    // ---- versions ------------------------------------------------------------

    public function test_a_version_that_does_not_fit_refuses_to_boot(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('requires "plugins/Billing ^2.0", but the installed "plugins/Billing" is 1.4.0');

        $this->resolve([
            $this->module('plugins/Billing', '1.4.0'),
            $this->module('plugins/Payment', requires: ['plugins/Billing' => '^2.0']),
        ]);
    }

    /**
     * Present optional modules are held to their constraint.
     *
     * Optional means "works without it", not "works with any version of it":
     * an integration written for Crm 1.x that is handed Crm 2.0 is the failure
     * the constraint exists to stop.
     */
    public function test_an_optional_module_that_is_present_must_still_fit(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('requires "plugins/Crm ^1.0"');

        $this->resolve([
            $this->module('plugins/Accounts', optionally: ['plugins/Crm' => '^1.0']),
            $this->module('plugins/Crm', '2.0.0'),
        ]);
    }

    public function test_a_module_with_no_version_is_told_to_declare_one(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('declares no version at all');

        $this->resolve([
            $this->module('plugins/Billing', null),
            $this->module('plugins/Payment', requires: ['plugins/Billing' => '^1.0']),
        ]);
    }

    public function test_any_version_accepts_a_module_that_declared_none(): void
    {
        self::assertSame(
            ['plugins/Billing', 'plugins/Payment'],
            $this->resolve([
                $this->module('plugins/Billing', null),
                $this->module('plugins/Payment', requires: ['plugins/Billing' => '*']),
            ]),
        );
    }

    // ---- cycles --------------------------------------------------------------

    public function test_a_circle_is_refused_and_the_circle_is_named(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('plugins/A -> plugins/B -> plugins/C -> plugins/A');

        $this->resolve([
            $this->module('plugins/A', requires: ['plugins/B' => '*']),
            $this->module('plugins/B', requires: ['plugins/C' => '*']),
            $this->module('plugins/C', requires: ['plugins/A' => '*']),
        ]);
    }

    /** Optional dependencies order registration, so they can close a circle too. */
    public function test_an_optional_dependency_can_close_a_circle(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('in a circle');

        $this->resolve([
            $this->module('plugins/A', requires: ['plugins/B' => '*']),
            $this->module('plugins/B', optionally: ['plugins/A' => '*']),
        ]);
    }

    public function test_a_module_cannot_require_itself(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('plugins/A -> plugins/A');

        $this->resolve([$this->module('plugins/A', requires: ['plugins/A' => '*'])]);
    }

    /** A circle among some modules does not hide behind the ones that resolve. */
    public function test_a_circle_is_found_among_modules_that_are_fine(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('plugins/C -> plugins/D -> plugins/C');

        $this->resolve([
            $this->module('shared'),
            $this->module('plugins/A'),
            $this->module('plugins/B', requires: ['plugins/A' => '*']),
            $this->module('plugins/C', requires: ['plugins/D' => '*', 'plugins/A' => '*']),
            $this->module('plugins/D', requires: ['plugins/C' => '*']),
        ]);
    }

    // ---- against kind ----------------------------------------------------------

    public function test_a_plugin_cannot_depend_on_a_gateway(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('which is a kind that loads after it');

        $this->resolve([
            $this->module('plugins/Payment', requires: ['gateways/Stripe' => '*']),
            $this->module('gateways/Stripe'),
        ]);
    }

    /** Even optionally, and even when the gateway is not installed today. */
    public function test_the_kind_rule_holds_for_optional_and_absent_modules(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('loads after it');

        $this->resolve([$this->module('plugins/Payment', optionally: ['gateways/Stripe' => '*'])]);
    }

    /** Shared is what everything else may rely on; it relies on nothing. */
    public function test_shared_cannot_depend_on_anything(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('Module "shared" (shared) depends on "plugins/Billing"');

        $this->resolve([
            $this->module('shared', requires: ['plugins/Billing' => '*']),
            $this->module('plugins/Billing'),
        ]);
    }

    public function test_a_gateway_may_depend_on_a_plugin_and_on_another_gateway(): void
    {
        self::assertSame(
            ['plugins/Payment', 'gateways/Base', 'gateways/Stripe'],
            $this->resolve([
                $this->module('plugins/Payment'),
                $this->module('gateways/Base', requires: ['plugins/Payment' => '*']),
                $this->module('gateways/Stripe', requires: ['gateways/Base' => '*', 'plugins/Payment' => '*']),
            ]),
        );
    }
}
