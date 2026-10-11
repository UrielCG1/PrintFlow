<?php

declare(strict_types=1);

namespace App\Repository\Production;

use App\Entity\Equipment\Equipment;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Production\ProductionReservation;
use App\Enum\Orders\ProductionEstimateKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ProductionReservation> */
final class ProductionReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, ProductionReservation::class); }

    /** @return list<ProductionReservation> */
    public function findActiveForEquipmentBetween(Equipment $equipment, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $utc = new \DateTimeZone('UTC');
        $start = $start->setTimezone($utc);
        $end = $end->setTimezone($utc);

        /** @var list<ProductionReservation> $rows */
        $rows = $this->createQueryBuilder('reservation')
            ->andWhere('reservation.equipment = :equipment')
            ->andWhere('reservation.isActive = true')
            ->andWhere('reservation.startsAt < :end AND reservation.endsAt > :start')
            ->setParameter('equipment', $equipment)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('reservation.startsAt', 'ASC')
            ->getQuery()->getResult();
        return $rows;
    }

    /** @return list<ProductionReservation> */
    public function findInProgressOverlapping(\DateTimeImmutable $start, \DateTimeImmutable $end, \DateTimeImmutable $now, ?Equipment $equipment = null): array
    {
        $utc = new \DateTimeZone('UTC');
        $start = $start->setTimezone($utc);
        $end = $end->setTimezone($utc);
        $now = $now->setTimezone($utc);

        /** @var list<ProductionReservation> $rows */
        $query = $this->createQueryBuilder('reservation')
            ->andWhere('reservation.isActive = true')
            ->andWhere('reservation.startsAt <= :now AND reservation.endsAt > :now')
            ->andWhere('reservation.startsAt < :end AND reservation.endsAt > :start')
            ->setParameter('now', $now)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('reservation.startsAt', 'ASC');
        if ($equipment !== null) {
            $query->andWhere('reservation.equipment = :equipment')->setParameter('equipment', $equipment);
        }
        $rows = $query->getQuery()->getResult();
        return $rows;
    }

    /** @return list<ProductionReservation> */
    public function findActiveForPlan(ServiceOrderOperationPlan $plan): array
    {
        /** @var list<ProductionReservation> $rows */
        $rows = $this->findBy(['plan' => $plan, 'isActive' => true], ['segmentNumber' => 'ASC']);
        return $rows;
    }

    public function hasActiveCalculatedForEquipment(Equipment $equipment, \DateTimeImmutable $now): bool
    {
        return $this->createQueryBuilder('reservation')
            ->select('reservation.id')
            ->innerJoin('reservation.plan', 'plan')
            ->andWhere('reservation.equipment = :equipment')
            ->andWhere('reservation.isActive = true')
            ->andWhere('reservation.endsAt > :now')
            ->andWhere('plan.estimateKind = :kind')
            ->setParameter('equipment', $equipment)
            ->setParameter('now', $now)
            ->setParameter('kind', ProductionEstimateKind::CALCULATED)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult() !== null;
    }

    /** @return list<ProductionReservation> */
    public function findActiveForServiceOrder(ServiceOrder $order): array
    {
        /** @var list<ProductionReservation> $rows */
        $rows = $this->createQueryBuilder('reservation')
            ->innerJoin('reservation.plan', 'plan')
            ->innerJoin('plan.serviceOrderItem', 'item')
            ->addSelect('plan', 'item')
            ->andWhere('item.serviceOrder = :order')
            ->andWhere('reservation.isActive = true')
            ->setParameter('order', $order)
            ->orderBy('reservation.startsAt', 'ASC')
            ->getQuery()->getResult();
        return $rows;
    }

    /** @return list<Equipment> */
    public function findEquipmentWithReservationsAfter(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $utc = new \DateTimeZone('UTC');
        $start = $start->setTimezone($utc);
        $end = $end->setTimezone($utc);

        /** @var list<Equipment> $equipment */
        $equipment = $this->createQueryBuilder('reservation')
            ->select('DISTINCT equipment')
            ->innerJoin('reservation.equipment', 'equipment')
            ->andWhere('reservation.isActive = true')
            ->andWhere('reservation.startsAt < :end AND reservation.endsAt > :start')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('equipment.id', 'ASC')
            ->getQuery()->getResult();
        return $equipment;
    }
}
