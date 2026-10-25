<?php

namespace App\Services\Catalog\Import;

use App\Enums\ProductType;
use App\Models\Attribute;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\RichText;
use Illuminate\Support\Str;

/**
 * The columns a catalog spreadsheet can have, and strict parsing of each cell. A value that
 * isn't exactly one of the accepted forms throws ImportValueError with the reason: nothing is
 * rounded, trimmed to fit or guessed. Blank cells mean "not given" (never "clear it").
 */
final class ImportFields
{
    public const PRODUCT = 'product';

    public const VARIANT = 'variant';

    /**
     * key => [label, level, help]
     *
     * @return array<string, array{label: string, level: string, help: string}>
     */
    public static function all(): array
    {
        $types = implode(', ', array_map(fn (ProductType $t) => $t->value, ProductType::cases()));

        return [
            'sku' => ['label' => 'SKU', 'level' => self::VARIANT, 'help' => 'Required. Matches existing variants; a new SKU creates a variant.'],
            'product_slug' => ['label' => 'Product slug', 'level' => self::PRODUCT, 'help' => 'Rows with the same slug are variants of one product. Blank = made from the product name.'],
            'product_name' => ['label' => 'Product name', 'level' => self::PRODUCT, 'help' => 'Needed for new products.'],
            'category' => ['label' => 'Category', 'level' => self::PRODUCT, 'help' => 'Full path, e.g. "Living room > Sofas". Needed for new products.'],
            'brand' => ['label' => 'Brand', 'level' => self::PRODUCT, 'help' => ''],
            'type' => ['label' => 'Type', 'level' => self::PRODUCT, 'help' => "One of: {$types}. Blank on a new product = stock."],
            'description' => ['label' => 'Description', 'level' => self::PRODUCT, 'help' => 'Plain text or simple HTML.'],
            'width_mm' => ['label' => 'Width (mm)', 'level' => self::PRODUCT, 'help' => 'Whole millimetres.'],
            'depth_mm' => ['label' => 'Depth (mm)', 'level' => self::PRODUCT, 'help' => 'Whole millimetres.'],
            'height_mm' => ['label' => 'Height (mm)', 'level' => self::PRODUCT, 'help' => 'Whole millimetres.'],
            'weight_g' => ['label' => 'Weight (g)', 'level' => self::PRODUCT, 'help' => 'Whole grams.'],
            'assembly_required' => ['label' => 'Assembly required', 'level' => self::PRODUCT, 'help' => 'yes / no'],
            'requires_delivery' => ['label' => 'Requires delivery', 'level' => self::PRODUCT, 'help' => 'yes / no'],
            'warranty_months' => ['label' => 'Warranty (months)', 'level' => self::PRODUCT, 'help' => '0–600. Blank = category default.'],
            'is_serialized' => ['label' => 'Serialized', 'level' => self::PRODUCT, 'help' => 'yes / no: track each piece by serial number.'],
            'publish_online' => ['label' => 'Publish online', 'level' => self::PRODUCT, 'help' => 'yes / no'],
            'is_active' => ['label' => 'Product active', 'level' => self::PRODUCT, 'help' => 'yes / no'],
            'tags' => ['label' => 'Tags', 'level' => self::PRODUCT, 'help' => 'Comma separated. Replaces the product’s tags.'],
            'barcode' => ['label' => 'Barcode', 'level' => self::VARIANT, 'help' => 'Blank on a new variant = generated.'],
            'price' => ['label' => 'Price', 'level' => self::VARIANT, 'help' => 'Rupees, e.g. 125000 or 125000.50. Needed for new variants.'],
            'min_price' => ['label' => 'Minimum price', 'level' => self::VARIANT, 'help' => 'Selling below it needs a manager. Blank on a new variant = 0.'],
            'online_price' => ['label' => 'Online price', 'level' => self::VARIANT, 'help' => 'Blank = same as price.'],
            'reorder_level' => ['label' => 'Reorder level', 'level' => self::VARIANT, 'help' => 'Quantity, up to 3 decimals.'],
            'reorder_qty' => ['label' => 'Reorder quantity', 'level' => self::VARIANT, 'help' => 'Quantity, up to 3 decimals.'],
            'variant_active' => ['label' => 'Variant active', 'level' => self::VARIANT, 'help' => 'yes / no'],
        ];
    }

    /**
     * Everything a column can be mapped to: the fixed fields plus "attribute:{id}" for each attribute.
     *
     * @return array<string, string> key => label
     */
    public static function targets(): array
    {
        return [
            ...collect(self::all())->map(fn ($f) => $f['label'])->all(),
            ...Attribute::query()->orderBy('sort_order')->orderBy('name')->get()->mapWithKeys(fn (Attribute $a) => ["attribute:{$a->id}" => "Option: {$a->name}"])->all(),
        ];
    }

    /** Header text → target key, only on an exact (case/space-insensitive) match with a key or label. */
    public static function suggest(string $header): ?string
    {
        $wanted = self::comparable($header);

        foreach (self::targets() as $key => $label) {
            if ($wanted === self::comparable($key) || $wanted === self::comparable($label) || $wanted === self::comparable(Str::after($label, 'Option: '))) {
                return $key;
            }
        }

        return null;
    }

