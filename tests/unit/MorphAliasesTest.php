<?php

namespace Nikolag\Square\Tests\Unit;

use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nikolag\Square\Models\Discount;
use Nikolag\Square\Models\Tax;
use Nikolag\Square\Tests\Models\Order;
use Nikolag\Square\Tests\TestCase;
use Nikolag\Square\Utils\Constants;
use Nikolag\Square\Utils\MorphAliases;

class MorphAliasesTest extends TestCase
{
    /**
     * Reset every piece of global morph state between tests.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        MorphAliases::flush();
        Relation::morphMap([], false);
        Relation::requireMorphMap(false);

        parent::tearDown();
    }

    /**
     * An unregistered vendor model reports its class name, as before.
     *
     * @return void
     */
    public function test_unregistered_model_reports_its_class_name(): void
    {
        $this->assertSame(Tax::class, (new Tax())->getMorphClass());
    }

    /**
     * A registered vendor model reports the host app's alias.
     *
     * @return void
     */
    public function test_registered_model_reports_its_alias(): void
    {
        MorphAliases::register([Tax::class => 'tax']);

        $this->assertSame('tax', (new Tax())->getMorphClass());
        $this->assertSame(Discount::class, (new Discount())->getMorphClass());
    }

    /**
     * Registration merges rather than replaces.
     *
     * @return void
     */
    public function test_register_merges_with_earlier_registrations(): void
    {
        MorphAliases::register([Tax::class => 'tax']);
        MorphAliases::register([Discount::class => 'discount']);

        $this->assertSame('tax', MorphAliases::for(Tax::class));
        $this->assertSame('discount', MorphAliases::for(Discount::class));
    }

    /**
     * Flushing forgets every alias.
     *
     * @return void
     */
    public function test_flush_forgets_every_alias(): void
    {
        MorphAliases::register([Tax::class => 'tax']);
        MorphAliases::flush();

        $this->assertNull(MorphAliases::for(Tax::class));
        $this->assertSame(Tax::class, (new Tax())->getMorphClass());
    }

    /**
     * Without a registration, the host morph map still resolves the class.
     *
     * @return void
     */
    public function test_falls_back_to_the_morph_map(): void
    {
        Relation::morphMap(['vendor_tax' => Tax::class]);

        $this->assertSame('vendor_tax', (new Tax())->getMorphClass());
    }

    /**
     * An enforced morph map with no entry for the class still throws.
     *
     * @return void
     */
    public function test_enforced_morph_map_without_registration_throws(): void
    {
        Relation::requireMorphMap();

        $this->expectException(ClassMorphViolationException::class);

        (new Tax())->getMorphClass();
    }

    /**
     * A registration satisfies an enforced morph map.
     *
     * @return void
     */
    public function test_registration_satisfies_an_enforced_morph_map(): void
    {
        Relation::requireMorphMap();
        MorphAliases::register([Tax::class => 'tax']);

        $this->assertSame('tax', (new Tax())->getMorphClass());
    }

    /**
     * Relation filters compare against the registered alias.
     *
     * @return void
     */
    public function test_deductible_relation_filters_on_the_registered_alias(): void
    {
        MorphAliases::register([Tax::class => 'tax']);
        $order = factory(Order::class)->create();
        $tax = factory(Tax::class)->create();

        $order->taxes()->attach($tax->id, [
            'featurable_type' => $order->getMorphClass(),
            'deductible_type' => $tax->getMorphClass(),
            'scope'           => Constants::DEDUCTIBLE_SCOPE_ORDER,
        ]);

        $this->assertDatabaseHas('nikolag_deductibles', [
            'featurable_id'   => $order->id,
            'deductible_id'   => $tax->id,
            'deductible_type' => 'tax',
        ]);
        $this->assertSame([$tax->id], $order->taxes()->pluck('nikolag_taxes.id')->all());
    }
}
