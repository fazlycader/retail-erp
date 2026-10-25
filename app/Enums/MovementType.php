<?php

namespace App\Enums;

enum MovementType: string
{
    case Opening = 'opening';
    case PurchaseReceipt = 'purchase_receipt';
    case SupplierReturn = 'supplier_return';
    case Sale = 'sale';
    case SaleReturn = 'sale_return';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case CountGain = 'count_gain';
    case CountLoss = 'count_loss';
    case Damage = 'damage';
    case ProductionIn = 'production_in';
    case MaterialIssue = 'material_issue';
    case MaterialReturn = 'material_return';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening stock',
            self::PurchaseReceipt => 'Purchase receipt (GRN)',
            self::SupplierReturn => 'Supplier return',
            self::Sale => 'Sale',
            self::SaleReturn => 'Sales return',
            self::TransferOut => 'Transfer out',
            self::TransferIn => 'Transfer in',
            self::AdjustmentIn => 'Adjustment (+)',
            self::AdjustmentOut => 'Adjustment (−)',
            self::CountGain => 'Stock count gain (+)',
            self::CountLoss => 'Stock count loss (−)',
            self::Damage => 'Damaged write-off',
            self::ProductionIn => 'Production receipt',
            self::MaterialIssue => 'Material issue',
            self::MaterialReturn => 'Material return',
        };
    }

    /** +1 for stock coming in, -1 for stock going out. */
    public function sign(): int
    {
        return match ($this) {
            self::Opening,
            self::PurchaseReceipt,
            self::SaleReturn,
            self::TransferIn,
            self::AdjustmentIn,
            self::CountGain,
            self::ProductionIn,
            self::MaterialReturn => 1,

            self::SupplierReturn,
            self::Sale,
            self::TransferOut,
            self::AdjustmentOut,
            self::CountLoss,
            self::Damage,
            self::MaterialIssue => -1,
        };
    }

    public function isIncoming(): bool
    {
        return $this->sign() === 1;
    }

    public function isOutgoing(): bool
    {
        return $this->sign() === -1;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
