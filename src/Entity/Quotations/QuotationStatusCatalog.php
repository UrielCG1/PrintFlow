<?php

declare(strict_types=1);

namespace App\Entity\Quotations;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'quotation_statuses')]
class QuotationStatusCatalog
{
    #[ORM\Id]
    #[ORM\Column(length: 30)]
    private string $code;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(name: 'display_order')]
    private int $displayOrder = 0;

    #[ORM\Column(name: 'is_terminal', options: ['default' => false])]
    private bool $isTerminal = false;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }
    public function getDisplayOrder(): int { return $this->displayOrder; }
    public function isTerminal(): bool { return $this->isTerminal; }
    public function isActive(): bool { return $this->isActive; }
}
