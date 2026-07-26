<?php

namespace App\Entity\Clients;

use App\Repository\Clients\ClientContactRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClientContactRepository::class)]
#[ORM\Table(name: 'client_contacts')]
#[ORM\Index(name: 'idx_client_contacts_client_active', columns: ['client_id', 'is_active'])]
#[ORM\Index(name: 'idx_client_contacts_client_primary', columns: ['client_id', 'is_primary'])]
#[ORM\UniqueConstraint(name: 'uniq_client_contacts_primary_active', columns: ['primary_client_id'])]
#[ORM\HasLifecycleCallbacks]
class ClientContact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(name: 'client_id', nullable: false, onDelete: 'RESTRICT')]
    private Client $client;

    #[ORM\Column(name: 'full_name', length: 160)]
    private string $fullName;

    #[ORM\Column(name: 'job_title', length: 120, nullable: true)]
    private ?string $jobTitle = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(name: 'phone_extension', length: 15, nullable: true)]
    private ?string $phoneExtension = null;

    #[ORM\Column(name: 'mobile_phone', length: 40, nullable: true)]
    private ?string $mobilePhone = null;

    #[ORM\Column(name: 'personal_mobile_phone', length: 40, nullable: true)]
    private ?string $personalMobilePhone = null;

    #[ORM\Column(name: 'work_schedule', length: 160, nullable: true)]
    private ?string $workSchedule = null;

    #[ORM\Column(name: 'is_primary', options: ['default' => false])]
    private bool $isPrimary = false;

    #[ORM\Column(
        name: 'primary_client_id',
        type: 'integer',
        nullable: true,
        insertable: false,
        updatable: false,
        generated: 'ALWAYS',
        columnDefinition: 'INT GENERATED ALWAYS AS (CASE WHEN is_active = 1 AND is_primary = 1 THEN client_id ELSE NULL END) STORED',
    )]
    private ?int $primaryClientId = null;

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

    public function __construct(Client $client)
    {
        $this->client = $client;

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): self
    {
        $this->fullName = trim($fullName);

        return $this;
    }

    public function getJobTitle(): ?string
    {
        return $this->jobTitle;
    }

    public function setJobTitle(?string $jobTitle): self
    {
        $jobTitle = trim((string) $jobTitle);
        $this->jobTitle = $jobTitle !== '' ? $jobTitle : null;

        return $this;
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
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $phone = trim((string) $phone);
        $this->phone = $phone !== '' ? $phone : null;

        return $this;
    }

    public function getPhoneExtension(): ?string
    {
        return $this->phoneExtension;
    }

    public function setPhoneExtension(?string $phoneExtension): self
    {
        $phoneExtension = trim((string) $phoneExtension);
        $this->phoneExtension = $phoneExtension !== '' ? $phoneExtension : null;

        return $this;
    }

    public function getMobilePhone(): ?string
    {
        return $this->mobilePhone;
    }

    public function setMobilePhone(?string $mobilePhone): self
    {
        $mobilePhone = trim((string) $mobilePhone);
        $this->mobilePhone = $mobilePhone !== '' ? $mobilePhone : null;

        return $this;
    }

    public function getPersonalMobilePhone(): ?string
    {
        return $this->personalMobilePhone;
    }

    public function setPersonalMobilePhone(?string $personalMobilePhone): self
    {
        $personalMobilePhone = trim((string) $personalMobilePhone);
        $this->personalMobilePhone = $personalMobilePhone !== '' ? $personalMobilePhone : null;

        return $this;
    }

    public function getWorkSchedule(): ?string
    {
        return $this->workSchedule;
    }

    public function setWorkSchedule(?string $workSchedule): self
    {
        $workSchedule = trim((string) $workSchedule);
        $this->workSchedule = $workSchedule !== '' ? $workSchedule : null;

        return $this;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function setIsPrimary(bool $isPrimary): self
    {
        $this->isPrimary = $isPrimary;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        if (!$isActive) {
            $this->isPrimary = false;
        }

        return $this;
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}