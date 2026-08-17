<?php

namespace App\Entity\Suppliers;

use App\Entity\Common\{Address,Phone,TaxData};
use App\Repository\Suppliers\SupplierRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SupplierRepository::class)]
#[ORM\Table(name: 'suppliers')]
#[ORM\UniqueConstraint(name: 'uniq_suppliers_code', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'uniq_suppliers_tax_id', columns: ['tax_id'])]
#[ORM\Index(name: 'idx_suppliers_active_name', columns: ['is_active', 'business_name'])]
#[ORM\Index(name: 'idx_suppliers_deleted_at', columns: ['deleted_at'])]
#[ORM\HasLifecycleCallbacks]
class Supplier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    private string $code;

    #[ORM\Column(name: 'business_name', length: 160)]
    private string $businessName;

    #[ORM\Column(name: 'legal_name', length: 160, nullable: true)]
    private ?string $legalName = null;

    #[ORM\Column(name: 'tax_id', length: 20, nullable: true)]
    private ?string $taxId = null;

    /** Giro o actividad comercial principal del proveedor. */
    #[ORM\Column(name: 'business_activity', length: 160, nullable: true)]
    private ?string $businessActivity = null;

    /** Sitio web institucional. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    /** Teléfonos normalizados asignados al proveedor. @var Collection<int, SupplierPhone> */
    #[ORM\OneToMany(mappedBy: 'supplier', targetEntity: SupplierPhone::class, cascade: ['persist'])]
    private Collection $phones;
    #[ORM\OneToMany(mappedBy:'supplier',targetEntity:TaxData::class,cascade:['persist'])] private Collection $taxData;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

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

    #[ORM\Column(
        name: 'deleted_at',
        type: 'datetime_immutable',
        nullable: true,
        options: ['comment' => '(DC2Type:datetime_immutable)'],
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

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = strtoupper(trim($code));

        return $this;
    }

    public function getBusinessName(): string
    {
        return $this->businessName;
    }

    public function setBusinessName(string $businessName): self
    {
        $this->businessName = trim($businessName);

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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getBusinessActivity(): ?string { return $this->businessActivity; }
    public function setBusinessActivity(?string $value): self { $value=trim((string)$value);$this->businessActivity=$value?:null;return $this; }
    public function getWebsite(): ?string { return $this->website; }
    public function setWebsite(?string $value): self { $value=trim((string)$value);$this->website=$value?:null;return $this; }

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
            $this->phones->add((new SupplierPhone($this, new Phone('LANDLINE', $value)))->setLabel('General')->setIsPrimary(true));
        }

        return $this;
    }
    /** @return Collection<int,SupplierPhone> */ public function getPhones():Collection{return $this->phones;}
    private function defaultTaxData():?TaxData{foreach($this->taxData as $d){if($d->isActive()&&$d->isDefault())return $d;}foreach($this->taxData as $d){if($d->isActive())return $d;}return null;}
    private function taxDataForWrite():TaxData{$d=$this->defaultTaxData();if(!$d){$d=TaxData::draftForSupplier($this)->setIsDefault(true);$this->taxData->add($d);}return $d;}
    public function getTaxRegimeCode():?string{return $this->defaultTaxData()?->getTaxRegimeCode();} public function setTaxRegimeCode(?string $v):self{$this->taxDataForWrite()->setTaxRegimeCode($v);return $this;} public function getBillingEmail():?string{return $this->defaultTaxData()?->getBillingEmail();} public function setBillingEmail(?string $v):self{$this->taxDataForWrite()->setBillingEmail($v);return $this;} public function getDefaultCfdiUseCode():?string{return $this->defaultTaxData()?->getCfdiUseCode();} public function setDefaultCfdiUseCode(?string $v):self{$this->taxDataForWrite()->setCfdiUseCode($v);return $this;} public function setFiscalAddress(?Address $v):self{$this->taxDataForWrite()->setFiscalAddress($v);return $this;}

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
    public function refreshUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
