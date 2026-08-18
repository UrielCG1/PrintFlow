<?php

declare(strict_types=1);

namespace App\Entity\Catalog;

use Doctrine\ORM\Mapping as ORM;

/** Restringe las opciones permitidas de una característica para un producto. */
#[ORM\Entity]
#[ORM\Table(name: 'commercial_item_characteristic_options')]
#[ORM\UniqueConstraint(name: 'uniq_commercial_item_characteristic_options', columns: ['commercial_item_characteristic_id', 'characteristic_option_id'])]
#[ORM\Index(name: 'idx_commercial_item_characteristic_options_order', columns: ['commercial_item_characteristic_id', 'display_order'])]
final class CommercialItemCharacteristicOption
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'allowedOptions')]
    #[ORM\JoinColumn(name: 'commercial_item_characteristic_id', nullable: false, onDelete: 'CASCADE')]
    private CommercialItemCharacteristic $commercialItemCharacteristic;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'characteristic_option_id', nullable: false, onDelete: 'RESTRICT')]
    private CommercialCharacteristicOption $characteristicOption;

    #[ORM\Column(name: 'display_order', options: ['unsigned' => true, 'default' => 0])]
    private int $displayOrder = 0;

    public function getId(): ?int { return $this->id; }
    public function getCommercialItemCharacteristic(): CommercialItemCharacteristic { return $this->commercialItemCharacteristic; }
    public function setCommercialItemCharacteristic(CommercialItemCharacteristic $configuration): self
    {
        if (isset($this->characteristicOption)
            && $this->characteristicOption->getCharacteristic() !== $configuration->getCharacteristic()) {
            throw new \DomainException('La opción no corresponde a la característica configurada para el producto.');
        }

        $this->commercialItemCharacteristic = $configuration;

        return $this;
    }
    public function getCharacteristicOption(): CommercialCharacteristicOption { return $this->characteristicOption; }

    public function setCharacteristicOption(CommercialCharacteristicOption $option): self
    {
        if (isset($this->commercialItemCharacteristic)
            && $option->getCharacteristic() !== $this->commercialItemCharacteristic->getCharacteristic()) {
            throw new \DomainException('La opción no corresponde a la característica configurada para el producto.');
        }

        $this->characteristicOption = $option;

        return $this;
    }

    public function getDisplayOrder(): int { return $this->displayOrder; }

    public function setDisplayOrder(int $displayOrder): self
    {
        if ($displayOrder < 0) { throw new \InvalidArgumentException('El orden de la opción del producto no puede ser negativo.'); }
        $this->displayOrder = $displayOrder;

        return $this;
    }
}
