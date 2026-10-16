<?php

namespace Nikolag\Square\Tests\Unit;

use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Nikolag\Square\Builders\OrderBuilder;
use Nikolag\Square\Builders\SquareRequestBuilder;
use Nikolag\Square\Models\Discount;
use Nikolag\Square\Models\Modifier;
use Nikolag\Square\Models\ModifierOption;
use Nikolag\Square\Models\OrderProductModifierPivot;
use Nikolag\Square\Models\OrderProductPivot;
use Nikolag\Square\Models\OrderReturnLineItem;
use Nikolag\Square\Models\Product;
use Nikolag\Square\Models\ServiceCharge;
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
        Relation::morphMap(['tax' => Tax::class]);
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
        Relation::morphMap(['tax' => Tax::class, 'discount' => Discount::class]);
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
        Relation::morphMap(['vendor_tax' => Tax::class, 'tax' => Tax::class]);
        MorphAliases::register([Tax::class => 'tax']);
        MorphAliases::flush();

        $this->assertNull(MorphAliases::for(Tax::class));
        $this->assertSame('vendor_tax', (new Tax())->getMorphClass());
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
        Relation::morphMap(['tax' => Tax::class]);
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
        Relation::morphMap(['tax' => Tax::class]);
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

    /**
     * Alias every vendor model the deductible and modifier paths touch, behind an enforced map.
     *
     * @return void
     */
    private function aliasVendorModels(): void
    {
        $aliases = [
            Tax::class                => 'tax',
            Discount::class           => 'discount',
            ServiceCharge::class      => 'service_charge',
            OrderProductPivot::class  => 'order_product',
            OrderReturnLineItem::class => 'order_return_line_item',
            Modifier::class           => 'modifier',
            ModifierOption::class     => 'modifier_option',
        ];

        Relation::morphMap(array_flip($aliases) + ['order' => Order::class]);
        Relation::requireMorphMap();
        MorphAliases::register($aliases);
    }

    /**
     * An alias missing from the morph map is rejected at registration.
     *
     * @return void
     */
    public function test_register_rejects_an_alias_missing_from_the_morph_map(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MorphAliases::register([Modifier::class => 'modifer']);
    }

    /**
     * An alias mapped to an unrelated class is rejected at registration.
     *
     * @return void
     */
    public function test_register_rejects_an_alias_mapped_to_an_unrelated_class(): void
    {
        Relation::morphMap(['tax' => Discount::class]);

        $this->expectException(InvalidArgumentException::class);

        MorphAliases::register([Tax::class => 'tax']);
    }

    /**
     * The static resolver matches the instance resolution in every state.
     *
     * @return void
     */
    public function test_morph_type_of_matches_get_morph_class(): void
    {
        $this->assertSame(Tax::class, MorphAliases::morphTypeOf(Tax::class));

        $this->aliasVendorModels();

        $this->assertSame('tax', MorphAliases::morphTypeOf(Tax::class));
        $this->assertSame('tax', MorphAliases::morphTypeOf('\\'.Tax::class));
        $this->assertSame('order', MorphAliases::morphTypeOf(Order::class));
    }

    /**
     * Every rewritten deductible filter on a line-item pivot reads the aliased rows.
     *
     * @return void
     */
    public function test_line_item_pivot_deductible_filters_read_aliased_rows(): void
    {
        $this->aliasVendorModels();
        $pivot = factory(OrderProductPivot::class)->create();
        $tax = factory(Tax::class)->create();
        $discount = factory(Discount::class)->create();
        $serviceCharge = factory(ServiceCharge::class)->states('PERCENTAGE_ONLY')->create();

        $pivot->taxes()->attach($tax->id, ['deductible_type' => 'tax', 'scope' => Constants::DEDUCTIBLE_SCOPE_PRODUCT]);
        $pivot->discounts()->attach($discount->id, ['deductible_type' => 'discount', 'scope' => Constants::DEDUCTIBLE_SCOPE_PRODUCT]);
        $pivot->serviceCharges()->attach($serviceCharge->id, ['deductible_type' => 'service_charge', 'scope' => Constants::DEDUCTIBLE_SCOPE_PRODUCT]);

        $this->assertDatabaseHas('nikolag_deductibles', ['featurable_type' => 'order_product', 'deductible_type' => 'tax']);
        $this->assertSame([$tax->id], $pivot->taxes()->pluck('nikolag_taxes.id')->all());
        $this->assertSame([$discount->id], $pivot->discounts()->pluck('nikolag_discounts.id')->all());
        $this->assertSame([$serviceCharge->id], $pivot->serviceCharges()->pluck('nikolag_service_charges.id')->all());
    }

    /**
     * The service charge and return line item tax filters read the aliased rows.
     *
     * @return void
     */
    public function test_service_charge_and_return_line_item_filters_read_aliased_rows(): void
    {
        $this->aliasVendorModels();
        $serviceCharge = factory(ServiceCharge::class)->states('PERCENTAGE_ONLY')->create();
        $lineItem = factory(OrderReturnLineItem::class)->create();
        $tax = factory(Tax::class)->create();
        $discount = factory(Discount::class)->create();

        $serviceCharge->taxes()->attach($tax->id, ['deductible_type' => 'tax', 'scope' => Constants::DEDUCTIBLE_SCOPE_PRODUCT]);
        $lineItem->taxes()->attach($tax->id, ['deductible_type' => 'tax', 'scope' => Constants::DEDUCTIBLE_SCOPE_PRODUCT]);
        $lineItem->discounts()->attach($discount->id, ['deductible_type' => 'discount', 'scope' => Constants::DEDUCTIBLE_SCOPE_PRODUCT]);
        $lineItem->serviceCharges()->attach($serviceCharge->id, ['deductible_type' => 'service_charge', 'scope' => Constants::DEDUCTIBLE_SCOPE_PRODUCT]);

        $this->assertSame([$tax->id], $serviceCharge->taxes()->pluck('nikolag_taxes.id')->all());
        $this->assertSame([$tax->id], $lineItem->taxes()->pluck('nikolag_taxes.id')->all());
        $this->assertSame([$discount->id], $lineItem->discounts()->pluck('nikolag_discounts.id')->all());
        $this->assertSame([$serviceCharge->id], $lineItem->serviceCharges()->pluck('nikolag_service_charges.id')->all());
    }

    /**
     * Inverse deductible relations keep order-level and line-item-level rows apart.
     *
     * @return void
     */
    public function test_inverse_deductible_relations_filter_on_featurable_type(): void
    {
        $order = factory(Order::class)->create();
        $pivot = factory(OrderProductPivot::class)->create();
        $tax = factory(Tax::class)->create();

        $pivot->taxes()->attach($tax->id, ['deductible_type' => Tax::class, 'scope' => Constants::DEDUCTIBLE_SCOPE_PRODUCT]);

        // Same featurable_id on both sides, so only featurable_type can tell them apart.
        $this->assertSame($order->id, $pivot->id);
        $this->assertSame([], $tax->orders()->pluck('id')->all());
        $this->assertSame([$pivot->id], $tax->products()->pluck('nikolag_product_order.id')->all());
    }

    /**
     * The order builder writes the aliases its own reads filter on.
     *
     * @return void
     */
    public function test_order_builder_writes_aliased_deductibles(): void
    {
        $this->aliasVendorModels();
        $builder = new OrderBuilder();
        $order = factory(Order::class)->create();
        $orderCopy = $builder->buildOrderCopyFromArray([
            'taxes'    => [['name' => 'Order tax', 'type' => Constants::TAX_ADDITIVE, 'percentage' => 5]],
            'products' => [[
                'name'      => 'Bread',
                'price'     => 10_00,
                'quantity'  => 1,
                'discounts' => [['name' => 'Line discount', 'percentage' => 10]],
            ]],
        ]);
        $orderCopy->location_id = $order->location_id ?: 'test-location';

        $builder->buildOrderFromOrderCopy($order, $orderCopy);

        $pivot = OrderProductPivot::where('order_id', $order->id)->firstOrFail();
        $this->assertDatabaseHas('nikolag_deductibles', ['featurable_type' => 'order', 'deductible_type' => 'tax']);
        $this->assertDatabaseHas('nikolag_deductibles', ['featurable_type' => 'order_product', 'deductible_type' => 'discount']);
        $this->assertCount(1, $order->taxes()->get());
        $this->assertCount(1, $order->discounts()->get());
        $this->assertCount(1, $pivot->discounts()->get());
    }

    /**
     * The modifier factory and the line-item note both use the modifier alias.
     *
     * @return void
     */
    public function test_text_modifier_note_matches_the_aliased_modifier_type(): void
    {
        $this->aliasVendorModels();
        $product = factory(Product::class)->create(['note' => 'Base']);
        $pivot = factory(OrderProductPivot::class)->create(['product_id' => $product->id]);
        $modifier = factory(Modifier::class)->create(['type' => 'TEXT']);
        $productModifier = factory(OrderProductModifierPivot::class)->create([
            'order_product_id' => $pivot->id,
            'modifiable_id'    => $modifier->id,
            'modifiable_type'  => $modifier->getMorphClass(),
            'text'             => 'No crust',
        ]);

        $defaultModifier = factory(OrderProductModifierPivot::class)->create(['order_product_id' => $pivot->id]);

        $this->assertDatabaseHas('nikolag_product_order_modifier', ['modifiable_id' => $modifier->id, 'modifiable_type' => 'modifier']);
        $this->assertSame('modifier_option', $defaultModifier->modifiable_type);
        $this->assertSame('Base No crust', (new SquareRequestBuilder())->buildNote($product, collect([$productModifier, $defaultModifier])));
    }
}