    /** The cell as text, or null when blank. Non-text cells (dates, formulas that failed) are refused. */
    public static function text(mixed $cell): ?string
    {
        if ($cell === null) {
            return null;
        }

        if (is_bool($cell)) {
            return $cell ? 'yes' : 'no';
        }

        if (is_int($cell) || is_float($cell)) {
            return is_float($cell) && floor($cell) == $cell && abs($cell) < 1e15 ? (string) (int) $cell : (string) $cell;
        }

        if (! is_string($cell)) {
            throw new ImportValueError('is not text or a number (is it formatted as a date?)');
        }

        $text = trim(preg_replace('/\s+/u', ' ', $cell));

        return $text === '' ? null : $text;
    }

    /** Parse one given (non-blank) value for a field. */
    public static function parse(string $field, string $value): mixed
    {
        return match ($field) {
            'sku' => self::code($value, 'SKU'),
            'barcode' => self::barcode($value),
            'product_slug' => self::slug($value),
            'product_name' => self::length($value, 255),
            'brand' => self::length($value, 80),
            'category' => self::length($value, 400),
            'type' => self::type($value),
            'description' => self::description($value),
            'width_mm', 'depth_mm', 'height_mm' => self::integer($value, 0, 100000),
            'weight_g' => self::integer($value, 0, 10000000),
            'warranty_months' => self::integer($value, 0, 600),
            'assembly_required', 'requires_delivery', 'is_serialized', 'publish_online', 'is_active', 'variant_active' => self::boolean($value),
            'tags' => self::tags($value),
            'price', 'min_price', 'online_price' => self::money($value),
            'reorder_level', 'reorder_qty' => self::quantity($value),
            default => throw new ImportValueError('is not a column we import'),
        };
    }

    public static function boolean(string $value): bool
    {
        return match (strtolower($value)) {
            'yes', 'y', 'true', '1' => true,
            'no', 'n', 'false', '0' => false,
            default => throw new ImportValueError("\"{$value}\" is not yes or no"),
        };
    }

    public static function money(string $value): int
    {
        $clean = preg_replace('/^(rs\.?|lkr)\s*/i', '', $value);

        try {
            $cents = Money::fromDecimal($clean)->cents;
        } catch (\InvalidArgumentException) {
            throw new ImportValueError("\"{$value}\" is not an amount (use 125000 or 125000.50)");
        }

        if ($cents < 0) {
            throw new ImportValueError("\"{$value}\" is negative");
        }

        return $cents;
    }

    public static function quantity(string $value): int
    {
        try {
            $milli = Quantity::parse($value);
        } catch (\InvalidArgumentException) {
            throw new ImportValueError("\"{$value}\" is not a quantity (up to 3 decimals)");
        }

        if ($milli < 0) {
            throw new ImportValueError("\"{$value}\" is negative");
        }

        return $milli;
    }

    public static function integer(string $value, int $min, int $max): int
    {
        $clean = str_replace(',', '', $value);

        if (! preg_match('/^\d+$/', $clean) || (int) $clean < $min || (int) $clean > $max) {
            throw new ImportValueError("\"{$value}\" is not a whole number from {$min} to {$max}");
        }

        return (int) $clean;
    }

    public static function type(string $value): string
    {
        $wanted = self::comparable($value);

        foreach (ProductType::cases() as $type) {
            if ($wanted === self::comparable($type->value) || $wanted === self::comparable($type->label())) {
                return $type->value;
            }
        }

        throw new ImportValueError("\"{$value}\" is not a product type (stock, made_to_order or service)");
    }

    public static function code(string $value, string $what): string
    {
        $code = strtoupper($value);

        if (! preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,63}$/', $code)) {
            throw new ImportValueError("{$what} \"{$value}\" may only use letters, digits and . _ / - (max 64)");
        }

        return $code;
    }

    public static function barcode(string $value): string
    {
        if (! preg_match('/^[\x21-\x7E]{1,64}$/', $value)) {
            throw new ImportValueError("barcode \"{$value}\" has spaces or characters a scanner can't type");
        }

        return $value;
    }

    public static function slug(string $value): string
    {
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) || strlen($value) > 255) {
            throw new ImportValueError("slug \"{$value}\" must be lowercase letters, digits and dashes (e.g. roma-sofa)");
        }

        return $value;
    }

    /**
     * @return array<int, string>
     */
    public static function tags(string $value): array
    {
        $tags = collect(preg_split('/[,;]/', $value))->map(fn ($t) => trim($t))->filter()->unique(fn ($t) => mb_strtolower($t))->values();

        if ($tags->count() > 20 || $tags->contains(fn ($t) => mb_strlen($t) > 40)) {
            throw new ImportValueError('has more than 20 tags or a tag longer than 40 characters');
        }

        return $tags->all();
    }

    public static function description(string $value): ?string
    {
        if (mb_strlen($value) > RichText::MAX_LENGTH) {
            throw new ImportValueError('is longer than '.RichText::MAX_LENGTH.' characters');
        }

        return str_contains($value, '<') ? RichText::sanitize($value) : '<p>'.e($value).'</p>';
    }

    public static function length(string $value, int $max): string
    {
        if (mb_strlen($value) > $max) {
            throw new ImportValueError("is longer than {$max} characters");
        }

        return $value;
    }

    public static function comparable(string $text): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($text)));
    }
}
