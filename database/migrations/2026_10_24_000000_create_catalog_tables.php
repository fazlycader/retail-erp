<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 2: product catalog. The catalog is shared by every location (stock and sales per
 * location come later). Money in cents, quantities in milli-units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('managers_edit_catalog')->default(true)->after('allow_negative_stock');
        });

        // Nested up to 3 levels (Category::MAX_DEPTH), checked in CategoryTree.
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->string('image_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('default_warranty_months')->nullable();
            $table->string('commission_group', 40)->nullable(); // used by commissions (step 15)
            $table->timestamps();

            $table->index(['parent_id', 'sort_order']);
        });

        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $table->string('value', 80);
            $table->string('code', 12); // short code for generated SKUs, e.g. GRY
            $table->string('swatch_color', 7)->nullable(); // #RRGGBB
            $table->string('swatch_image_path')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['attribute_id', 'value']);
        });

        // Created early with a name only; step 4 adds contacts, terms and ledgers.
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('brand', 80)->nullable()->index();
            $table->text('description')->nullable(); // sanitised HTML (App\Support\RichText)
            $table->string('type', 20)->default('stock'); // App\Enums\ProductType
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('depth_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->unsignedInteger('weight_g')->nullable();
            $table->boolean('assembly_required')->default(false);
            $table->boolean('requires_delivery')->default(false);
            $table->unsignedSmallInteger('warranty_months')->nullable(); // null = category default
            $table->boolean('is_serialized')->default(false);
            $table->boolean('publish_online')->default(false);
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('tags')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'is_active']);
        });

        // The attributes a product's variants are made of (Colour × Size…), in order.
        Schema::create('attribute_product', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);

            $table->primary(['product_id', 'attribute_id']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 64)->unique();
            $table->string('barcode', 64)->unique();
            $table->string('name')->default(''); // "Grey / 3 seater"; '' for the default variant
            // Sorted attribute value ids ("4-17"), '' for the default variant: one row per combination.
            $table->string('combination_key', 191)->default('');
            $table->unsignedBigInteger('price')->default(0);
            $table->unsignedBigInteger('min_price')->default(0);
            $table->unsignedBigInteger('online_price')->nullable(); // null = same as price
            $table->unsignedBigInteger('average_cost')->default(0); // maintained by GRNs (step 4)
            $table->unsignedBigInteger('last_cost')->nullable();
            $table->unsignedBigInteger('reorder_level')->default(0); // milli-units
            $table->unsignedBigInteger('reorder_qty')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['product_id', 'combination_key']);
        });

        Schema::create('variant_attribute_value', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_value_id')->constrained()->restrictOnDelete();

            $table->primary(['product_variant_id', 'attribute_value_id']);
            $table->index('attribute_value_id');
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('image_path');
            $table->string('thumbnail_path');
            $table->string('alt')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('product_supplier', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('supplier_sku', 64)->nullable();
            $table->unsignedBigInteger('last_cost')->nullable();
            $table->boolean('is_preferred')->default(false);
            $table->timestamps();

            $table->unique(['product_variant_id', 'supplier_id']);
        });

        Schema::create('price_change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('field', 20); // price | min_price | online_price
            $table->unsignedBigInteger('old_value')->nullable();
            $table->unsignedBigInteger('new_value')->nullable();
            $table->string('source', 20); // form | bulk | import
            $table->string('note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['product_variant_id', 'created_at']);
        });

        Schema::create('catalog_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_name');
            $table->string('file_path'); // local (private) disk
            $table->string('file_hash', 64);
            $table->string('status', 20); // App\Enums\ImportStatus
            $table->json('headers');
            $table->json('mapping')->nullable();
            $table->json('options')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->json('counts')->nullable(); // {create, update, unchanged, fail}
            $table->text('error')->nullable();
            $table->timestamp('dry_run_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('catalog_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number'); // spreadsheet row (header = 1)
            $table->string('sku', 64)->nullable();
            $table->string('barcode', 64)->nullable();
            $table->string('product_key')->nullable(); // which rows form one product
            $table->string('combination_key', 191)->nullable();
            $table->string('action', 12); // create | update | unchanged | fail
            $table->json('raw')->nullable(); // the cells as read, keyed by column index
            $table->json('reasons')->nullable();
            $table->json('data')->nullable(); // parsed, validated values
            $table->string('result', 12)->nullable(); // after commit: created | updated | unchanged | failed
            $table->timestamps();

            $table->index(['catalog_import_id', 'action']);
            $table->index(['catalog_import_id', 'sku']);
            $table->index(['catalog_import_id', 'product_key']);
        });
    }

    public function down(): void
    {
        foreach (['catalog_import_rows', 'catalog_imports', 'price_change_logs', 'product_supplier', 'product_images', 'variant_attribute_value',
            'product_variants', 'attribute_product', 'products', 'suppliers', 'attribute_values', 'attributes', 'categories'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('company_settings', fn (Blueprint $table) => $table->dropColumn('managers_edit_catalog'));
    }
};
