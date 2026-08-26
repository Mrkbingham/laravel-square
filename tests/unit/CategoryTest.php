<?php

namespace Nikolag\Square\Tests\Unit;

use Nikolag\Square\Models\Category;
use Nikolag\Square\Models\Product;
use Nikolag\Square\Tests\TestCase;

class CategoryTest extends TestCase
{
    /**
     * Category creation in memory.
     *
     * @return void
     */
    public function test_category_make(): void
    {
        $category = factory(Category::class)->make();

        $this->assertNotNull($category, 'Category is null.');
    }

    /**
     * Category persisting.
     *
     * @return void
     */
    public function test_category_create(): void
    {
        $name = $this->faker->word;

        $category = factory(Category::class)->create([
            'name' => $name,
        ]);

        $this->assertDatabaseHas('nikolag_categories', [
            'name' => $name,
        ]);
    }

    /**
     * Category parent relationship.
     *
     * @return void
     */
    public function test_category_with_parent(): void
    {
        $parent = factory(Category::class)->create(['is_top_level' => true]);
        $child = factory(Category::class)->create([
            'parent_category_id' => $parent->id,
            'is_top_level'       => false,
        ]);

        $this->assertEquals($parent->id, $child->parent->id);
    }

    /**
     * Category children relationship.
     *
     * @return void
     */
    public function test_category_children_relationship(): void
    {
        $parent = factory(Category::class)->create();
        $child1 = factory(Category::class)->create(['parent_category_id' => $parent->id]);
        $child2 = factory(Category::class)->create(['parent_category_id' => $parent->id]);

        $this->assertCount(2, $parent->children);
        $this->assertContainsOnlyInstancesOf(Category::class, $parent->children);
    }

    /**
     * Category products many-to-many relationship with ordinal pivot.
     *
     * @return void
     */
    public function test_category_products_relationship(): void
    {
        $category = factory(Category::class)->create();
        $product = factory(Product::class)->create();

        $category->products()->attach($product->id, ['ordinal' => 5]);

        $this->assertDatabaseHas('nikolag_category_product', [
            'category_id' => $category->id,
            'product_id'  => $product->id,
            'ordinal'     => 5,
        ]);

        $this->assertCount(1, $category->products);
        $this->assertEquals(5, $category->products->first()->pivot->ordinal);
    }

    /**
     * Product categories inverse relationship.
     *
     * @return void
     */
    public function test_product_categories_relationship(): void
    {
        $product = factory(Product::class)->create();
        $category1 = factory(Category::class)->create();
        $category2 = factory(Category::class)->create();

        $product->categories()->attach([
            $category1->id => ['ordinal' => 1],
            $category2->id => ['ordinal' => 2],
        ]);

        $product->refresh();

        $this->assertCount(2, $product->categories);
        $this->assertContainsOnlyInstancesOf(Category::class, $product->categories);
    }

    /**
     * Category image_ids JSON cast.
     *
     * @return void
     */
    public function test_category_image_ids_json_cast(): void
    {
        $imageIds = ['img_abc123', 'img_def456'];

        $category = factory(Category::class)->create([
            'image_ids' => $imageIds,
        ]);

        $category->refresh();

        $this->assertIsArray($category->image_ids);
        $this->assertEquals($imageIds, $category->image_ids);
    }

    /**
     * Category boolean casts.
     *
     * @return void
     */
    public function test_category_boolean_casts(): void
    {
        $category = factory(Category::class)->create([
            'is_top_level'      => true,
            'online_visibility' => false,
        ]);

        $category->refresh();

        $this->assertIsBool($category->is_top_level);
        $this->assertTrue($category->is_top_level);
        $this->assertIsBool($category->online_visibility);
        $this->assertFalse($category->online_visibility);
    }
}
