<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * New, additive — CRUD for the tenant's reusable attribute vocabulary
 * (ProductAttribute model's docblock). Nothing here touches
 * product_variants.attributes JSON or existing Product/Variant behavior;
 * a tenant that never opens this screen sees no change at all.
 */
class ProductAttributeController extends Controller
{
    public function index()
    {
        $tenant = app('currentTenant');
        $attributes = ProductAttribute::where('tenant_id', $tenant->id)
            ->with('values')
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $attributes->map(fn (ProductAttribute $a) => $this->present($a))->all()]);
    }

    public function store(Request $request)
    {
        $tenant = app('currentTenant');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('product_attributes', 'name')->where('tenant_id', $tenant->id)],
            'values' => 'sometimes|array',
            'values.*' => 'string|max:80',
        ]);

        $attribute = ProductAttribute::create(['tenant_id' => $tenant->id, 'name' => $data['name']]);

        foreach ($data['values'] ?? [] as $i => $value) {
            $attribute->values()->create(['value' => $value, 'sort_order' => $i]);
        }

        return response()->json($this->present($attribute->fresh('values')), 201);
    }

    public function update(Request $request, int $attribute)
    {
        $tenant = app('currentTenant');
        $attribute = ProductAttribute::where('tenant_id', $tenant->id)->findOrFail($attribute);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('product_attributes', 'name')->where('tenant_id', $tenant->id)->ignore($attribute->id),
            ],
        ]);

        $attribute->update($data);

        return response()->json($this->present($attribute->fresh('values')));
    }

    public function destroy(int $attribute)
    {
        $tenant = app('currentTenant');
        $attribute = ProductAttribute::where('tenant_id', $tenant->id)->findOrFail($attribute);
        $attribute->delete();

        return response()->json(['ok' => true]);
    }

    public function addValue(Request $request, int $attribute)
    {
        $tenant = app('currentTenant');
        $attribute = ProductAttribute::where('tenant_id', $tenant->id)->findOrFail($attribute);

        $data = $request->validate([
            'value' => [
                'required', 'string', 'max:80',
                Rule::unique('product_attribute_values', 'value')->where('product_attribute_id', $attribute->id),
            ],
        ]);

        $value = $attribute->values()->create(['value' => $data['value'], 'sort_order' => $attribute->values()->count()]);

        return response()->json(['id' => $value->id, 'value' => $value->value], 201);
    }

    public function destroyValue(int $value)
    {
        $tenant = app('currentTenant');
        $value = ProductAttributeValue::whereHas(
            'attribute',
            fn ($q) => $q->where('tenant_id', $tenant->id),
        )->findOrFail($value);
        $value->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * New — rename a value. Safe regardless of how many existing variants
     * already carry this value string in their `attributes` JSON: that
     * JSON stores plain strings with no live reference back to this
     * vocabulary row (see ProductAttribute model's docblock), so renaming
     * here can never corrupt or orphan an existing variant.
     */
    public function updateValue(Request $request, int $value)
    {
        $tenant = app('currentTenant');
        $value = ProductAttributeValue::whereHas(
            'attribute',
            fn ($q) => $q->where('tenant_id', $tenant->id),
        )->findOrFail($value);

        $data = $request->validate([
            'value' => [
                'required', 'string', 'max:80',
                Rule::unique('product_attribute_values', 'value')->where('product_attribute_id', $value->product_attribute_id)->ignore($value->id),
            ],
        ]);

        $value->update($data);

        return response()->json(['id' => $value->id, 'value' => $value->value]);
    }

    protected function present(ProductAttribute $attribute): array
    {
        return [
            'id' => $attribute->id,
            'name' => $attribute->name,
            'values' => $attribute->values->map(fn (ProductAttributeValue $v) => ['id' => $v->id, 'value' => $v->value])->all(),
        ];
    }
}
