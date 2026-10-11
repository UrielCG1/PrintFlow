<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Users\User;
use App\Enum\Production\DesignAuthorizationType;
use App\Enum\Production\ProductionFilePurpose;
use App\Repository\Production\ServiceOrderItemDesignReleaseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ServiceOrderItemDesignReleaseRepository::class)]
#[ORM\Table(name: 'service_order_item_design_releases')]
#[ORM\UniqueConstraint(name: 'uniq_design_release_item_version', columns: ['service_order_item_id', 'version_number'])]
#[ORM\UniqueConstraint(name: 'uniq_design_release_item_current', columns: ['service_order_item_id', 'current_marker'])]
#[ORM\Index(name: 'idx_design_release_artwork', columns: ['artwork_file_id'])]
#[ORM\Index(name: 'idx_design_release_reference', columns: ['reference_file_id'])]
class ServiceOrderItemDesignRelease
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'service_order_item_id', nullable: false, onDelete: 'RESTRICT')]
    private ServiceOrderItem $serviceOrderItem;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'artwork_file_id', nullable: false, onDelete: 'RESTRICT')]
    private ProductionFile $artworkFile;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'reference_file_id', nullable: false, onDelete: 'RESTRICT')]
    private ProductionFile $referenceFile;

    #[ORM\Column(name: 'authorization_type', length: 30, enumType: DesignAuthorizationType::class)]
    private DesignAuthorizationType $authorizationType;

    #[ORM\Column(name: 'version_number', options: ['unsigned' => true])]
    private int $versionNumber;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $instructions;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'authorized_by_user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $authorizedBy;

    #[ORM\Column(name: 'authorized_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $authorizedAt;

    #[ORM\Column(name: 'current_marker', nullable: true, options: ['unsigned' => true, 'default' => 1])]
    private ?int $currentMarker = 1;

    #[ORM\Column(name: 'superseded_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $supersededAt = null;

    public function __construct(
        ServiceOrderItem $serviceOrderItem,
        ProductionFile $artworkFile,
        ProductionFile $referenceFile,
        DesignAuthorizationType $authorizationType,
        int $versionNumber,
        ?string $instructions,
        User $authorizedBy,
    ) {
        if ($versionNumber < 1) {
            throw new \InvalidArgumentException('La versión de liberación de Diseño debe ser positiva.');
        }
        if ($artworkFile->getServiceOrderItem() !== $serviceOrderItem || $referenceFile->getServiceOrderItem() !== $serviceOrderItem) {
            throw new \DomainException('Los archivos liberados no pertenecen a la misma partida.');
        }
        if ($referenceFile->getPurpose() !== ProductionFilePurpose::DESIGN_REFERENCE) {
            throw new \DomainException('La liberación requiere una captura de referencia de Diseño.');
        }
        if ($authorizationType === DesignAuthorizationType::CLIENT_ORIGINAL && $artworkFile->getPurpose() !== ProductionFilePurpose::CLIENT_ORIGINAL) {
            throw new \DomainException('La autorización del original debe referirse al archivo del cliente.');
        }
        if ($authorizationType === DesignAuthorizationType::PREPARED_ARTWORK && $artworkFile->getPurpose() !== ProductionFilePurpose::PREPARED_ARTWORK) {
            throw new \DomainException('La autorización preparada debe referirse al arte creado por Diseño.');
        }

        $instructions = trim((string) $instructions);
        if (mb_strlen($instructions) > 5000) {
            throw new \InvalidArgumentException('Las instrucciones de Diseño no pueden superar 5,000 caracteres.');
        }

        $this->serviceOrderItem = $serviceOrderItem;
        $this->artworkFile = $artworkFile;
        $this->referenceFile = $referenceFile;
        $this->authorizationType = $authorizationType;
        $this->versionNumber = $versionNumber;
        $this->instructions = $instructions !== '' ? $instructions : null;
        $this->authorizedBy = $authorizedBy;
        $this->authorizedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getServiceOrderItem(): ServiceOrderItem { return $this->serviceOrderItem; }
    public function getArtworkFile(): ProductionFile { return $this->artworkFile; }
    public function getReferenceFile(): ProductionFile { return $this->referenceFile; }
    public function getAuthorizationType(): DesignAuthorizationType { return $this->authorizationType; }
    public function getVersionNumber(): int { return $this->versionNumber; }
    public function getInstructions(): ?string { return $this->instructions; }
    public function getAuthorizedBy(): User { return $this->authorizedBy; }
    public function getAuthorizedAt(): \DateTimeImmutable { return $this->authorizedAt; }
    public function isCurrent(): bool { return $this->currentMarker !== null; }
    public function getSupersededAt(): ?\DateTimeImmutable { return $this->supersededAt; }

    public function supersede(): self
    {
        if ($this->currentMarker === null) {
            throw new \DomainException('La liberación de Diseño ya fue sustituida.');
        }

        $this->currentMarker = null;
        $this->supersededAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this;
    }
}
