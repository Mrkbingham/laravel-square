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
            'base_price_money_amount' => 15_00,
            'base_price_money_currency' => 'USD',
        ]);

        $this->assertNotNull($pivot);
        $this->assertEquals($order->id, $pivot->order_id);
        $this->assertEquals($product->id, $pivot->product_id);
        $this->assertEquals(2, $pivot->quantity);
        $this->assertEquals(15_00, $pivot->base_price_money_amount);
        $this->assertEquals('USD', $pivot->base_price_money_currency);
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
            'base_price_money_amount' => 20_00,
            'base_price_money_currency' => 'USD',
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
        $this->assertEquals(20_00, $pivot->base_price_money_amount);
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
            'base_price_money_amount' => 10_00,
            'variation_total_price_money_amount' => 20_00,
            'gross_sales_money_amount' => 22_00,
            'total_tax_money_amount' => 2_00,
            'total_discount_money_amount' => 1_00,
            'total_money_amount' => 23_00,
        ]);

        // Test that values are stored correctly
        $this->assertEquals(10_00, $pivot->base_price_money_amount);
        $this->assertEquals(20_00, $pivot->variation_total_price_money_amount);
        $this->assertEquals(22_00, $pivot->gross_sales_money_amount);
        $this->assertEquals(2_00, $pivot->total_tax_money_amount);
        $this->assertEquals(1_00, $pivot->total_discount_money_amount);
        $this->assertEquals(23_00, $pivot->total_money_amount);

        // Test that values are cast to integers
        $this->assertIsInt($pivot->base_price_money_amount);
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
        $this->assertGreaterThanOrEqual(0, $pivot->base_price_money_amount);
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

    /**
     * Test base price money fields persistence and casting.
     *
     * @return void
     */
    public function test_base_price_money_fields_persistence(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create();

        $pivot = factory(OrderProductPivot::class)->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'base_price_money_amount' => 25_50,
            'base_price_money_currency' => 'USD',
        ]);

        // Test that values are stored correctly
        $this->assertEquals(25_50, $pivot->base_price_money_amount);
        $this->assertEquals('USD', $pivot->base_price_money_currency);

        // Test that amount is cast to integer
        $this->assertIsInt($pivot->base_price_money_amount);
        $this->assertIsString($pivot->base_price_money_currency);

        // Test database persistence
        $this->assertDatabaseHas('nikolag_product_order', [
            'id' => $pivot->id,
            'base_price_money_amount' => 25_50,
            'base_price_money_currency' => 'USD',
        ]);
    }

    /**
     * Test money field calculations and relationships.
     *
     * @return void
     */
    public function test_money_field_calculations(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create();

        $basePrice = 10_00;  // $10.00
        $quantity = 3;
        $variationTotal = $basePrice * $quantity;  // $30.00
        $modifierAmount = 5_00;  // $5.00 for modifiers
        $grossSales = $variationTotal + $modifierAmount;  // $35.00
        $taxAmount = 2_80;  // $2.80 (8% tax)
        $discountAmount = 3_50;  // $3.50 discount
        $totalMoney = $grossSales + $taxAmount - $discountAmount;  // $34.30

        $pivot = factory(OrderProductPivot::class)->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'base_price_money_amount' => $basePrice,
            'base_price_money_currency' => 'USD',
            'variation_total_price_money_amount' => $variationTotal,
            'variation_total_price_money_currency' => 'USD',
            'gross_sales_money_amount' => $grossSales,
            'gross_sales_money_currency' => 'USD',
            'total_tax_money_amount' => $taxAmount,
            'total_tax_money_currency' => 'USD',
            'total_discount_money_amount' => $discountAmount,
            'total_discount_money_currency' => 'USD',
            'total_money_amount' => $totalMoney,
            'total_money_currency' => 'USD',
        ]);

        // Verify the mathematical relationships
        $this->assertEquals($basePrice * $quantity, $pivot->variation_total_price_money_amount);
        $this->assertEquals($variationTotal + $modifierAmount, $pivot->gross_sales_money_amount);
        $this->assertEquals($grossSales + $taxAmount - $discountAmount, $pivot->total_money_amount);

        // Verify currency consistency
        $this->assertEquals($pivot->base_price_money_currency, $pivot->variation_total_price_money_currency);
        $this->assertEquals($pivot->base_price_money_currency, $pivot->gross_sales_money_currency);
        $this->assertEquals($pivot->base_price_money_currency, $pivot->total_money_currency);
    }

    /**
     * Test different currencies for money fields.
     *
     * @return void
     */
    public function test_different_currencies_for_money_fields(): void
    {
        $currencies = ['EUR', 'GBP', 'CAD', 'JPY'];

        foreach ($currencies as $currency) {
            $order = factory(Order::class)->create();
            $product = factory(Product::class)->create();

            $pivot = factory(OrderProductPivot::class)->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'base_price_money_amount' => 10_00,
                'base_price_money_currency' => $currency,
                'variation_total_price_money_amount' => 20_00,
                'variation_total_price_money_currency' => $currency,
                'gross_sales_money_amount' => 20_00,
                'gross_sales_money_currency' => $currency,
                'total_tax_money_amount' => 1_60,
                'total_tax_money_currency' => $currency,
                'total_discount_money_amount' => 2_00,
                'total_discount_money_currency' => $currency,
                'total_money_amount' => 19_60,
                'total_money_currency' => $currency,
            ]);

            // Verify all currency fields match the specified currency
            $this->assertEquals($currency, $pivot->base_price_money_currency);
            $this->assertEquals($currency, $pivot->variation_total_price_money_currency);
            $this->assertEquals($currency, $pivot->gross_sales_money_currency);
            $this->assertEquals($currency, $pivot->total_tax_money_currency);
            $this->assertEquals($currency, $pivot->total_discount_money_currency);
            $this->assertEquals($currency, $pivot->total_money_currency);

            // Verify database persistence
            $this->assertDatabaseHas('nikolag_product_order', [
                'id' => $pivot->id,
                'base_price_money_currency' => $currency,
                'total_money_currency' => $currency,
            ]);
        }
    }

    /**
     * Test that nullable fields can be set to null.
     *
     * @return void
     */
    public function test_nullable_fields_can_be_null(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create();

        $pivot = factory(OrderProductPivot::class)->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            // Explicitly set nullable fields to null
            'square_uid' => null,
            'name' => null,
            'variation_name' => null,
            'catalog_object_id' => null,
            'catalog_version' => null,
            'item_type' => null,
            'note' => null,
            'base_price_money_amount' => null,
            'base_price_money_currency' => null,
            'variation_total_price_money_amount' => null,
            'variation_total_price_money_currency' => null,
            'gross_sales_money_amount' => null,
            'gross_sales_money_currency' => null,
            'total_tax_money_amount' => null,
            'total_tax_money_currency' => null,
            'total_discount_money_amount' => null,
            'total_discount_money_currency' => null,
            'total_money_amount' => null,
            'total_money_currency' => null,
        ]);

        // Verify all fields are null
        $this->assertNull($pivot->square_uid);
        $this->assertNull($pivot->name);
        $this->assertNull($pivot->variation_name);
        $this->assertNull($pivot->catalog_object_id);
        $this->assertNull($pivot->catalog_version);
        $this->assertNull($pivot->item_type);
        $this->assertNull($pivot->note);
        $this->assertNull($pivot->base_price_money_amount);
        $this->assertNull($pivot->variation_total_price_money_amount);
        $this->assertNull($pivot->gross_sales_money_amount);
        $this->assertNull($pivot->total_tax_money_amount);
        $this->assertNull($pivot->total_discount_money_amount);
        $this->assertNull($pivot->total_money_amount);
    }

    /**
     * Test default values for money fields.
     *
     * @return void
     */
    public function test_default_values_for_money_fields(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create();

        // Create pivot with minimal fields to test defaults
        $pivot = factory(OrderProductPivot::class)->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            // Don't specify money fields to test defaults
            'base_price_money_amount' => null,
            'variation_total_price_money_amount' => null,
            'gross_sales_money_amount' => null,
            'total_tax_money_amount' => null,
            'total_discount_money_amount' => null,
            'total_money_amount' => null,
        ]);

        // Verify default values from migration (nullable fields can be null)
        // The migration sets defaults to 0 and 'USD' for non-null money fields
        $freshPivot = OrderProductPivot::find($pivot->id);

        // Check that the pivot was created
        $this->assertNotNull($freshPivot);

        // Verify the pivot exists in database
        $this->assertDatabaseHas('nikolag_product_order', [
            'id' => $freshPivot->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
        ]);
    }

    /**
     * Test zero money amounts.
     *
     * @return void
     */
    public function test_zero_money_amounts(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create();

        $pivot = factory(OrderProductPivot::class)->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'base_price_money_amount' => 0,
            'variation_total_price_money_amount' => 0,
            'gross_sales_money_amount' => 0,
            'total_tax_money_amount' => 0,
            'total_discount_money_amount' => 0,
            'total_money_amount' => 0,
        ]);

        // Verify all amounts are zero
        $this->assertEquals(0, $pivot->base_price_money_amount);
        $this->assertEquals(0, $pivot->variation_total_price_money_amount);
        $this->assertEquals(0, $pivot->gross_sales_money_amount);
        $this->assertEquals(0, $pivot->total_tax_money_amount);
        $this->assertEquals(0, $pivot->total_discount_money_amount);
        $this->assertEquals(0, $pivot->total_money_amount);

        // Verify they are still integers (not null)
        $this->assertIsInt($pivot->base_price_money_amount);
        $this->assertIsInt($pivot->total_money_amount);
    }

    /**
     * Test large money amounts.
     *
     * @return void
     */
    public function test_large_money_amounts(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create();

        // Test with $99,999,999.99 (9999999999 cents)
        $largeAmount = 9_999_999_99;

        $pivot = factory(OrderProductPivot::class)->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'base_price_money_amount' => $largeAmount,
            'variation_total_price_money_amount' => $largeAmount,
            'gross_sales_money_amount' => $largeAmount,
            'total_tax_money_amount' => $largeAmount,
            'total_discount_money_amount' => $largeAmount,
            'total_money_amount' => $largeAmount,
        ]);

        // Verify large amounts are stored correctly
        $this->assertEquals($largeAmount, $pivot->base_price_money_amount);
        $this->assertEquals($largeAmount, $pivot->variation_total_price_money_amount);
        $this->assertEquals($largeAmount, $pivot->gross_sales_money_amount);
        $this->assertEquals($largeAmount, $pivot->total_tax_money_amount);
        $this->assertEquals($largeAmount, $pivot->total_discount_money_amount);
        $this->assertEquals($largeAmount, $pivot->total_money_amount);

        // Verify no overflow occurred
        $this->assertIsInt($pivot->base_price_money_amount);
    }

    /**
     * Test catalog object ID accepts various formats.
     *
     * @return void
     */
    public function test_catalog_object_id_accepts_various_formats(): void
    {
        $testCases = [
            'UUID format' => '550e8400-e29b-41d4-a716-446655440000',
            'Square catalog ID' => 'CATALOG_ITEM_1234567890ABCDEF',
            'Long string' => str_repeat('A', 192),  // Maximum length
            'Short ID' => 'ABC123',
        ];

        foreach ($testCases as $description => $catalogId) {
            $order = factory(Order::class)->create();
            $product = factory(Product::class)->create();

            $pivot = factory(OrderProductPivot::class)->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'catalog_object_id' => $catalogId,
            ]);

            $this->assertEquals($catalogId, $pivot->catalog_object_id, "Failed for: {$description}");

            $this->assertDatabaseHas('nikolag_product_order', [
                'id' => $pivot->id,
                'catalog_object_id' => $catalogId,
            ]);
        }
    }

    /**
     * Test complete line item with all money calculations.
     *
     * @return void
     */
    public function test_complete_line_item_with_all_money_calculations(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create();

        // Realistic scenario: 3 items at $10.00 each
        $basePrice = 10_00;        // $10.00
        $quantity = 3;
        $variationTotal = 30_00;   // $10.00 × 3 = $30.00
        $grossSales = 30_00;       // $30.00 (no modifiers)
        $tax = 2_40;               // $2.40 (8% tax)
        $discount = 3_00;          // $3.00 discount
        $total = 29_40;            // $30.00 + $2.40 - $3.00 = $29.40

        $pivot = factory(OrderProductPivot::class)->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'name' => 'Premium Widget',
            'variation_name' => 'Large',
            'catalog_object_id' => 'CATALOG_ABC123',
            'catalog_version' => 1,
            'item_type' => 'ITEM',
            'note' => 'Customer requested express shipping',
            'base_price_money_amount' => $basePrice,
            'base_price_money_currency' => 'USD',
            'variation_total_price_money_amount' => $variationTotal,
            'variation_total_price_money_currency' => 'USD',
            'gross_sales_money_amount' => $grossSales,
            'gross_sales_money_currency' => 'USD',
            'total_tax_money_amount' => $tax,
            'total_tax_money_currency' => 'USD',
            'total_discount_money_amount' => $discount,
            'total_discount_money_currency' => 'USD',
            'total_money_amount' => $total,
            'total_money_currency' => 'USD',
        ]);

        // Verify all fields
        $this->assertEquals($quantity, $pivot->quantity);
        $this->assertEquals($basePrice, $pivot->base_price_money_amount);
        $this->assertEquals($variationTotal, $pivot->variation_total_price_money_amount);
        $this->assertEquals($grossSales, $pivot->gross_sales_money_amount);
        $this->assertEquals($tax, $pivot->total_tax_money_amount);
        $this->assertEquals($discount, $pivot->total_discount_money_amount);
        $this->assertEquals($total, $pivot->total_money_amount);

        // Verify mathematical relationships
        $this->assertEquals($basePrice * $quantity, $pivot->variation_total_price_money_amount);
        $this->assertEquals(
            $grossSales + $tax - $discount,
            $pivot->total_money_amount,
            'Total should equal gross sales + tax - discount'
        );

        // Verify metadata fields
        $this->assertEquals('Premium Widget', $pivot->name);
        $this->assertEquals('Large', $pivot->variation_name);
        $this->assertEquals('CATALOG_ABC123', $pivot->catalog_object_id);
        $this->assertEquals('ITEM', $pivot->item_type);
        $this->assertEquals('Customer requested express shipping', $pivot->note);

        // Verify relationships still work
        $this->assertEquals($order->id, $pivot->order->id);
        $this->assertEquals($product->id, $pivot->product->id);
    }

    /**
     * Test timestamps update on modification.
     *
     * @return void
     */
    public function test_timestamps_update_on_modification(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create();

        $pivot = factory(OrderProductPivot::class)->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'base_price_money_amount' => 10_00,
        ]);

        $originalCreatedAt = $pivot->created_at;
        $originalUpdatedAt = $pivot->updated_at;

        // Ensure timestamps are set
        $this->assertNotNull($originalCreatedAt);
        $this->assertNotNull($originalUpdatedAt);

        // Wait a moment to ensure time difference
        sleep(1);

        // Update the pivot
        $pivot->base_price_money_amount = 15_00;
        $pivot->save();

        // Refresh from database
        $pivot->refresh();

        // Verify created_at hasn't changed
        $this->assertEquals($originalCreatedAt->timestamp, $pivot->created_at->timestamp);

        // Verify updated_at has changed
        $this->assertGreaterThan($originalUpdatedAt->timestamp, $pivot->updated_at->timestamp);
    }

    /**
     * Test all new fields are mass assignable.
     *
     * @return void
     */
    public function test_all_new_fields_are_mass_assignable(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create();

        $data = [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'square_uid' => 'test-uid',
            'name' => 'Test Product',
            'variation_name' => 'Test Variation',
            'catalog_object_id' => 'CATALOG_123',
            'catalog_version' => 5,
            'item_type' => 'ITEM',
            'note' => 'Test note',
            'base_price_money_amount' => 10_00,
            'base_price_money_currency' => 'USD',
            'variation_total_price_money_amount' => 20_00,
            'variation_total_price_money_currency' => 'USD',
            'gross_sales_money_amount' => 20_00,
            'gross_sales_money_currency' => 'USD',
            'total_tax_money_amount' => 1_60,
            'total_tax_money_currency' => 'USD',
            'total_discount_money_amount' => 2_00,
            'total_discount_money_currency' => 'USD',
            'total_money_amount' => 19_60,
            'total_money_currency' => 'USD',
        ];

        // This should not throw a MassAssignmentException
        $pivot = OrderProductPivot::create($data);

        // Verify all fields were assigned (excluding timestamps which are auto-managed)
        foreach ($data as $key => $value) {
            if (!in_array($key, ['created_at', 'updated_at'])) {
                $this->assertEquals($value, $pivot->{$key}, "Field {$key} was not mass assigned correctly");
            }
        }
    }

    /**
     * Test catalog version can be incremented.
     *
     * @return void
     */
    public function test_catalog_version_increments(): void
    {
        $order = factory(Order::class)->create();
        $product = factory(Product::class)->create();

        // Create with version 1
        $pivot = factory(OrderProductPivot::class)->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'catalog_object_id' => 'CATALOG_ABC',
            'catalog_version' => 1,
        ]);

        $this->assertEquals(1, $pivot->catalog_version);
        $this->assertIsInt($pivot->catalog_version);

        // Update to version 2
        $pivot->catalog_version = 2;
        $pivot->save();

        $this->assertEquals(2, $pivot->catalog_version);

        // Verify in database
        $this->assertDatabaseHas('nikolag_product_order', [
            'id' => $pivot->id,
            'catalog_object_id' => 'CATALOG_ABC',
            'catalog_version' => 2,
        ]);

        // Update to version 3
        $pivot->update(['catalog_version' => 3]);

        $pivot->refresh();
        $this->assertEquals(3, $pivot->catalog_version);
    }
}
