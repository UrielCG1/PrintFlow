<?php

declare(strict_types=1);

namespace App\Entity\Clients;

use App\Repository\Clients\ClientClassRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClientClassRepository::class)]
#[ORM\Table(name: 'client_classes')]
#[ORM\UniqueConstraint(name: 'uniq_client_classes_code', columns: ['code'])]
#[ORM\HasLifecycleCallbacks]
class ClientClass
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 1)]
    private string $code;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(name: 'loyalty_discount_percent', type: Types::DECIMAL, precision: 7, scale: 4)]
    private string $loyaltyDiscountPercent = '0.0000';

    #[ORM\Column(name: 'config_revision', options: ['unsigned' => true, 'default' => 1])]
    private int $configRevision = 1;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'is_system', options: ['default' => true])]
    private bool $isSystem = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $code = 'C', string $name = 'Clase C')
    {
        $this->setCode($code);
        $this->name = trim($name);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAt = $this->updatedAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self
    {
        $code = strtoupper(trim($code));
        if (!in_array($code, ['A', 'B', 'C'], true)) {
            throw new \InvalidArgumentException('La clase de cliente debe ser A, B o C.');
        }
        $this->code = $code;
        return $this;
    }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $name = trim($name); if ($name !== $this->name) { $this->name = $name; ++$this->configRevision; } return $this; }
    public function getLoyaltyDiscountPercent(): string { return $this->loyaltyDiscountPercent; }
    public function setLoyaltyDiscountPercent(string $value): self
    {
        $value = trim(str_replace(',', '.', $value));
        if (!preg_match('/^(?:0|[1-9]\d{0,2})(?:\.\d{1,4})?$/D', $value)) {
            throw new \InvalidArgumentException('El porcentaje de lealtad no es válido.');
        }
        $decimal = \Brick\Math\BigDecimal::of($value);
        if ($decimal->compareTo('100') > 0) {
            throw new \InvalidArgumentException('El porcentaje de lealtad no puede superar 100%.');
        }
        $this->loyaltyDiscountPercent = $decimal->toScale(4)->__toString();
        ++$this->configRevision;
        return $this;
    }
    public function getConfigRevision(): int { return $this->configRevision; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $active): self { $this->isActive = $active; ++$this->configRevision; return $this; }
    public function isSystem(): bool { return $this->isSystem; }
    public function setIsSystem(bool $system): self { $this->isSystem = $system; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void { $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
}
