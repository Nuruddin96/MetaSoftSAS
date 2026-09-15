<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\ProductAttribute;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Catalog Architecture project: product_attributes/product_attribute_values
 * are new, additive tables (a reusable tenant-scoped vocabulary layer) —
 * see ProductAttribute model's docblock. Does not touch
 * product_variants.attributes JSON at all.
 */
class ProductAttributeTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    private function actingAsTenantUser(): \App\Models\Tenant
    {
        $tenant = $this->makeTenant();
        Sanctum::actingAs($this->makeUser($tenant->id));

        return $tenant;
    }

    public function test_store_creates_an_attribute_with_initial_values(): void
    {
        $this->actingAsTenantUser();

        $response = $this->postJson('/api/mobile/v1/attributes', ['name' => 'Color', 'values' => ['Red', 'Black']]);

        $response->assertCreated()->assertJsonPath('name', 'Color');
        $this->assertSame(['Red', 'Black'], collect($response->json('values'))->pluck('value')->all());
    }

    public function test_store_rejects_a_duplicate_attribute_name_for_the_same_tenant(): void
    {
        $tenant = $this->actingAsTenantUser();
        ProductAttribute::create(['tenant_id' => $tenant->id, 'name' => 'Color']);

        $this->postJson('/api/mobile/v1/attributes', ['name' => 'Color'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_two_different_tenants_can_each_have_an_attribute_with_the_same_name(): void
    {
        $otherTenant = $this->makeTenant();
        ProductAttribute::create(['tenant_id' => $otherTenant->id, 'name' => 'Color']);

        $this->actingAsTenantUser();

        $this->postJson('/api/mobile/v1/attributes', ['name' => 'Color'])->assertCreated();
    }

    public function test_add_value_appends_to_an_existing_attribute(): void
    {
        $tenant = $this->actingAsTenantUser();
        $attribute = ProductAttribute::create(['tenant_id' => $tenant->id, 'name' => 'Size']);

        $this->postJson("/api/mobile/v1/attributes/{$attribute->id}/values", ['value' => 'M'])
            ->assertCreated()->assertJsonPath('value', 'M');
    }

    public function test_update_renames_an_attribute(): void
    {
        $tenant = $this->actingAsTenantUser();
        $attribute = ProductAttribute::create(['tenant_id' => $tenant->id, 'name' => 'Colour']);

        $this->patchJson("/api/mobile/v1/attributes/{$attribute->id}", ['name' => 'Color'])
            ->assertOk()->assertJsonPath('name', 'Color');
    }

    public function test_update_rejects_renaming_another_tenants_attribute(): void
    {
        $this->actingAsTenantUser();
        $otherTenant = $this->makeTenant();
        $foreign = ProductAttribute::create(['tenant_id' => $otherTenant->id, 'name' => 'Not Yours']);

        $this->patchJson("/api/mobile/v1/attributes/{$foreign->id}", ['name' => 'Hacked'])->assertNotFound();
    }

    public function test_update_value_renames_a_value(): void
    {
        $tenant = $this->actingAsTenantUser();
        $attribute = ProductAttribute::create(['tenant_id' => $tenant->id, 'name' => 'Color']);
        $value = $attribute->values()->create(['value' => 'Redd']);

        $this->patchJson("/api/mobile/v1/attribute-values/{$value->id}", ['value' => 'Red'])
            ->assertOk()->assertJsonPath('value', 'Red');
    }

    public function test_update_value_rejects_renaming_another_tenants_value(): void
    {
        $this->actingAsTenantUser();
        $otherTenant = $this->makeTenant();
        $foreignAttribute = ProductAttribute::create(['tenant_id' => $otherTenant->id, 'name' => 'Foreign']);
        $foreignValue = $foreignAttribute->values()->create(['value' => 'X']);

        $this->patchJson("/api/mobile/v1/attribute-values/{$foreignValue->id}", ['value' => 'Hacked'])->assertNotFound();
    }

    /**
     * Renaming an attribute/value must never touch existing variants —
     * product_variants.attributes JSON has no live reference back to this
     * vocabulary table (see ProductAttribute model's docblock), so a
     * variant that already used the old name keeps it verbatim.
     */
    public function test_renaming_an_attribute_or_value_never_touches_existing_variant_json(): void
    {
        $tenant = $this->actingAsTenantUser();
        $attribute = ProductAttribute::create(['tenant_id' => $tenant->id, 'name' => 'Color']);
        $value = $attribute->values()->create(['value' => 'Red']);

        $product = \App\Models\Product::create(['tenant_id' => $tenant->id, 'name' => 'Shirt']);
        $variant = \App\Models\ProductVariant::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'variant_name' => 'Red', 'selling_price' => 100, 'attributes' => ['Color' => 'Red'],
        ]);

        $this->patchJson("/api/mobile/v1/attributes/{$attribute->id}", ['name' => 'Colour'])->assertOk();
        $this->patchJson("/api/mobile/v1/attribute-values/{$value->id}", ['value' => 'Crimson'])->assertOk();

        $this->assertSame(['Color' => 'Red'], $variant->fresh()->attributes);
    }

    public function test_index_never_exposes_another_tenants_attributes(): void
    {
        $this->actingAsTenantUser();
        $otherTenant = $this->makeTenant();
        ProductAttribute::create(['tenant_id' => $otherTenant->id, 'name' => 'Not Yours']);

        $response = $this->getJson('/api/mobile/v1/attributes')->assertOk();
        $names = collect($response->json('data'))->pluck('name');

        $this->assertFalse($names->contains('Not Yours'));
    }

    public function test_destroy_value_rejects_deleting_another_tenants_value(): void
    {
        $this->actingAsTenantUser();
        $otherTenant = $this->makeTenant();
        $foreignAttribute = ProductAttribute::create(['tenant_id' => $otherTenant->id, 'name' => 'Foreign']);
        $foreignValue = $foreignAttribute->values()->create(['value' => 'X']);

        $this->deleteJson("/api/mobile/v1/attribute-values/{$foreignValue->id}")->assertNotFound();
    }

    public function test_destroy_removes_an_attribute_and_its_values(): void
    {
        $tenant = $this->actingAsTenantUser();
        $attribute = ProductAttribute::create(['tenant_id' => $tenant->id, 'name' => 'Color']);
        $attribute->values()->create(['value' => 'Red']);

        $this->deleteJson("/api/mobile/v1/attributes/{$attribute->id}")->assertOk();

        $this->assertDatabaseMissing('product_attributes', ['id' => $attribute->id]);
        $this->assertDatabaseMissing('product_attribute_values', ['product_attribute_id' => $attribute->id]);
    }
}
