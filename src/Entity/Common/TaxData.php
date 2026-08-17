<?php
declare(strict_types=1);

namespace App\Entity\Common;

use App\Entity\Clients\Client;
use App\Entity\Suppliers\Supplier;
use Doctrine\ORM\Mapping as ORM;

/** Configuración fiscal de un cliente o proveedor sin duplicar su identidad. */
#[ORM\Entity]
#[ORM\Table(name: 'tax_data')]
#[ORM\HasLifecycleCallbacks]
class TaxData
{
    use Timestampable;

    /** Identificador interno. */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Cliente propietario; es excluyente con supplier. */
    #[ORM\ManyToOne(targetEntity: Client::class, inversedBy: 'taxData')]
    #[ORM\JoinColumn(name: 'client_id', nullable: true, onDelete: 'CASCADE')]
    private ?Client $client = null;

    /** Proveedor propietario; es excluyente con client. */
    #[ORM\ManyToOne(targetEntity: Supplier::class, inversedBy: 'taxData')]
    #[ORM\JoinColumn(name: 'supplier_id', nullable: true, onDelete: 'CASCADE')]
    private ?Supplier $supplier = null;

    /** Domicilio fiscal reutilizable; puede quedar pendiente durante el alta. */
    #[ORM\ManyToOne(targetEntity: Address::class)]
    #[ORM\JoinColumn(name: 'fiscal_address_id', nullable: true, onDelete: 'RESTRICT')]
    private ?Address $fiscalAddress = null;

    /** Clave SAT del régimen fiscal. */
    #[ORM\Column(name: 'tax_regime_code', length: 3, nullable: true)]
    private ?string $taxRegimeCode = null;

    /** Correo al que se envían los CFDI. */
    #[ORM\Column(name: 'billing_email', length: 180, nullable: true)]
    private ?string $billingEmail = null;

    /** Uso CFDI predeterminado. */
    #[ORM\Column(name: 'cfdi_use_code', length: 10, nullable: true)]
    private ?string $cfdiUseCode = null;

    /** Indica que es la configuración fiscal predeterminada. */
    #[ORM\Column(name: 'is_default', options: ['default' => false])]
    private bool $isDefault = false;

    /** Indica que la configuración está vigente. */
    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    private function __construct(
        ?Address $address,
        ?string $regime,
        ?string $email,
        ?string $cfdi,
    ) {
        $this->fiscalAddress = $address;
        $this->setTaxRegimeCode($regime);
        $this->setBillingEmail($email);
        $this->setCfdiUseCode($cfdi);
        $this->initializeTimestamps();
    }

    public static function forClient(Client $owner, Address $address, string $regime, string $email, string $cfdi): self
    {
        $self = new self($address, $regime, $email, $cfdi);
        $self->client = $owner;
        return $self;
    }

    public static function forSupplier(Supplier $owner, Address $address, string $regime, string $email, string $cfdi): self
    {
        $self = new self($address, $regime, $email, $cfdi);
        $self->supplier = $owner;
        return $self;
    }

    public static function draftForClient(Client $owner): self
    {
        $self = new self(null, null, null, null);
        $self->client = $owner;
        return $self;
    }
    public static function draftForSupplier(Supplier $owner): self { $self=new self(null,null,null,null);$self->supplier=$owner;return $self; }

    public function getId(): ?int { return $this->id; }
    public function getFiscalAddress(): ?Address { return $this->fiscalAddress; }
    public function setFiscalAddress(?Address $value): self { $this->fiscalAddress = $value; return $this; }
    public function getTaxRegimeCode(): ?string { return $this->taxRegimeCode; }
    public function setTaxRegimeCode(?string $value): self { $value=trim((string)$value); $this->taxRegimeCode=$value?:null; return $this; }
    public function getBillingEmail(): ?string { return $this->billingEmail; }
    public function setBillingEmail(?string $value): self { $value=trim((string)$value); $this->billingEmail=$value?strtolower($value):null; return $this; }
    public function getCfdiUseCode(): ?string { return $this->cfdiUseCode; }
    public function setCfdiUseCode(?string $value): self { $value=trim((string)$value); $this->cfdiUseCode=$value?strtoupper($value):null; return $this; }
    public function isDefault(): bool { return $this->isDefault; }
    public function setIsDefault(bool $value): self { $this->isDefault=$value; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $value): self { $this->isActive=$value; return $this; }
}
