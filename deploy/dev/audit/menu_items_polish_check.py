#!/usr/bin/env python3
"""Regression checks for the tenant-aware Menu Items catalog."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
CONTROLLER = (ROOT / "app/Http/Controllers/MasterController.php").read_text()
VIEW = (ROOT / "resources/views/masters/menu-items.blade.php").read_text()
CONFIG = (ROOT / "config/masters.php").read_text()
MODEL = (ROOT / "app/Models/MenuItem.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()
FORM = (ROOT / "resources/views/masters/form.blade.php").read_text()
MENU_FORM = (ROOT / "resources/views/masters/menu-item-form.blade.php").read_text()
VARIANT_MODEL = (ROOT / "app/Models/MenuItemVariant.php").read_text()
VARIANT_MIGRATION = (ROOT / "database/migrations/2026_11_02_000100_create_menu_item_variants.php").read_text()
POS_CONTROLLER = (ROOT / "app/Http/Controllers/PosController.php").read_text()
POS_VIEW = (ROOT / "resources/views/pos/terminal.blade.php").read_text()
KOT_LINES = (ROOT / "resources/views/pos/kot-lines.blade.php").read_text()
POS_BILL = (ROOT / "resources/views/pos/bill.blade.php").read_text()
VARIANT_SERVICE = (ROOT / "app/Services/PosMenuVariantService.php").read_text()


class MenuItemsPolishTest(unittest.TestCase):
    def test_catalog_uses_the_real_tenant_menu_item_model_and_existing_master_definition(self):
        self.assertIn("if ($master === 'menu-items')", CONTROLLER)
        self.assertIn("\\App\\Models\\MenuItem::query()", CONTROLLER)
        self.assertIn("use BelongsToHotel;", MODEL)
        self.assertIn("'menu-items' => ['title' => 'Menu Items'", CONFIG)
        self.assertIn("'with' => ['category', 'outlet', 'printer']", CONFIG)
        self.assertIn("'refs' => [['pos_order_items', 'menu_item_id']]", CONFIG)
        self.assertIn("return $this->hasMany(MenuItemVariant::class)->orderBy('sort_order')->orderBy('id')", MODEL)

    def test_multiple_side_dish_variants_persist_name_rate_and_cost_per_menu_item(self):
        for marker in (
            "Schema::create('menu_item_variants'",
            "foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete()",
            "$table->string('name', 100)",
            "$table->decimal('rate', 12, 2)",
            "$table->decimal('cost', 12, 2)",
            "use BelongsToHotel;",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VARIANT_MIGRATION + VARIANT_MODEL)
        for marker in (
            "'variants' => ['nullable', 'array', 'max:30']",
            "'variants.*.name' => ['required', 'string', 'max:100', 'distinct:ignore_case']",
            "'variants.*.rate' => ['required', 'numeric', 'min:0'",
            "'variants.*.cost' => ['required', 'numeric', 'min:0'",
            "'variants_present'",
            "syncMenuItemVariants($row, $variants)",
            "DB::transaction(function () use ($row, $data, $variants)",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, CONTROLLER)
        for marker in (
            'Variants / side dishes',
            'name="variants_present"',
            'Name of side dish',
            'Rate / price ({{ $priceSymbol }})',
            'Cost ({{ $priceSymbol }})',
            'id="addMenuItemVariant"',
            'id="menuItemVariantTemplate"',
            'data-remove-variant',
            "variantRows.dataset.nextIndex",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, MENU_FORM)
        self.assertIn("->withCount('variants')", CONTROLLER)
        self.assertIn('<th scope="col">Side dishes</th>', VIEW)

    def test_pos_allows_multiple_attached_side_dishes_without_exposing_variant_cost(self):
        for marker in (
            "'items.*.variants' => 'nullable|array|max:30'",
            "'items.*.variants.*' => 'integer|owned:menu_item_variants'",
            "PosMenuVariantService::snapshot($m, $line['variants'] ?? [], $k)",
            "array_merge($modifierSnapshots[$k], $sideDishSnapshots)",
            "round($modifierAmounts[$k] + $sideDishAmount, 2)",
            "with(['category', 'variants'])",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, POS_CONTROLLER)
        for marker in (
            'data-variants=',
            'name="items[${mi}][variants][]"',
            "const sideIds = variants.map(v => v.id)",
            "variants.reduce((sum, v) => sum + Number(v.rate || 0), 0)",
            'data-side-variant-id=',
            'Side dishes <span class="tiny muted">Choose any; each rate is added to the item.</span>',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, POS_VIEW)
        self.assertIn("'type' => 'side_dish'", VARIANT_SERVICE)
        self.assertIn("'price_delta' => $rate", VARIANT_SERVICE)
        self.assertNotIn("'cost' =>", VARIANT_SERVICE)
        self.assertIn("'rate' => (float) $v->rate", POS_VIEW)
        self.assertNotIn("'cost' =>", POS_VIEW)
        self.assertIn('Side dishes:', KOT_LINES)
        self.assertIn('Side dishes:', POS_BILL)

    def test_search_and_validated_facets_keep_query_state_and_summarize_real_catalog(self):
        for marker in (
            "'category' => 'nullable|integer|owned:menu_categories'",
            "'outlet' => 'nullable|integer|owned:outlets'",
            "Rule::in(['available', 'hidden'])",
            "where('menu_category_id', $filters['category'])",
            "where('outlet_id', $filters['outlet'])",
            "'COUNT(*) as total_count",
            "as available_count",
            "as hidden_count",
            "paginate(50)->withQueryString()",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, CONTROLLER)
        self.assertIn('name="q" value="{{ $q }}"', VIEW)
        for field in ('category', 'outlet', 'availability'):
            self.assertIn(f'name="{field}"', VIEW)
        self.assertIn('aria-label="Menu item counts"', VIEW)

    def test_catalog_displays_live_item_context_and_accessible_actions(self):
        for marker in (
            'caption class="sr-only"',
            '<th scope="col">Item</th>',
            '<th scope="col">Category</th>',
            '<th scope="col">Outlet</th>',
            '<th scope="col">KOT printer</th>',
            '<th scope="col" class="num">Price</th>',
            '<th scope="col" class="num">Tax</th>',
            "{{ $item->category?->name ?? '—' }}",
            "{{ $item->outlet?->name ?? 'All outlets' }}",
            "{{ $item->printer?->name ?? 'Category / station' }}",
            "{{ money($item->price) }}",
            'aria-label="Edit {{ $item->name }}"',
            'aria-label="Delete {{ $item->name }}"',
            "$photo = $item->imageUrl()",
            "route('masters.edit', [$def['slug'], $item->id])",
            "route('masters.destroy', [$def['slug'], $item->id])",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)
        self.assertIn("'image' => ['Photo', 'image'", CONFIG)
        self.assertIn("'is_available' => ['Available', 'bool'", CONFIG)

    def test_master_create_edit_delete_routes_and_photo_upload_workflow_remain_intact(self):
        self.assertIn("Route::prefix('masters/{master}')->name('masters.')", ROUTES)
        self.assertIn("Route::get('/', 'index')->name('index')", ROUTES)
        self.assertIn("Route::post('/', 'store')->name('store')", ROUTES)
        self.assertIn("Route::put('/{id}', 'update')->name('update')", ROUTES)
        self.assertIn("Route::delete('/{id}', 'destroy')->name('destroy')", ROUTES)
        self.assertIn("Route::patch('/{id}/availability', 'toggleAvailability')->name('availability')", ROUTES)
        self.assertIn("abort_unless($master === 'menu-items', 404)", CONTROLLER)
        self.assertIn("'is_available' => 'required|boolean'", CONTROLLER)
        self.assertIn("MenuItem::findOrFail($id)", CONTROLLER)
        self.assertIn("$row->is_available = $request->boolean('is_available')", CONTROLLER)
        self.assertIn("route('masters.create'", VIEW)
        self.assertIn("route('masters.availability', [$def['slug'], $item->id])", VIEW)
        self.assertIn('aria-label="{{ $item->is_available ? \'Hide \'.$item->name.\' from POS\' : \'Make \'.$item->name.\' available on POS\' }}"', VIEW)
        self.assertIn("route('masters.edit'", VIEW)
        self.assertIn("route('masters.destroy'", VIEW)
        self.assertIn('type="file"', FORM)
        self.assertIn('name="{{ $name }}"', FORM)
        self.assertIn('enctype="multipart/form-data"', FORM)
        self.assertIn("@method('DELETE')", VIEW)

    def test_item_form_groups_existing_fields_and_preserves_upload_edit_and_save_actions(self):
        self.assertEqual(CONTROLLER.count("$view = $master === 'menu-items' ? 'masters.menu-item-form' : 'masters.form';"), 2)
        for marker in (
            "route('masters.update', [$def['slug'], $row->id])",
            "route('masters.store', $def['slug'])",
            '@method(\'PUT\')',
            'name="image"',
            'name="remove_image"',
            'id="menuItemPhotoPreview"',
            'id="menuItemPhotoStatus" class="tiny muted" role="status" aria-live="polite"',
            'id="menuItemTaxPreview"',
            'data-inclusive="{{ $taxInclusive ? \'1\' : \'0\' }}"',
            "TaxService::pricesInclusive()",
            "const net = inclusive ? roundMoney(price / (1 + rate / 100)) : roundMoney(price)",
            "const tax = inclusive ? roundMoney(price - net) : roundMoney(price * rate / 100)",
            "const guestPrice = inclusive ? roundMoney(price) : roundMoney(price + tax)",
            "URL.createObjectURL(file)",
            "URL.revokeObjectURL(previewUrl)",
            r"const extensionIsAllowed = /\.(jpe?g|png|webp)$/i.test(file.name)",
            "file.size > 2 * 1024 * 1024",
            "Choose a JPG, PNG or WebP image.",
            "image.dataset.originalSrc || ''",
            'Saving keeps this item\'s existing POS and order history.',
            "route('masters.index', $def['slug'])",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, MENU_FORM)
        for field in ('code', 'name', 'menu_category_id', 'outlet_id', 'food_type', 'description', 'price', 'tax_rate', 'kot_printer_id', 'is_available'):
            with self.subTest(field=field):
                self.assertIn(f"'{field}'", MENU_FORM)
        self.assertIn('enctype="multipart/form-data"', MENU_FORM)

    def test_catalog_has_responsive_filter_and_table_layout(self):
        self.assertIn('class="menu-items-filters"', VIEW)
        self.assertIn('class="table menu-items-table"', VIEW)
        self.assertIn('@media(max-width:1100px)', VIEW)
        self.assertIn('@media(max-width:640px)', VIEW)
        self.assertIn('No menu items match these filters.', VIEW)
        self.assertIn('Clear filters', VIEW)


if __name__ == "__main__":
    unittest.main(verbosity=2)
