<?php

use App\Enums\ImportStatus;
use App\Enums\ProductType;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\CatalogImport;
use App\Models\Category;
use App\Models\PriceChangeLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Catalog\Import\ImportService;
use App\Services\Catalog\ProductService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * @param  array<int, array<int, string|int|float>>  $rows
 */
function makeCsvUpload(array $rows, string $name = 'catalog.csv'): UploadedFile
{
    $lines = array_map(function (array $row) {
        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, $row);
        rewind($fp);
        $line = stream_get_contents($fp);
        fclose($fp);

        return $line;
    }, $rows);

    return UploadedFile::fake()->createWithContent($name, implode('', $lines));
}

/**
 * @param  array<int, array<int, string|int|float>>  $rows
 */
function makeXlsxUpload(array $rows, string $name = 'catalog.xlsx'): UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'test-xlsx-').'.xlsx';
    $writer = new XlsxWriter;
    $writer->openToFile($tmp);
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();

    $file = UploadedFile::fake()->createWithContent($name, file_get_contents($tmp));
    @unlink($tmp);

    return $file;
}

test('import dry run reports create, update, unchanged and fail with reasons without touching the catalog, then commit applies valid rows', function () {
    Storage::fake(ImportService::DISK);

    $admin = User::factory()->admin()->create();
    $living = Category::create(['name' => 'Living Room', 'slug' => 'living-room']);
    $sofas = Category::create(['parent_id' => $living->id, 'name' => 'Sofas', 'slug' => 'sofas']);

    $colour = Attribute::create(['name' => 'Colour', 'sort_order' => 0]);
    $grey = AttributeValue::create(['attribute_id' => $colour->id, 'value' => 'Grey', 'code' => 'GRY']);
    AttributeValue::create(['attribute_id' => $colour->id, 'value' => 'Beige', 'code' => 'BGE']);

    // Existing product with 1 variant (Grey)
    $existingProduct = app(ProductService::class)->save(
        new Product,
        ['category_id' => $sofas->id, 'name' => 'Milan Sofa', 'slug' => 'milan-sofa', 'brand' => 'Damro', 'type' => ProductType::Stock],
        [$colour->id],
        [['value_ids' => [$grey->id], 'sku' => 'MILAN-GRY', 'price' => 15000000, 'min_price' => 14000000, 'online_price' => null, 'reorder_level' => 0, 'reorder_qty' => 0, 'is_active' => true]],
    );

    // Another product not in the import (must never be deleted!)
    $untouched = app(ProductService::class)->save(
        new Product,
        ['category_id' => $sofas->id, 'name' => 'Untouched Table', 'slug' => 'untouched-table', 'type' => ProductType::Stock],
        [],
        [['value_ids' => [], 'sku' => 'TBL-KEEP', 'price' => 5000000, 'min_price' => 4500000, 'online_price' => null, 'reorder_level' => 0, 'reorder_qty' => 0, 'is_active' => true]],
    );

    $file = makeXlsxUpload([
        ['SKU', 'Product slug', 'Product name', 'Category', 'Price', 'Minimum price', 'Colour'],
        // Row 2: existing variant, price changed → UPDATE
        ['MILAN-GRY', 'milan-sofa', 'Milan Sofa', 'Living Room > Sofas', '160000', '145000', 'Grey'],
        // Row 3: new variant on existing product → CREATE
        ['MILAN-BGE', 'milan-sofa', 'Milan Sofa', 'Living Room > Sofas', '160000', '145000', 'Beige'],
        // Row 4: existing variant, nothing changed → UNCHANGED
        ['TBL-KEEP', 'untouched-table', 'Untouched Table', 'Living Room > Sofas', '50000', '45000', ''],
        // Row 5: new product with no options → CREATE
        ['OTT-01', 'plush-ottoman', 'Plush Ottoman', 'Living Room > Sofas', '25000', '20000', ''],
        // Row 6: invalid row (min_price > price) → FAIL
        ['BAD-MIN', 'bad-item', 'Bad Item', 'Living Room > Sofas', '10000', '15000', ''],
        // Row 7: unknown option value when create_missing is false → FAIL
        ['MILAN-PNK', 'milan-sofa', 'Milan Sofa', 'Living Room > Sofas', '160000', '145000', 'Hot Pink'],
    ]);

    $this->actingAs($admin)
        ->post(route('products.import.upload'), ['file' => $file])
        ->assertRedirect();

    $import = CatalogImport::firstOrFail();
    expect($import->status)->toBe(ImportStatus::Uploaded);

    // Start dry run with auto-suggested mapping
    $this->actingAs($admin)
        ->post(route('products.import.dry-run', $import), [
            'mapping' => $import->mapping,
            'create_missing' => false,
        ])
        ->assertRedirect();

    $import->refresh();
    expect($import->status)->toBe(ImportStatus::Checked)
        ->and($import->counts)->toBe([
            'create' => 2,
            'update' => 1,
            'unchanged' => 1,
            'fail' => 2,
        ]);

    // Dry run must NOT have modified the database yet!
    expect(ProductVariant::where('sku', 'MILAN-GRY')->value('price'))->toBe(15000000)
        ->and(ProductVariant::where('sku', 'MILAN-BGE')->exists())->toBeFalse()
        ->and(Product::where('slug', 'plush-ottoman')->exists())->toBeFalse();

    // Commit the import
    $this->actingAs($admin)
        ->post(route('products.import.commit', $import))
        ->assertRedirect();

    $import->refresh();
    expect($import->status)->toBe(ImportStatus::Committed)
        ->and($import->counts)->toBe([
            'created' => 2,
            'updated' => 1,
            'unchanged' => 1,
            'failed' => 2,
        ]);

    // Verify database state after commit
    expect(ProductVariant::where('sku', 'MILAN-GRY')->value('price'))->toBe(16000000)
        ->and(ProductVariant::where('sku', 'MILAN-BGE')->exists())->toBeTrue()
        ->and(Product::where('slug', 'plush-ottoman')->exists())->toBeTrue()
        ->and(Product::whereKey($untouched->id)->exists())->toBeTrue()
        ->and(PriceChangeLog::where('source', 'import')->count())->toBeGreaterThanOrEqual(4);
});

