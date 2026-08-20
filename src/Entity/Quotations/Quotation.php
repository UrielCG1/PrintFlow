<?php

namespace App\Entity\Quotations;

use App\Entity\Clients\Client;
use App\Entity\Users\User;
use App\Enum\Quotations\QuotationResponseChannel;
use App\Enum\Quotations\QuotationStatus;
use App\Repository\Quotations\QuotationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QuotationRepository::class)]
#[ORM\Table(name: 'quotations')]
#[ORM\UniqueConstraint(name: 'uniq_quotations_folio', columns: ['folio'])]
#[ORM\UniqueConstraint(name: 'uniq_quotations_previous_revision', columns: ['previous_revision_id'])]
#[ORM\Index(name: 'idx_quotations_status_expires_at', columns: ['status', 'expires_at'])]
#[ORM\Index(name: 'idx_quotations_client_created_at', columns: ['client_id', 'created_at'])]
#[ORM\Index(name: 'idx_quotations_created_by_user', columns: ['created_by_user_id'])]
#[ORM\HasLifecycleCallbacks]
class Quotation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'client_id', nullable: false, onDelete: 'RESTRICT')]
    private Client $client;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_user_id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $createdBy = null;

    #[ORM\Column(length: 20, options: ['default' => 'INTERNAL'])]
    private string $origin = 'INTERNAL';

    #[ORM\Column(name: 'request_reference', length: 40, unique: true, nullable: true)]
    private ?string $requestReference = null;

    #[ORM\Column(name: 'requested_delivery_at', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $requestedDeliveryAt = null;

    #[ORM\Column(name: 'contact_preference', length: 20, nullable: true)]
    private ?string $contactPreference = null;

    #[ORM\Column(name: 'delivery_method', length: 30, nullable: true)]
    private ?string $deliveryMethod = null;

    #[ORM\Column(name: 'requires_invoice', options: ['default' => false])]
    private bool $requiresInvoice = false;

    #[ORM\Column(name: 'request_contact_name', length: 160, nullable: true)]
    private ?string $requestContactName = null;

    #[ORM\Column(name: 'request_email', length: 180, nullable: true)]
    private ?string $requestEmail = null;

    #[ORM\Column(name: 'request_phone', length: 30, nullable: true)]
    private ?string $requestPhone = null;

    #[ORM\Column(name: 'request_company_name', length: 180, nullable: true)]
    private ?string $requestCompanyName = null;

    #[ORM\Column(length: 20, enumType: QuotationStatus::class)]
    private QuotationStatus $status = QuotationStatus::DRAFT;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'revisions')]
    #[ORM\JoinColumn(name: 'previous_revision_id', nullable: true, onDelete: 'RESTRICT')]
    private ?self $previousRevision = null;

    #[ORM\Column(name: 'revision_number', options: ['unsigned' => true, 'default' => 1])]
    private int $revisionNumber = 1;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $folio = null;

    #[ORM\Column(name: 'acceptance_token', length: 64, unique: true, nullable: true)]
    private ?string $acceptanceToken = null;

    #[ORM\Column(name: 'acceptance_ip', length: 45, nullable: true)]
    private ?string $acceptanceIp = null;

    #[ORM\Column(name: 'accepted_folio_snapshot', length: 40, nullable: true)]
    private ?string $acceptedFolioSnapshot = null;

    #[ORM\Column(name: 'accepted_amount_snapshot', type: Types::DECIMAL, precision: 14, scale: 2, nullable: true)]
    private ?string $acceptedAmountSnapshot = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'acceptance_reviewed_by_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $acceptanceReviewedBy = null;

    #[ORM\Column(name: 'acceptance_reviewed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acceptanceReviewedAt = null;

    #[ORM\Column(name: 'expires_at', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'issued_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $issuedAt = null;

    #[ORM\Column(name: 'decision_channel', length: 20, enumType: QuotationResponseChannel::class, nullable: true)]
    private ?QuotationResponseChannel $decisionChannel = null;

    #[ORM\Column(name: 'decision_contact', length: 160, nullable: true)]
    private ?string $decisionContact = null;

    #[ORM\Column(name: 'decision_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decisionAt = null;

    #[ORM\Column(name: 'decision_notes', type: Types::TEXT, nullable: true)]
    private ?string $decisionNotes = null;

    #[ORM\Column(name: 'decision_evidence_reference', length: 500, nullable: true)]
    private ?string $decisionEvidenceReference = null;

    #[ORM\Column(name: 'purchase_order_number', length: 120, nullable: true)]
    private ?string $purchaseOrderNumber = null;

    #[ORM\Column(name: 'purchase_order_file', type: Types::JSON, nullable: true)]
    private ?array $purchaseOrderFile = null;

    #[ORM\Column(name: 'response_screenshot_file', type: Types::JSON, nullable: true)]
    private ?array $responseScreenshotFile = null;

    #[ORM\Column(length: 3, options: ['default' => 'MXN'])]
    private string $currency = 'MXN';

    #[ORM\Column(name: 'client_snapshot', type: Types::JSON)]
    private array $clientSnapshot = [];

    #[ORM\Column(name: 'fiscal_address_snapshot', type: Types::JSON, nullable: true)]
    private ?array $fiscalAddressSnapshot = null;

    #[ORM\Column(name: 'delivery_address_snapshot', type: Types::JSON, nullable: true)]
    private ?array $deliveryAddressSnapshot = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'discount_percent', type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $discountPercent = '0.00';

    #[ORM\Column(name: 'tax_rate', type: Types::DECIMAL, precision: 5, scale: 4)]
    private string $taxRate = '0.1600';

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $subtotal = '0.00';

    #[ORM\Column(name: 'discount_amount', type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $discountAmount = '0.00';

    #[ORM\Column(name: 'taxable_amount', type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $taxableAmount = '0.00';

    #[ORM\Column(name: 'tax_amount', type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $taxAmount = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $total = '0.00';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, QuotationItem>
     */
    #[ORM\OneToMany(
        mappedBy: 'quotation',
        targetEntity: QuotationItem::class,
        cascade: ['persist'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['lineNumber' => 'ASC'])]
    private Collection $items;

    /** @var Collection<int, QuotationEmailDispatch> */
    #[ORM\OneToMany(mappedBy: 'quotation', targetEntity: QuotationEmailDispatch::class)]
    #[ORM\OrderBy(['sentAt' => 'DESC', 'id' => 'DESC'])]
    private Collection $emailDispatches;

    /** @var Collection<int, Quotation> */
    #[ORM\OneToMany(mappedBy: 'previousRevision', targetEntity: self::class)]
    #[ORM\OrderBy(['revisionNumber' => 'ASC'])]
    private Collection $revisions;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->emailDispatches = new ArrayCollection();
        $this->revisions = new ArrayCollection();

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->acceptanceToken = bin2hex(random_bytes(32));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function setClient(Client $client): self
    {
        $this->client = $client;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(User $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getOrigin(): string { return $this->origin; }
    public function isPublicRequest(): bool { return $this->origin === 'PUBLIC'; }
    public function getRequestReference(): ?string { return $this->requestReference; }
    public function getRequestedDeliveryAt(): ?\DateTimeImmutable { return $this->requestedDeliveryAt; }
    public function getContactPreference(): ?string { return $this->contactPreference; }
    public function getDeliveryMethod(): ?string { return $this->deliveryMethod; }
    public function requiresInvoice(): bool { return $this->requiresInvoice; }
    public function getRequestContactName(): ?string { return $this->requestContactName; }
    public function getRequestEmail(): ?string { return $this->requestEmail; }
    public function getRequestPhone(): ?string { return $this->requestPhone; }
    public function getRequestCompanyName(): ?string { return $this->requestCompanyName; }

    public function initializePublicRequest(
        string $reference,
        string $contactName,
        string $email,
        string $phone,
        ?string $companyName,
        string $contactPreference,
        string $deliveryMethod,
        ?\DateTimeImmutable $requestedDeliveryAt,
        bool $requiresInvoice,
    ): self {
        $this->origin = 'PUBLIC';
        $this->status = QuotationStatus::REQUEST;
        $this->requestReference = strtoupper(trim($reference));
        $this->requestContactName = trim($contactName);
        $this->requestEmail = strtolower(trim($email));
        $this->requestPhone = trim($phone);
        $companyName = trim((string) $companyName);
        $this->requestCompanyName = $companyName !== '' ? $companyName : null;
        $this->contactPreference = $contactPreference;
        $this->deliveryMethod = $deliveryMethod;
        $this->requestedDeliveryAt = $requestedDeliveryAt;
        $this->requiresInvoice = $requiresInvoice;
        return $this;
    }

    public function startReview(): void
    {
        if ($this->status !== QuotationStatus::REQUEST) { throw new \DomainException('Solo una solicitud puede pasar a revisión.'); }
        $this->status = QuotationStatus::IN_REVIEW;
    }

    public function prepareDraft(): void
    {
        if (!in_array($this->status, [QuotationStatus::REQUEST, QuotationStatus::IN_REVIEW], true)) { throw new \DomainException('La cotización no puede pasar a borrador.'); }
        $this->status = QuotationStatus::DRAFT;
    }

    public function getStatus(): QuotationStatus
    {
        return $this->status;
    }

    public function getPreviousRevision(): ?self
    {
        return $this->previousRevision;
    }

    public function setPreviousRevision(?self $previousRevision): self
    {
        if ($previousRevision === $this) {
            throw new \InvalidArgumentException('Una cotización no puede ser revisión de sí misma.');
        }

        $this->previousRevision = $previousRevision;

        return $this;
    }

    public function getRevisionNumber(): int
    {
        return $this->revisionNumber;
    }

    public function setRevisionNumber(int $revisionNumber): self
    {
        if ($revisionNumber < 1) {
            throw new \InvalidArgumentException('El número de revisión debe ser mayor que cero.');
        }

        $this->revisionNumber = $revisionNumber;

        return $this;
    }

    /** @return Collection<int, Quotation> */
    public function getRevisions(): Collection
    {
        return $this->revisions;
    }
        
    public function getFolio(): ?string
    {
        return $this->folio;
    }

    public function getAcceptanceToken(): ?string { return $this->acceptanceToken; }
    public function ensureAcceptanceToken(): string { return $this->acceptanceToken ??= bin2hex(random_bytes(32)); }
    public function getAcceptanceIp(): ?string { return $this->acceptanceIp; }
    public function getAcceptedFolioSnapshot(): ?string { return $this->acceptedFolioSnapshot; }
    public function getAcceptedAmountSnapshot(): ?string { return $this->acceptedAmountSnapshot; }
    public function getAcceptanceReviewedBy(): ?User { return $this->acceptanceReviewedBy; }
    public function getAcceptanceReviewedAt(): ?\DateTimeImmutable { return $this->acceptanceReviewedAt; }
    public function markAcceptanceReviewedBy(User $user): self { $this->acceptanceReviewedBy = $user; $this->acceptanceReviewedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); return $this; }

    public function hasBeenIssued(): bool
    {
        return $this->folio !== null && $this->issuedAt !== null;
    }

    public function issue(string $folio, \DateTimeImmutable $issuedAt): void
    {
        if (!$this->isEditable()) {
            throw new \DomainException(
                'Solo una cotización en borrador puede emitirse.',
            );
        }

        $this->folio = self::normalizeIssuedFolio($folio);
        $this->issuedAt = $issuedAt->setTimezone(
            new \DateTimeZone('UTC'),
        );
        $this->status = QuotationStatus::ISSUED;
    }

    public function markSent(): void
    {
        if (!$this->status->canBeSent()) {
            throw new \DomainException('Solo una cotización emitida o enviada puede enviarse por correo.');
        }
        // El envío se conserva en quotation_email_dispatches; no es una etapa
        // del flujo comercial y por ello la cotización permanece EMITIDA.
    }

    public function accept(
        QuotationResponseChannel $channel,
        string $contact,
        \DateTimeImmutable $respondedAt,
        ?string $notes,
        ?string $evidenceReference,
        ?string $purchaseOrderNumber = null,
        ?array $purchaseOrderFile = null,
        ?array $responseScreenshotFile = null,
    ): void {
        $this->recordDecision(
            QuotationStatus::ACCEPTED,
            $channel,
            $contact,
            $respondedAt,
            $notes,
            $evidenceReference,
        );
        $this->purchaseOrderNumber = self::normalizeOptionalText($purchaseOrderNumber);
        $this->purchaseOrderFile = $purchaseOrderFile;
        $this->responseScreenshotFile = $responseScreenshotFile;
    }

    public function acceptWithChanges(string $contact, \DateTimeImmutable $respondedAt, string $notes, string $ip): void
    {
        $this->recordDecision(QuotationStatus::ACCEPTED_WITH_CHANGES, QuotationResponseChannel::EMAIL, $contact, $respondedAt, $notes, 'Aceptación mediante enlace web; IP: '.$ip);
        $this->acceptanceIp = $ip;
        $this->acceptedFolioSnapshot = $this->folio;
        $this->acceptedAmountSnapshot = $this->total;
    }

    public function acceptFromPublicLink(string $contact, \DateTimeImmutable $respondedAt, ?string $notes, string $ip): void
    {
        $notes = self::normalizeOptionalText($notes);
        if ($notes !== null) { $this->acceptWithChanges($contact, $respondedAt, $notes, $ip); return; }
        $this->recordDecision(QuotationStatus::ACCEPTED, QuotationResponseChannel::EMAIL, $contact, $respondedAt, null, 'Aceptación mediante enlace web; IP: '.$ip);
        $this->acceptanceIp = $ip;
        $this->acceptedFolioSnapshot = $this->folio;
        $this->acceptedAmountSnapshot = $this->total;
    }

    public function reject(
        QuotationResponseChannel $channel,
        string $contact,
        \DateTimeImmutable $respondedAt,
        ?string $notes,
        ?string $evidenceReference,
    ): void {
        $this->recordDecision(
            QuotationStatus::REJECTED,
            $channel,
            $contact,
            $respondedAt,
            $notes,
            $evidenceReference,
        );
    }

    public function cancel(string $reason, \DateTimeImmutable $cancelledAt): void
    {
        if (!$this->status->canReceiveDecision()) {
            throw new \DomainException('Solo una cotización emitida o enviada puede cancelarse.');
        }

        $reason = self::normalizeOptionalText($reason);
        if ($reason === null) {
            throw new \InvalidArgumentException('El motivo de cancelación es obligatorio.');
        }

        $this->status = QuotationStatus::CANCELLED;
        $this->decisionChannel = null;
        $this->decisionContact = null;
        $this->decisionAt = $cancelledAt->setTimezone(new \DateTimeZone('UTC'));
        $this->decisionNotes = $reason;
        $this->decisionEvidenceReference = null;
    }

    public function expire(\DateTimeImmutable $expiredAt): void
    {
        if (!$this->status->canReceiveDecision()) {
            throw new \DomainException('Solo una cotización emitida o enviada puede expirar.');
        }

        $this->status = QuotationStatus::EXPIRED;
        $this->decisionChannel = null;
        $this->decisionContact = null;
        $this->decisionAt = $expiredAt->setTimezone(new \DateTimeZone('UTC'));
        $this->decisionNotes = 'Vigencia concluida automáticamente.';
        $this->decisionEvidenceReference = null;
    }

    public function supersede(string $reason, \DateTimeImmutable $supersededAt): void
    {
        if (!$this->status->canBeRevised()) {
            throw new \DomainException('Esta cotización no puede reemplazarse por una nueva revisión.');
        }

        $reason = self::normalizeOptionalText($reason);
        if ($reason === null) {
            throw new \InvalidArgumentException('El motivo de la nueva revisión es obligatorio.');
        }

        $this->status = QuotationStatus::SUPERSEDED;
        $this->decisionChannel = null;
        $this->decisionContact = null;
        $this->decisionAt = $supersededAt->setTimezone(new \DateTimeZone('UTC'));
        $this->decisionNotes = $reason;
        $this->decisionEvidenceReference = null;
    }

    private static function normalizeIssuedFolio(string $folio): string
    {
        $folio = strtoupper(trim($folio));

        if (preg_match('/^[A-Z0-9-]{1,40}$/D', $folio) !== 1) {
            throw new \InvalidArgumentException(
                'El folio no tiene un formato válido.',
            );
        }

        return $folio;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getIssuedAt(): ?\DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function setIssuedAt(?\DateTimeImmutable $issuedAt): self
    {
        $this->issuedAt = $issuedAt;

        return $this;
    }

    public function getDecisionChannel(): ?QuotationResponseChannel
    {
        return $this->decisionChannel;
    }

    public function getDecisionContact(): ?string
    {
        return $this->decisionContact;
    }

    public function getDecisionAt(): ?\DateTimeImmutable
    {
        return $this->decisionAt;
    }

    public function getDecisionNotes(): ?string
    {
        return $this->decisionNotes;
    }

    public function getDecisionEvidenceReference(): ?string
    {
        return $this->decisionEvidenceReference;
    }

    public function getPurchaseOrderNumber(): ?string { return $this->purchaseOrderNumber; }

    /** @return array<string, int|string>|null */
    public function getPurchaseOrderFile(): ?array { return $this->purchaseOrderFile; }

    /** @return array<string, int|string>|null */
    public function getResponseScreenshotFile(): ?array { return $this->responseScreenshotFile; }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $currency = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new \InvalidArgumentException('La moneda debe usar un código ISO de tres letras.');
        }

        $this->currency = $currency;

        return $this;
    }

    public function getClientSnapshot(): array
    {
        return $this->clientSnapshot;
    }

    public function setClientSnapshot(array $clientSnapshot): self
    {
        $this->clientSnapshot = $clientSnapshot;

        return $this;
    }

    public function getFiscalAddressSnapshot(): ?array
    {
        return $this->fiscalAddressSnapshot;
    }

    public function setFiscalAddressSnapshot(?array $fiscalAddressSnapshot): self
    {
        $this->fiscalAddressSnapshot = $fiscalAddressSnapshot;

        return $this;
    }

    public function getDeliveryAddressSnapshot(): ?array
    {
        return $this->deliveryAddressSnapshot;
    }

    public function setDeliveryAddressSnapshot(?array $deliveryAddressSnapshot): self
    {
        $this->deliveryAddressSnapshot = $deliveryAddressSnapshot;

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

    public function getDiscountPercent(): string
    {
        return $this->discountPercent;
    }

    public function setDiscountPercent(string $discountPercent): self
    {
        $value = trim(str_replace(',', '.', $discountPercent));

        if (preg_match('/^(?:0|[1-9]\d{0,2})(?:\.\d{1,2})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException('El descuento no tiene un formato válido.');
        }

        [$integer, $decimal] = array_pad(explode('.', $value, 2), 2, '');

        if ((int) $integer > 100) {
            throw new \InvalidArgumentException('El descuento no puede ser mayor a 100 %.');
        }

        $this->discountPercent = $integer.'.'.str_pad($decimal, 2, '0');

        return $this;
    }

    public function getTaxRate(): string
    {
        return $this->taxRate;
    }

    public function setTaxRate(string $taxRate): self
    {
        $value = trim(str_replace(',', '.', $taxRate));

        if (preg_match('/^(?:0|1)(?:\.\d{1,4})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException('La tasa de impuesto no tiene un formato válido.');
        }

        [$integer, $decimal] = array_pad(explode('.', $value, 2), 2, '');

        if ($integer === '1' && trim($decimal, '0') !== '') {
            throw new \InvalidArgumentException('La tasa de impuesto no puede ser mayor a 1.');
        }

        $this->taxRate = $integer.'.'.str_pad($decimal, 4, '0');

        return $this;
    }

    public function getSubtotal(): string
    {
        return $this->subtotal;
    }

    public function getDiscountAmount(): string
    {
        return $this->discountAmount;
    }

    public function getTaxableAmount(): string
    {
        return $this->taxableAmount;
    }

    public function getTaxAmount(): string
    {
        return $this->taxAmount;
    }

    public function getTotal(): string
    {
        return $this->total;
    }

    public function setTotals(
        string $subtotal,
        string $discountAmount,
        string $taxableAmount,
        string $taxAmount,
        string $total,
    ): self {
        $this->subtotal = self::normalizeAmount($subtotal, 'El subtotal');
        $this->discountAmount = self::normalizeAmount($discountAmount, 'El descuento');
        $this->taxableAmount = self::normalizeAmount($taxableAmount, 'La base gravable');
        $this->taxAmount = self::normalizeAmount($taxAmount, 'El impuesto');
        $this->total = self::normalizeAmount($total, 'El total');

        return $this;
    }

    /**
     * @return Collection<int, QuotationItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(QuotationItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setQuotation($this);
        }

        return $this;
    }

    public function removeItem(QuotationItem $item): self
    {
        $this->items->removeElement($item);

        return $this;
    }

    /** @return Collection<int, QuotationEmailDispatch> */
    public function getEmailDispatches(): Collection
    {
        return $this->emailDispatches;
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public static function normalizeAmount(string $amount, string $field): string
    {
        $value = trim(str_replace(',', '.', $amount));

        if (preg_match('/^(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException($field.' no tiene un formato válido.');
        }

        [$integer, $decimal] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';

        return $integer.'.'.str_pad($decimal, 2, '0');
    }

    private function recordDecision(
        QuotationStatus $targetStatus,
        QuotationResponseChannel $channel,
        string $contact,
        \DateTimeImmutable $respondedAt,
        ?string $notes,
        ?string $evidenceReference,
    ): void {
        if (!$this->status->canReceiveDecision()) {
            throw new \DomainException('Solo una cotización emitida o enviada puede recibir una respuesta comercial.');
        }

        if (!in_array($targetStatus, [QuotationStatus::ACCEPTED, QuotationStatus::ACCEPTED_WITH_CHANGES, QuotationStatus::REJECTED], true)) {
            throw new \InvalidArgumentException('El estado de respuesta comercial no es válido.');
        }

        $contact = trim($contact);
        if ($contact === '') {
            throw new \InvalidArgumentException('El contacto que respondió es obligatorio.');
        }

        $notes = self::normalizeOptionalText($notes);
        $evidenceReference = self::normalizeOptionalText($evidenceReference);
        if ($notes === null && $evidenceReference === null) {
            throw new \InvalidArgumentException('Registra una observación o una referencia de evidencia de la respuesta.');
        }

        $this->status = $targetStatus;
        $this->decisionChannel = $channel;
        $this->decisionContact = $contact;
        $this->decisionAt = $respondedAt->setTimezone(new \DateTimeZone('UTC'));
        $this->decisionNotes = $notes;
        $this->decisionEvidenceReference = $evidenceReference;
    }

    private static function normalizeOptionalText(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
