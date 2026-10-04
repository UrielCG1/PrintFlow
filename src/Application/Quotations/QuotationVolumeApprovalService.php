<?php

declare(strict_types=1);

namespace App\Application\Quotations;

use App\Entity\Discounts\QuotationVolumeDiscountReview;
use App\Entity\Quotations\Quotation;
use App\Entity\Users\User;
use Doctrine\ORM\EntityManagerInterface;
use Brick\Math\BigDecimal;

final class QuotationVolumeApprovalService
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function assertApproved(Quotation $quotation): void
    {
        $hasVolume = array_filter($quotation->getDiscountBreakdown(), static fn (array $item): bool => ($item['type'] ?? null) === 'VOLUME' && BigDecimal::of((string) ($item['discount_amount'] ?? '0'))->compareTo('0') > 0) !== [];
        if (!$hasVolume || $quotation->getPricingEngineVersion() !== 'V2') { return; }
        $review = $this->currentReview($quotation);
        if (!$review || $review->getStatus() !== 'APPROVED') {
            throw new \DomainException('El descuento por volumen requiere aprobación administrativa antes de emitir, enviar o aceptar la cotización.');
        }
    }

    public function approve(Quotation $quotation, User $actor, ?string $notes = null): void
    {
        $review = $this->currentReview($quotation);
        if (!$review) throw new \DomainException('No existe una revisión de descuento por volumen pendiente.');
        if ($review->getStatus() !== 'PENDING') throw new \DomainException('La revisión actual ya fue procesada.');
        $review->setStatus('APPROVED')->setReviewedBy($actor)->setNotes($notes);
        $this->entityManager->flush();
    }

    public function reject(Quotation $quotation, User $actor, string $notes): void
    {
        if (trim($notes) === '') throw new \InvalidArgumentException('El motivo del rechazo es obligatorio.');
        $review = $this->currentReview($quotation);
        if (!$review) throw new \DomainException('No existe una revisión de descuento por volumen pendiente.');
        if ($review->getStatus() !== 'PENDING') throw new \DomainException('La revisión actual ya fue procesada.');
        $review->setStatus('REJECTED')->setReviewedBy($actor)->setNotes($notes);
        $this->entityManager->flush();
    }

    public function currentReview(Quotation $quotation): ?QuotationVolumeDiscountReview
    {
        return $this->entityManager->getRepository(QuotationVolumeDiscountReview::class)->createQueryBuilder('r')
            ->andWhere('r.quotation = :quotation')->andWhere('r.volumeCalculationVersion = :version')->andWhere('r.volumeHash = :hash')
            ->setParameter('quotation', $quotation)->setParameter('version', $quotation->getVolumeCalculationVersion())->setParameter('hash', $quotation->getVolumeHash())
            ->orderBy('r.reviewAttempt', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }
}