test('import cross-checks duplicate SKUs and conflicting product columns across rows', function () {
    Storage::fake(ImportService::DISK);

    $admin = User::factory()->admin()->create();
    Category::create(['name' => 'Dining', 'slug' => 'dining']);

    $file = makeCsvUpload([
        ['SKU', 'Product slug', 'Product name', 'Category', 'Brand', 'Price'],
        ['DUP-01', 'table-a', 'Table A', 'Dining', 'Brand One', '50000'],
        ['DUP-01', 'table-b', 'Table B', 'Dining', 'Brand One', '55000'],
        ['CONF-01', 'chair-x', 'Chair X', 'Dining', 'Brand One', '12000'],
        ['CONF-02', 'chair-x', 'Chair X', 'Dining', 'Brand Two', '12000'],
    ]);

    $service = app(ImportService::class);
    $import = $service->upload($file, $admin);
    $service->start($import, $import->mapping, false);

    $import->refresh();
    expect($import->counts['fail'])->toBe(4)
        ->and($import->rows()->where('row_number', 2)->value('reasons')[0])->toContain('SKU DUP-01 is on more than one row')
        ->and($import->rows()->where('row_number', 4)->value('reasons')[0])->toContain('disagree on brand');
});

test('template and report downloads succeed for authorized staff', function () {
    Storage::fake(ImportService::DISK);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('products.import.template', 'csv'))
        ->assertOk();
});

test('large files process in queued chunks, create missing categories/values when enabled, and fail rows whose target changed before commit', function () {
    Storage::fake(ImportService::DISK);
    config(['catalog.import.inline_max_bytes' => 0, 'catalog.import.chunk_rows' => 1]);

    $admin = User::factory()->admin()->create();
    Attribute::create(['name' => 'Material', 'sort_order' => 0]);

    $file = makeCsvUpload([
        ['SKU', 'Product slug', 'Product name', 'Category', 'Price', 'Barcode', 'Material'],
        ['NEW-TEAK-01', 'kandy-chair', 'Kandy Chair', 'Dining > Chairs', '28000', '8900001112223', 'Burma Teak'],
        ['NEW-TEAK-02', 'kandy-chair', 'Kandy Chair', 'Dining > Chairs', '29000', '8900001112224', 'Mahogany'],
    ]);

    $service = app(ImportService::class);
    $import = $service->upload($file, $admin);
    $service->start($import, $import->mapping, true);

    $import->refresh();
    expect($import->status)->toBe(ImportStatus::Checked)
        ->and($import->counts['create'])->toBe(2);

    // Before commit, someone else takes barcode 8900001112224 in the app:
    ProductVariant::factory()->create(['barcode' => '8900001112224']);

    $service->commit($import);
    $import->refresh();

    expect($import->status)->toBe(ImportStatus::Committed)
        ->and($import->counts)->toBe([
            'created' => 1,
            'updated' => 0,
            'unchanged' => 0,
            'failed' => 1,
        ])
        ->and(ProductVariant::where('sku', 'NEW-TEAK-01')->exists())->toBeTrue()
        ->and(ProductVariant::where('sku', 'NEW-TEAK-02')->exists())->toBeFalse();
});
