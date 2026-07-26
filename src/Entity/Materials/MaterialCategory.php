<?php

namespace App\Entity\Materials;

use App\Repository\Materials\MaterialCategoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MaterialCategoryRepository::class)]
#[ORM\Table(name: 'material_categories')]
#[ORM\UniqueConstraint(name: 'uniq_material_categories_code', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'uniq_material_categories_name', columns: ['name'])]
#[ORM\Index(name: 'idx_material_categories_active_name', columns: ['is_active', 'name'])]
#[ORM\HasLifecycleCallbacks]
class MaterialCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $code;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(
        name: 'created_at',
        type: 'datetime_immutable',
        options: ['comment' => '(DC2Type:datetime_immutable)'],
    )]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(
        name: 'updated_at',
        type: 'datetime_immutable',
        options: ['comment' => '(DC2Type:datetime_immutable)'],
    )]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = strtoupper(trim($code));

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = trim($name);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $description = trim((string) $description);
        $this->description = $description !== '' ? $description : null;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable(
            'now',
            new \DateTimeZone('UTC'),
        );
    }
}