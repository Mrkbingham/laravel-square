<?php

namespace Nikolag\Square\Tests\Unit;

use Nikolag\Square\Models\Discount;
use Nikolag\Square\Models\OrderProductPivot;
use Nikolag\Square\Models\Product;
use Nikolag\Square\Models\ServiceCharge;
use Nikolag\Square\Models\Tax;
use Nikolag\Square\Tests\Models\Order;
use Nikolag\Square\Tests\TestCase;
use Nikolag\Square\Utils\Constants;

class OrderProductPivotTest extends TestCase
{
    /**
     * Test OrderProductPivot creation with basic fields.
     *
     * @return void
     */
    public function test_order_product_pivot_creation(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create([
            'price' => 15_00,
        ]);

        $pivot = factory(OrderProductPivot::class)->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price_money_amount' => 15_00,
            'price_money_currency' => 'USD',
        ]);

        $this->assertNotNull($pivot);
        $this->assertEquals($order->id, $pivot->order_id);
        $this->assertEquals($product->id, $pivot->product_id);
        $this->assertEquals(2, $pivot->quantity);
        $this->assertEquals(15_00, $pivot->price_money_amount);
        $this->assertEquals('USD', $pivot->price_money_currency);
    }

    /**
     * Test OrderProductPivot creation with all Square line item fields.
     *
     * @return void
     */
    public function test_order_product_pivot_with_all_fields(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create();

        $pivot = factory(OrderProductPivot::class)->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'square_uid' => 'test-square-uid',
            'name' => 'Test Product Name',
            'variation_name' => 'Large Size',
            'catalog_object_id' => 'test-catalog-object-id',
            'catalog_version' => 5,
            'item_type' => 'ITEM',
            'note' => 'Special instructions for this line item',
            'price_money_amount' => 20_00,
            'price_money_currency' => 'USD',
            'variation_total_price_money_amount' => 60_00,
            'variation_total_price_money_currency' => 'USD',
            'gross_sales_money_amount' => 65_00,
            'gross_sales_money_currency' => 'USD',
            'total_tax_money_amount' => 5_00,
            'total_tax_money_currency' => 'USD',
            'total_discount_money_amount' => 3_00,
            'total_discount_money_currency' => 'USD',
            'total_money_amount' => 67_00,
            'total_money_currency' => 'USD',
        ]);

        $this->assertNotNull($pivot);
        $this->assertEquals(3, $pivot->quantity);
        $this->assertEquals('test-square-uid', $pivot->square_uid);
        $this->assertEquals('Test Product Name', $pivot->name);
        $this->assertEquals('Large Size', $pivot->variation_name);
        $this->assertEquals('test-catalog-object-id', $pivot->catalog_object_id);
        $this->assertEquals(5, $pivot->catalog_version);
        $this->assertEquals('ITEM', $pivot->item_type);
        $this->assertEquals('Special instructions for this line item', $pivot->note);
        $this->assertEquals(20_00, $pivot->price_money_amount);
        $this->assertEquals(60_00, $pivot->variation_total_price_money_amount);
        $this->assertEquals(65_00, $pivot->gross_sales_money_amount);
        $this->assertEquals(5_00, $pivot->total_tax_money_amount);
        $this->assertEquals(3_00, $pivot->total_discount_money_amount);
        $this->assertEquals(67_00, $pivot->total_money_amount);
    }

    /**
     * Test OrderProductPivot relationship with Order.
     *
     * @return void
     */
    public function test_order_product_pivot_relationship_with_order(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create();

        $pivot = factory(OrderProductPivot::class)->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
        ]);

        $this->assertInstanceOf(Order::class, $pivot->order);
        $this->assertEquals($order->id, $pivot->order->id);
    }

    /**
     * Test OrderProductPivot relationship with Product.
     *
     * @return void
     */
    public function test_order_product_pivot_relationship_with_product(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create();

        $pivot = factory(OrderProductPivot::class)->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
        ]);

        $this->assertInstanceOf(Product::class, $pivot->product);
        $this->assertEquals($product->id, $pivot->product->id);
    }

    /**
     * Test OrderProductPivot with taxes.
     *
     * @return void
     */
    public function test_order_product_pivot_with_taxes(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create();

        $pivot = factory(OrderProductPivot::class)->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
        ]);

        $tax = factory(Tax::class)->create(['percentage' => 8.5]);

        $pivot->taxes()->attach($tax->id, [
            'featurable_type' => Constants::ORDER_PRODUCT_NAMESPACE,
            'deductible_type' => Constants::TAX_NAMESPACE,
            'scope' => Constants::DEDUCTIBLE_SCOPE_PRODUCT,
        ]);

        $this->assertNotNull($pivot->taxes);
        $this->assertCount(1, $pivot->taxes);
        $this->assertTrue($pivot->hasTax($tax));
    }

    /**
     * Test OrderProductPivot with discounts.
     *
     * @return void
     */
    public function test_order_product_pivot_with_discounts(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create();

        $pivot = factory(OrderProductPivot::class)->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
        ]);

        $discount = factory(Discount::class)->states('PERCENTAGE_ONLY')->create(['percentage' => 10.0]);

        $pivot->discounts()->attach($discount->id, [
            'featurable_type' => Constants::ORDER_PRODUCT_NAMESPACE,
            'deductible_type' => Constants::DISCOUNT_NAMESPACE,
            'scope' => Constants::DEDUCTIBLE_SCOPE_PRODUCT,
        ]);

        $this->assertNotNull($pivot->discounts);
        $this->assertCount(1, $pivot->discounts);
        $this->assertTrue($pivot->hasDiscount($discount));
    }

    /**
     * Test OrderProductPivot with service charges.
     *
     * @return void
     */
    public function test_order_product_pivot_with_service_charges(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create();

        $pivot = factory(OrderProductPivot::class)->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
        ]);

        $serviceCharge = factory(ServiceCharge::class)->states('AMOUNT_ONLY')->create(['amount_money' => 2_00]);

        $pivot->serviceCharges()->attach($serviceCharge->id, [
            'featurable_type' => Constants::ORDER_PRODUCT_NAMESPACE,
            'deductible_type' => Constants::SERVICE_CHARGE_NAMESPACE,
            'scope' => Constants::DEDUCTIBLE_SCOPE_PRODUCT,
        ]);

        $this->assertNotNull($pivot->serviceCharges);
        $this->assertCount(1, $pivot->serviceCharges);
        $this->assertTrue($pivot->hasServiceCharge($serviceCharge));
    }

    /**
     * Test OrderProductPivot relationship with product.
     *
     * @return void
     */
    public function test_order_product_pivot_has_product(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create();

        $pivot = factory(OrderProductPivot::class)->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
        ]);

        // Test that the pivot is correctly associated with the product
        $this->assertEquals($product->id, $pivot->product_id);
        $this->assertInstanceOf(Product::class, $pivot->product);
        $this->assertEquals($product->id, $pivot->product->id);

        // The hasProduct method uses strict instance comparison
        $this->assertTrue($pivot->hasProduct($pivot->product));
    }

    /**
     * Test catalog fields persistence.
     *
     * @return void
     */
    public function test_catalog_fields_persistence(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create();

        $catalogObjectId = 'CATALOG_OBJECT_' . uniqid();
        $catalogVersion = rand(1, 100);

        $pivot = factory(OrderProductPivot::class)->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'catalog_object_id' => $catalogObjectId,
            'catalog_version' => $catalogVersion,
        ]);

        $this->assertEquals($catalogObjectId, $pivot->catalog_object_id);
        $this->assertEquals($catalogVersion, $pivot->catalog_version);

        $this->assertDatabaseHas('nikolag_product_order', [
            'id' => $pivot->id,
            'catalog_object_id' => $catalogObjectId,
            'catalog_version' => $catalogVersion,
        ]);
    }

    /**
     * Test money fields persistence and type casting.
     *
     * @return void
     */
    public function test_money_fields_persistence(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create();

        $pivot = factory(OrderProductPivot::class)->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'price_money_amount' => 10_00,
            'variation_total_price_money_amount' => 20_00,
            'gross_sales_money_amount' => 22_00,
            'total_tax_money_amount' => 2_00,
            'total_discount_money_amount' => 1_00,
            'total_money_amount' => 23_00,
        ]);

        // Test that values are stored correctly
        $this->assertEquals(10_00, $pivot->price_money_amount);
        $this->assertEquals(20_00, $pivot->variation_total_price_money_amount);
        $this->assertEquals(22_00, $pivot->gross_sales_money_amount);
        $this->assertEquals(2_00, $pivot->total_tax_money_amount);
        $this->assertEquals(1_00, $pivot->total_discount_money_amount);
        $this->assertEquals(23_00, $pivot->total_money_amount);

        // Test that values are cast to integers
        $this->assertIsInt($pivot->price_money_amount);
        $this->assertIsInt($pivot->variation_total_price_money_amount);
        $this->assertIsInt($pivot->gross_sales_money_amount);
        $this->assertIsInt($pivot->total_tax_money_amount);
        $this->assertIsInt($pivot->total_discount_money_amount);
        $this->assertIsInt($pivot->total_money_amount);
    }

    /**
     * Test note field persistence.
     *
     * @return void
     */
    public function test_note_field_persistence(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create();

        $note = 'This is a special line item note with instructions';

        $pivot = factory(OrderProductPivot::class)->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'note' => $note,
        ]);

        $this->assertEquals($note, $pivot->note);

        $this->assertDatabaseHas('nikolag_product_order', [
            'id' => $pivot->id,
            'note' => $note,
        ]);
    }

    /**
     * Test item_type field persistence.
     *
     * @return void
     */
    public function test_item_type_field(): void
    {
        $itemTypes = ['ITEM', 'CUSTOM_AMOUNT', 'GIFT_CARD'];

        foreach ($itemTypes as $itemType) {
            // Create new order and product for each iteration to avoid unique constraint
            $order = factory(Order::class)->create();
            $product = factory(Product::class)->create();

            $pivot = factory(OrderProductPivot::class)->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'item_type' => $itemType,
            ]);

            $this->assertEquals($itemType, $pivot->item_type);

            $this->assertDatabaseHas('nikolag_product_order', [
                'id' => $pivot->id,
                'item_type' => $itemType,
            ]);
        }
    }

    /**
     * Test square_uid uniqueness and persistence.
     *
     * @return void
     */
    public function test_square_uid_persistence(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create();

        $squareUid = 'SQUARE_UID_' . uniqid();

        $pivot = factory(OrderProductPivot::class)->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'square_uid' => $squareUid,
        ]);

        $this->assertEquals($squareUid, $pivot->square_uid);

        $this->assertDatabaseHas('nikolag_product_order', [
            'id' => $pivot->id,
            'square_uid' => $squareUid,
        ]);
    }

    /**
     * Test timestamps are automatically set.
     *
     * @return void
     */
    public function test_timestamps_are_set(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create();

        $pivot = factory(OrderProductPivot::class)->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
        ]);

        $this->assertNotNull($pivot->created_at);
        $this->assertNotNull($pivot->updated_at);
        $this->assertInstanceOf(\DateTime::class, $pivot->created_at);
        $this->assertInstanceOf(\DateTime::class, $pivot->updated_at);
    }

    /**
     * Test variation_name field persistence.
     *
     * @return void
     */
    public function test_variation_name_persistence(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create();

        $variationName = 'Large - Red';

        $pivot = factory(OrderProductPivot::class)->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'variation_name' => $variationName,
        ]);

        $this->assertEquals($variationName, $pivot->variation_name);

        $this->assertDatabaseHas('nikolag_product_order', [
            'id' => $pivot->id,
            'variation_name' => $variationName,
        ]);
    }

    /**
     * Test that factory creates valid data.
     *
     * @return void
     */
    public function test_factory_creates_valid_data(): void
    {
        $pivot = factory(OrderProductPivot::class)->create();

        $this->assertNotNull($pivot);
        $this->assertNotNull($pivot->order_id);
        $this->assertNotNull($pivot->product_id);
        $this->assertNotNull($pivot->quantity);
        $this->assertGreaterThan(0, $pivot->quantity);

        // Test that money amounts are non-negative
        $this->assertGreaterThanOrEqual(0, $pivot->price_money_amount);
        $this->assertGreaterThanOrEqual(0, $pivot->variation_total_price_money_amount);
        $this->assertGreaterThanOrEqual(0, $pivot->gross_sales_money_amount);
    }

    /**
     * Test database has all expected columns.
     *
     * @return void
     */
    public function test_database_has_expected_columns(): void
    {
        $pivot = factory(OrderProductPivot::class)->create([
            'square_uid' => 'test-uid',
            'name' => 'Test Name',
            'variation_name' => 'Test Variation',
            'catalog_object_id' => 'test-catalog-id',
            'catalog_version' => 1,
            'item_type' => 'ITEM',
            'note' => 'Test note',
        ]);

        $this->assertDatabaseHas('nikolag_product_order', [
            'id' => $pivot->id,
            'square_uid' => 'test-uid',
            'name' => 'Test Name',
            'variation_name' => 'Test Variation',
            'catalog_object_id' => 'test-catalog-id',
            'catalog_version' => 1,
            'item_type' => 'ITEM',
            'note' => 'Test note',
        ]);
    }
}
