<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\Quotations\Quotation;
use App\Entity\Quotations\QuotationStatusHistory;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::onFlush)]
final class QuotationStatusHistorySubscriber
{
    public function onFlush(OnFlushEventArgs $event): void
    {
        $entityManager = $event->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();
        $metadata = $entityManager->getClassMetadata(QuotationStatusHistory::class);

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if (!$entity instanceof Quotation) { continue; }
            $history = new QuotationStatusHistory($entity, null, $entity->getStatus()->value);
            $entityManager->persist($history);
            $unitOfWork->computeChangeSet($metadata, $history);
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Quotation) { continue; }
            $changeSet = $unitOfWork->getEntityChangeSet($entity);
            if (!isset($changeSet['status'])) { continue; }
            [$from, $to] = $changeSet['status'];
            $fromCode = $from instanceof \BackedEnum ? (string) $from->value : (string) $from;
            $toCode = $to instanceof \BackedEnum ? (string) $to->value : (string) $to;
            $history = new QuotationStatusHistory($entity, $fromCode, $toCode);
            $entityManager->persist($history);
            $unitOfWork->computeChangeSet($metadata, $history);
        }
    }
}
