<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Inventory;

use App\Application\Inventory\InventoryPrintingMaterialResolver;
use App\Application\Inventory\InventoryPrintingMaterialStatus;
use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Inventory\InventoryCommitment;
use App\Entity\Inventory\InventoryCommitmentSubstitution;
use App\Entity\Inventory\InventoryShortageDecision;
use App\Entity\Materials\Material;
use App\Entity\Materials\MaterialVariant;
use App\Enum\Inventory\InventoryCommitmentStatus;
use App\Enum\Inventory\InventoryShortageDecisionType;
use PHPUnit\Framework\TestCase;

final class InventoryPrintingMaterialStatusTest extends TestCase
{
    public function testMissingMaterialRequestsAdminDecisionAndShowsShortage(): void
    {
        $variant = $this->variant(1, 'QA-VINIL-A-M2', 'Vinil A');
        $commitment = $this->commitment($variant, InventoryCommitmentStatus::SHORTAGE);

        $status = InventoryPrintingMaterialStatus::describe($variant, $commitment, null, null);

        self::assertSame('pending_authorization', $status['code']);
        self::assertSame('6.000000', $status['shortage']);
        self::assertStringContainsString('dashboard administrativo', $status['message']);
    }

    public function testFullSubstitutionDirectsPrintingToReplacementQrOnly(): void
    {
        $originalVariant = $this->variant(1, 'QA-VINIL-A-M2', 'Vinil A');
        $replacementVariant = $this->variant(2, 'QA-VINIL-B-M2', 'Vinil B');
        $original = $this->commitment($originalVariant, InventoryCommitmentStatus::SUBSTITUTED);
        $replacement = $this->createMock(InventoryCommitment::class);
        $replacement->method('getVariant')->willReturn($replacementVariant);
        $substitution = $this->createMock(InventoryCommitmentSubstitution::class);
        $substitution->method('getOriginalCommitment')->willReturn($original);
        $substitution->method('getSubstituteCommitment')->willReturn($replacement);
        $substitution->method('getSubstituteQuantity')->willReturn('6.000000');

        $status = InventoryPrintingMaterialStatus::describe($originalVariant, $original, null, $substitution);
        $allowed = InventoryPrintingMaterialResolver::authorizedVariants($originalVariant, [$substitution]);

        self::assertSame('substituted', $status['code']);
        self::assertStringContainsString('Vinil B', $status['message']);
        self::assertSame([$replacementVariant], $allowed);
    }

    public function testWaitingForPurchaseIsVisibleAsAProductionBlock(): void
    {
        $variant = $this->variant(1, 'QA-VINIL-A-M2', 'Vinil A');
        $commitment = $this->commitment($variant, InventoryCommitmentStatus::SHORTAGE);
        $decision = $this->createMock(InventoryShortageDecision::class);
        $decision->method('getType')->willReturn(InventoryShortageDecisionType::WAIT_FOR_PURCHASE);

        $status = InventoryPrintingMaterialStatus::describe($variant, $commitment, $decision, null);

        self::assertSame('waiting_purchase', $status['code']);
        self::assertStringContainsString('no inicies la impresión', $status['message']);
    }

    private function variant(int $id, string $code, string $name): MaterialVariant
    {
        $unit = $this->createMock(MeasurementUnit::class);
        $unit->method('getSymbol')->willReturn('m²');
        $material = $this->createMock(Material::class);
        $material->method('getName')->willReturn($name);
        $variant = $this->createMock(MaterialVariant::class);
        $variant->method('getId')->willReturn($id);
        $variant->method('getCode')->willReturn($code);
        $variant->method('getMaterial')->willReturn($material);
        $variant->method('getInventoryUnit')->willReturn($unit);

        return $variant;
    }

    private function commitment(MaterialVariant $variant, InventoryCommitmentStatus $status): InventoryCommitment
    {
        $commitment = $this->createMock(InventoryCommitment::class);
        $commitment->method('getVariant')->willReturn($variant);
        $commitment->method('getStatus')->willReturn($status);
        $commitment->method('getShortageQuantity')->willReturn('6.000000');
        $commitment->method('getPendingQuantity')->willReturn('0.000000');

        return $commitment;
    }
}
