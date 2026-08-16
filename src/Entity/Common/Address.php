<?php
declare(strict_types=1);

namespace App\Entity\Common;

use Doctrine\ORM\Mapping as ORM;

/** Domicilio reutilizable; la relación con clientes, proveedores, sucursales o contactos define su uso. */
#[ORM\Entity]
#[ORM\Table(name: 'addresses')]
#[ORM\HasLifecycleCallbacks]
class Address
{
    use Timestampable;

    /** Identificador interno del domicilio. */
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    /** Calle o vialidad. */
    #[ORM\Column(length: 160)] private string $street;
    /** Número exterior. */
    #[ORM\Column(name: 'exterior_number', length: 30)] private string $exteriorNumber;
    /** Número interior, local o departamento. */
    #[ORM\Column(name: 'interior_number', length: 30, nullable: true)] private ?string $interiorNumber = null;
    /** Colonia o asentamiento. */
    #[ORM\Column(length: 120, nullable: true)] private ?string $neighborhood = null;
    /** Código postal. */
    #[ORM\Column(name: 'postal_code', length: 10)] private string $postalCode;
    /** Ciudad, municipio o alcaldía. */
    #[ORM\Column(length: 120)] private string $city;
    /** Estado o entidad federativa. */
    #[ORM\Column(length: 120, nullable: true)] private ?string $state = null;
    /** Código ISO de dos letras del país. */
    #[ORM\Column(name: 'country_code', length: 2, options: ['default' => 'MX'])] private string $countryCode = 'MX';
    /** Referencias e indicaciones adicionales. */
    #[ORM\Column(type: 'text', nullable: true)] private ?string $notes = null;

    public function __construct(string $street, string $exteriorNumber, string $postalCode, string $city)
    {
        $this->street = trim($street); $this->exteriorNumber = trim($exteriorNumber);
        $this->postalCode = trim($postalCode); $this->city = trim($city); $this->initializeTimestamps();
    }
    public function getId(): ?int { return $this->id; }
    public function getStreet(): string { return $this->street; }
    public function setStreet(string $value): self { $this->street=trim($value); return $this; }
    public function getExteriorNumber(): string { return $this->exteriorNumber; }
    public function setExteriorNumber(string $value): self { $this->exteriorNumber=trim($value); return $this; }
    public function getInteriorNumber(): ?string { return $this->interiorNumber; }
    public function setInteriorNumber(?string $value): self { $value=trim((string)$value); $this->interiorNumber=$value?:null; return $this; }
    public function getNeighborhood(): ?string { return $this->neighborhood; }
    public function setNeighborhood(?string $value): self { $value=trim((string)$value); $this->neighborhood=$value?:null; return $this; }
    public function getPostalCode(): string { return $this->postalCode; }
    public function setPostalCode(string $value): self { $this->postalCode=trim($value); return $this; }
    public function getCity(): string { return $this->city; }
    public function setCity(string $value): self { $this->city=trim($value); return $this; }
    public function getState(): ?string { return $this->state; }
    public function setState(?string $value): self { $value=trim((string)$value); $this->state=$value?:null; return $this; }
    public function getCountryCode(): string { return $this->countryCode; }
    public function setCountryCode(string $value): self { $this->countryCode=strtoupper(trim($value)); return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $value): self { $value=trim((string)$value); $this->notes=$value?:null; return $this; }
}
