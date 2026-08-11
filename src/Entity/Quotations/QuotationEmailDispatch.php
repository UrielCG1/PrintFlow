<?php

declare(strict_types=1);

namespace App\Entity\Quotations;

use App\Entity\Users\User;
use App\Repository\Quotations\QuotationEmailDispatchRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QuotationEmailDispatchRepository::class)]
#[ORM\Table(name: 'quotation_email_dispatches')]
#[ORM\Index(name: 'idx_quotation_email_dispatches_quotation_sent_at', columns: ['quotation_id', 'sent_at'])]
#[ORM\Index(name: 'idx_quotation_email_dispatches_actor', columns: ['sent_by_user_id'])]
class QuotationEmailDispatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'emailDispatches')]
    #[ORM\JoinColumn(name: 'quotation_id', nullable: false, onDelete: 'RESTRICT')]
    private Quotation $quotation;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'sent_by_user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $sentBy;

    #[ORM\Column(name: 'recipient_email', length: 180)]
    private string $recipientEmail;

    #[ORM\Column(name: 'recipient_name', length: 160, nullable: true)]
    private ?string $recipientName = null;

    #[ORM\Column(name: 'copy_email', length: 180, nullable: true)]
    private ?string $copyEmail = null;

    #[ORM\Column(length: 200)]
    private string $subject;

    #[ORM\Column(name: 'message_note', type: Types::TEXT, nullable: true)]
    private ?string $messageNote = null;

    #[ORM\Column(name: 'message_id', length: 255, nullable: true)]
    private ?string $messageId = null;

    #[ORM\Column(name: 'sent_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $sentAt;

    public function __construct(Quotation $quotation, User $sentBy)
    {
        $this->quotation = $quotation;
        $this->sentBy = $sentBy;
        $this->sentAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuotation(): Quotation
    {
        return $this->quotation;
    }

    public function getSentBy(): User
    {
        return $this->sentBy;
    }

    public function getRecipientEmail(): string
    {
        return $this->recipientEmail;
    }

    public function setRecipientEmail(string $recipientEmail): self
    {
        $this->recipientEmail = strtolower(trim($recipientEmail));

        return $this;
    }

    public function getRecipientName(): ?string
    {
        return $this->recipientName;
    }

    public function setRecipientName(?string $recipientName): self
    {
        $recipientName = trim((string) $recipientName);
        $this->recipientName = $recipientName !== '' ? $recipientName : null;

        return $this;
    }

    public function getCopyEmail(): ?string
    {
        return $this->copyEmail;
    }

    public function setCopyEmail(?string $copyEmail): self
    {
        $copyEmail = trim((string) $copyEmail);
        $this->copyEmail = $copyEmail !== '' ? strtolower($copyEmail) : null;

        return $this;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): self
    {
        $this->subject = trim($subject);

        return $this;
    }

    public function getMessageNote(): ?string
    {
        return $this->messageNote;
    }

    public function setMessageNote(?string $messageNote): self
    {
        $messageNote = trim((string) $messageNote);
        $this->messageNote = $messageNote !== '' ? $messageNote : null;

        return $this;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function setMessageId(?string $messageId): self
    {
        $messageId = trim((string) $messageId);
        $this->messageId = $messageId !== '' ? $messageId : null;

        return $this;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }
}
