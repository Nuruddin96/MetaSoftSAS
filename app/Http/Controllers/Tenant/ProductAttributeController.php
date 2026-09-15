<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * New, additive — web-panel CRUD for the tenant's reusable attribute
 * vocabulary (ProductAttribute model's docblock). Mirrors
 * Api\Mobile\ProductAttributeController's validation/behavior exactly,
 * redirect-with-flash instead of JSON per this controller group's
 * convention.
 */
class ProductAttributeController extends Controller
{
    public function index()
    {
        $tenant = app('currentTenant');

        return view('tenant.attributes', [
            'attributes' => ProductAttribute::where('tenant_id', $tenant->id)->with('values')->orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $tenant = app('currentTenant');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('product_attributes', 'name')->where('tenant_id', $tenant->id)],
        ]);

        ProductAttribute::create(['tenant_id' => $tenant->id, 'name' => $data['name']]);

        return back()->with('success', 'অ্যাট্রিবিউট যোগ হয়েছে।');
    }

    /**
     * New — rename an attribute. Safe regardless of how many existing
     * variants already carry this attribute's old name in their
     * `attributes` JSON: that JSON stores plain key/value strings with no
     * live reference back to this vocabulary row (see ProductAttribute
     * model's docblock), so renaming here can never corrupt or orphan an
     * existing variant — it only changes what the picker suggests going
     * forward.
     */
    public function update(Request $request, ProductAttribute $attribute)
    {
        $tenant = app('currentTenant');

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('product_attributes', 'name')->where('tenant_id', $tenant->id)->ignore($attribute->id),
            ],
        ]);

        $attribute->update($data);

        return back()->with('success', 'অ্যাট্রিবিউট আপডেট হয়েছে।');
    }

    public function destroy(ProductAttribute $attribute)
    {
        $attribute->delete();

        return back()->with('success', 'অ্যাট্রিবিউট মুছে ফেলা হয়েছে।');
    }

    public function addValue(Request $request, ProductAttribute $attribute)
    {
        $data = $request->validate([
            'value' => [
                'required', 'string', 'max:80',
                Rule::unique('product_attribute_values', 'value')->where('product_attribute_id', $attribute->id),
            ],
        ]);

        $attribute->values()->create(['value' => $data['value'], 'sort_order' => $attribute->values()->count()]);

        return back()->with('success', 'ভ্যালু যোগ হয়েছে।');
    }

    /** New — rename a value. Same "safe by construction" reasoning as update() above. */
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

        return back()->with('success', 'ভ্যালু আপডেট হয়েছে।');
    }

    /**
     * Plain int, not an implicit `ProductAttributeValue $value` binding —
     * this model has no tenant_id/BelongsToTenant of its own (scoped only
     * through its parent attribute), so implicit binding would let one
     * tenant delete another tenant's value by guessing an id. Explicit
     * ownership check via the parent instead.
     */
    public function destroyValue(int $value)
    {
        $tenant = app('currentTenant');
        $value = ProductAttributeValue::whereHas(
            'attribute',
            fn ($q) => $q->where('tenant_id', $tenant->id),
        )->findOrFail($value);
        $value->delete();

        return back()->with('success', 'ভ্যালু মুছে ফেলা হয়েছে।');
    }
}
