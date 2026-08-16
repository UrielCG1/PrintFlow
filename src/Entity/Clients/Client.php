<?php

namespace App\Entity\Clients;

use App\Entity\Common\Phone;
use App\Entity\Common\TaxData;
use App\Repository\Clients\ClientRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClientRepository::class)]
#[ORM\Table(name: 'clients')]
#[ORM\UniqueConstraint(name: 'uniq_clients_tax_id', columns: ['tax_id'])]
#[ORM\Index(name: 'idx_clients_active_name', columns: ['is_active', 'business_name'])]
#[ORM\Index(name: 'idx_clients_deleted_at', columns: ['deleted_at'])]
#[ORM\Index(name: 'idx_clients_category', columns: ['client_category_id'])]
#[ORM\HasLifecycleCallbacks]
class Client
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'business_name', length: 160)]
    private string $businessName;

    /** Tipo de cliente: COMPANY para empresa o INDIVIDUAL para persona física. */
    #[ORM\Column(name: 'client_type', length: 20, options: ['default' => 'COMPANY'])]
    private string $clientType = 'COMPANY';

    /** Giro o actividad comercial principal. */
    #[ORM\Column(name: 'business_activity', length: 160, nullable: true)]
    private ?string $businessActivity = null;

    /** Sitio web institucional. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    /** Fecha de cumpleaños cuando el cliente es una persona física. */
    #[ORM\Column(name: 'birth_date', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $birthDate = null;

    #[ORM\Column(name: 'tax_id', length: 20, nullable: true)]
    private ?string $taxId = null;

    #[ORM\Column(name: 'legal_name', length: 160, nullable: true)]
    private ?string $legalName = null;

    /** Configuraciones fiscales normalizadas. @var Collection<int, TaxData> */
    #[ORM\OneToMany(mappedBy:'client',targetEntity:TaxData::class,cascade:['persist'])]
    private Collection $taxData;

    #[ORM\ManyToOne(targetEntity: ClientCategory::class)]
    #[ORM\JoinColumn(name: 'client_category_id', nullable: true, onDelete: 'RESTRICT')]
    private ?ClientCategory $category = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    /** Teléfonos normalizados asignados al cliente. @var Collection<int, ClientPhone> */
    #[ORM\OneToMany(mappedBy: 'client', targetEntity: ClientPhone::class, cascade: ['persist'])]
    private Collection $phones;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(
        name: 'created_at',
        type: 'datetime_immutable',
        options: ['comment' => '(DC2Type:datetime_immutable)']
    )]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(
        name: 'updated_at',
        type: 'datetime_immutable',
        nullable: false,
        options: ['comment' => '(DC2Type:datetime_immutable)']
    )]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(
        name: 'deleted_at',
        type: 'datetime_immutable',
        nullable: true,
        options: ['comment' => '(DC2Type:datetime_immutable)']
    )]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct()
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->phones = new ArrayCollection();
        $this->taxData = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBusinessName(): string
    {
        return $this->businessName;
    }

    public function getClientType(): string { return $this->clientType; }
    public function setClientType(string $value): self { $this->clientType=strtoupper(trim($value)); return $this; }
    public function getBusinessActivity(): ?string { return $this->businessActivity; }
    public function setBusinessActivity(?string $value): self { $value=trim((string)$value);$this->businessActivity=$value?:null;return $this; }
    public function getWebsite(): ?string { return $this->website; }
    public function setWebsite(?string $value): self { $value=trim((string)$value);$this->website=$value?:null;return $this; }
    public function getBirthDate(): ?\DateTimeImmutable { return $this->birthDate; }
    public function setBirthDate(?\DateTimeImmutable $value): self { $this->birthDate=$value;return $this; }

    public function setBusinessName(string $businessName): self
    {
        $this->businessName = trim($businessName);

        return $this;
    }

    public function getTaxId(): ?string
    {
        return $this->taxId;
    }

    public function setTaxId(?string $taxId): self
    {
        $taxId = trim((string) $taxId);

        $this->taxId = $taxId !== '' ? strtoupper($taxId) : null;

        return $this;
    }

    public function getLegalName(): ?string
    {
        return $this->legalName;
    }

    public function setLegalName(?string $legalName): self
    {
        $legalName = trim((string) $legalName);
        $this->legalName = $legalName !== '' ? $legalName : null;

        return $this;
    }

    public function getTaxRegimeCode(): ?string
    {
        return $this->getDefaultTaxData()?->getTaxRegimeCode();
    }

    public function setTaxRegimeCode(?string $taxRegimeCode): self
    {
        $this->taxDataForWrite()->setTaxRegimeCode($taxRegimeCode);

        return $this;
    }

    public function getFiscalPostalCode(): ?string
    {
        return $this->getDefaultTaxData()?->getFiscalAddress()?->getPostalCode();
    }

    public function setFiscalPostalCode(?string $fiscalPostalCode): self
    {
        // El CP pertenece al domicilio fiscal; se conserva este método por compatibilidad del formulario.

        return $this;
    }

    public function getBillingEmail(): ?string
    {
        return $this->getDefaultTaxData()?->getBillingEmail();
    }

    public function setBillingEmail(?string $billingEmail): self
    {
        $this->taxDataForWrite()->setBillingEmail($billingEmail);

        return $this;
    }

    public function getDefaultCfdiUseCode(): ?string
    {
        return $this->getDefaultTaxData()?->getCfdiUseCode();
    }

    public function setDefaultCfdiUseCode(?string $defaultCfdiUseCode): self
    {
        $this->taxDataForWrite()->setCfdiUseCode($defaultCfdiUseCode);

        return $this;
    }

    private function getDefaultTaxData(): ?TaxData
    {
        foreach ($this->taxData as $item) { if ($item->isActive() && $item->isDefault()) { return $item; } }
        foreach ($this->taxData as $item) { if ($item->isActive()) { return $item; } }
        return null;
    }

    private function taxDataForWrite(): TaxData
    {
        $item=$this->getDefaultTaxData();
        if($item===null){$item=TaxData::draftForClient($this)->setIsDefault(true);$this->taxData->add($item);}
        return $item;
    }

    public function getCategory(): ?ClientCategory
    {
        return $this->category;
    }

    public function setCategory(?ClientCategory $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getDefaultDiscountPercent(): float
    {
        return (float) ($this->category?->getDiscountPercentage() ?? '0.00');
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $email = trim((string) $email);

        $this->email = $email !== '' ? strtolower($email) : null;

        return $this;
    }

    public function getPhone(): ?string
    {
        foreach ($this->phones as $assignment) {
            if ($assignment->isActive() && $assignment->isPrimary()) {
                return $assignment->getPhone()->getNumber();
            }
        }

        return null;
    }

    public function setPhone(?string $phone): self
    {
        $value = trim((string) $phone);
        foreach ($this->phones as $assignment) {
            if (!$assignment->isPrimary()) { continue; }
            if ($value === '') { $assignment->setIsActive(false); }
            else { $assignment->getPhone()->setNumber($value); $assignment->setIsActive(true); }
            return $this;
        }
        if ($value !== '') {
            $this->phones->add((new ClientPhone($this, new Phone('LANDLINE', $value)))->setLabel('General')->setIsPrimary(true));
        }

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $notes = trim((string) $notes);

        $this->notes = $notes !== '' ? $notes : null;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        $this->deletedAt = $isActive
            ? null
            : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

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

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
