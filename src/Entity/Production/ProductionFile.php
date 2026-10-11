<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Users\User;
use App\Enum\Production\ProductionFilePurpose;
use App\Enum\Production\ProductionFileSource;
use App\Repository\Production\ProductionFileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductionFileRepository::class)]
#[ORM\Table(name: 'production_files')]
#[ORM\UniqueConstraint(name: 'uniq_production_file_item_purpose_version', columns: ['service_order_item_id', 'purpose', 'version_number'])]
#[ORM\Index(name: 'idx_production_file_item_purpose_created', columns: ['service_order_item_id', 'purpose', 'created_at'])]
#[ORM\Index(name: 'idx_production_file_sha256', columns: ['sha256'])]
class ProductionFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'service_order_item_id', nullable: false, onDelete: 'RESTRICT')]
    private ServiceOrderItem $serviceOrderItem;

    #[ORM\Column(length: 30, enumType: ProductionFilePurpose::class)]
    private ProductionFilePurpose $purpose;

    #[ORM\Column(name: 'version_number', options: ['unsigned' => true])]
    private int $versionNumber;

    #[ORM\Column(name: 'internal_path', length: 255)]
    private string $internalPath;

    #[ORM\Column(name: 'original_name', length: 255)]
    private string $originalName;

    #[ORM\Column(name: 'mime_type', length: 120)]
    private string $mimeType;

    #[ORM\Column(name: 'byte_size', options: ['unsigned' => true])]
    private int $byteSize;

    #[ORM\Column(length: 64)]
    private string $sha256;

    #[ORM\Column(length: 30, enumType: ProductionFileSource::class)]
    private ProductionFileSource $source;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_user_id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        ServiceOrderItem $serviceOrderItem,
        ProductionFilePurpose $purpose,
        int $versionNumber,
        string $internalPath,
        string $originalName,
        string $mimeType,
        int $byteSize,
        string $sha256,
        ProductionFileSource $source,
        ?User $createdBy,
    ) {
        if ($versionNumber < 1 || $byteSize < 1) {
            throw new \InvalidArgumentException('La versión y el tamaño del archivo deben ser positivos.');
        }
        $internalPath = trim($internalPath);
        $originalName = trim($originalName);
        $mimeType = strtolower(trim($mimeType));
        $sha256 = strtolower(trim($sha256));
        if ($internalPath === '' || $originalName === '' || $mimeType === '' || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            throw new \InvalidArgumentException('Los metadatos del archivo de producción no son válidos.');
        }

        $this->serviceOrderItem = $serviceOrderItem;
        $this->purpose = $purpose;
        $this->versionNumber = $versionNumber;
        $this->internalPath = $internalPath;
        $this->originalName = mb_substr($originalName, 0, 255);
        $this->mimeType = mb_substr($mimeType, 0, 120);
        $this->byteSize = $byteSize;
        $this->sha256 = $sha256;
        $this->source = $source;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getServiceOrderItem(): ServiceOrderItem { return $this->serviceOrderItem; }
    public function getPurpose(): ProductionFilePurpose { return $this->purpose; }
    public function getVersionNumber(): int { return $this->versionNumber; }
    public function getInternalPath(): string { return $this->internalPath; }
    public function getOriginalName(): string { return $this->originalName; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getByteSize(): int { return $this->byteSize; }
    public function getSha256(): string { return $this->sha256; }
    public function getSource(): ProductionFileSource { return $this->source; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
