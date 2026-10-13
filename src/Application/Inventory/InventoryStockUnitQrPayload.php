<?php

declare(strict_types=1);

namespace App\Application\Inventory;

/** Identifies the versioned QR format for a physical stock unit. */
final readonly class InventoryStockUnitQrPayload
{
    private const PREFIX = 'PF:MAT:1:';

    private function __construct(private string $token)
    {
    }

    public static function fromScanned(string $payload): self
    {
        $payload = trim($payload);
        if (preg_match('/^PF:MAT:1:([a-f0-9]{48})$/iD', $payload, $matches) !== 1) {
            throw new \DomainException('El código escaneado no es una etiqueta QR válida de material de OOXCorp.');
        }

        return new self(strtolower($matches[1]));
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function toCanonicalPayload(): string
    {
        return self::PREFIX.$this->token;
    }
}
