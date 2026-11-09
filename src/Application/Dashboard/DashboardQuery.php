<?php

declare(strict_types=1);

namespace App\Application\Dashboard;

use App\Application\Production\ProductionBoardQuery;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Production\ProductionCalendarException;
use App\Entity\Quotations\Quotation;
use App\Entity\Users\User;
use App\Enum\Orders\ProductionOrderStatus;
use App\Enum\Orders\ServiceOrderDeliveryStatus;
use App\Enum\Production\ProductionCalendarExceptionType;
use App\Enum\Quotations\QuotationStatus;
use App\Repository\Orders\ServiceOrderItemRepository;
use App\Repository\Orders\ServiceOrderOperationPlanRepository;
use App\Repository\Orders\ServiceOrderTraceabilityEventRepository;
use App\Repository\Production\ProductionReservationRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Read-only dashboard projection. Each source is enabled only after the controller checks its permission. */
final class DashboardQuery
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ServiceOrderItemRepository $items,
        private readonly ServiceOrderOperationPlanRepository $plans,
        private readonly ProductionReservationRepository $reservations,
        private readonly ProductionBoardQuery $productionBoard,
        private readonly ServiceOrderTraceabilityEventRepository $events,
    ) {}

    /** @return array<string, mixed> */
    public function collect(User $user, bool $canOrders, bool $canSales, bool $canDesign, bool $canExecute, bool $canManageProduction, bool $canCalendar, bool $canDelivery): array
    {
        $zone = new \DateTimeZone('America/Mexico_City');
        $today = new \DateTimeImmutable('today', $zone);
        $end = $today->modify('+7 days');
        $data = ['today' => $today, 'orders' => null, 'sales' => null, 'design' => null, 'work' => null, 'production' => null, 'agenda' => []];

        if ($canManageProduction) {
            $board = $this->productionBoard->build($today, false);
            $data['production'] = ['summary' => $board['summary'], 'resources' => array_slice($board['resources'], 0, 5)];
        }

        if ($canOrders) {
            $orders = $this->em->getRepository(ServiceOrder::class);
            $base = static fn () => $orders->createQueryBuilder('o')
                ->andWhere('o.deliveryStatus != :delivered')->setParameter('delivered', ServiceOrderDeliveryStatus::DELIVERED->value)
                ->andWhere('o.productionStatus != :cancelled')->setParameter('cancelled', ProductionOrderStatus::CANCELLED->value);
            $overdueCount = (int) $base()->select('COUNT(o.id)')->andWhere('o.commitmentDate < :today')->setParameter('today', $today->format('Y-m-d'))->getQuery()->getSingleScalarResult();
            $todayCount = (int) $base()->select('COUNT(o.id)')->andWhere('o.commitmentDate = :today')->setParameter('today', $today->format('Y-m-d'))->getQuery()->getSingleScalarResult();
            $overdue = $base()->andWhere('o.commitmentDate < :today')->setParameter('today', $today->format('Y-m-d'))->orderBy('o.commitmentDate', 'ASC')->addOrderBy('o.id', 'ASC')->setMaxResults(5)->getQuery()->getResult();
            $upcoming = $base()->andWhere('o.commitmentDate >= :today AND o.commitmentDate < :end')->setParameter('today', $today->format('Y-m-d'))->setParameter('end', $end->format('Y-m-d'))->orderBy('o.commitmentDate', 'ASC')->addOrderBy('o.id', 'ASC')->setMaxResults(8)->getQuery()->getResult();
            $data['orders'] = ['overdue_count' => $overdueCount, 'today_count' => $todayCount, 'overdue' => $overdue, 'upcoming' => $upcoming];
            foreach ($upcoming as $order) {
                $data['agenda'][] = ['sort' => $order->getCommitmentDate()->format('Y-m-d').'T23:59:59', 'date' => $order->getCommitmentDate(), 'timed' => false, 'type' => 'Compromiso de entrega', 'title' => $order->getFolio(), 'detail' => $order->getProductionStatus()->label(), 'route' => $canDelivery && $order->getProductionStatus() === ProductionOrderStatus::PRODUCTION_COMPLETED ? 'admin_production_quality_delivery_delivery_show' : 'admin_service_orders_show', 'id' => $order->getId()];
            }
        }

        if ($canSales) {
            $quotes = $this->em->getRepository(Quotation::class);
            $pending = [QuotationStatus::REQUEST->value, QuotationStatus::IN_REVIEW->value, QuotationStatus::DRAFT->value];
            $awaiting = [QuotationStatus::ISSUED->value, QuotationStatus::SENT->value];
            $count = static fn (array $statuses): int => (int) $quotes->createQueryBuilder('q')->select('COUNT(q.id)')->andWhere('q.status IN (:states)')->setParameter('states', $statuses)->getQuery()->getSingleScalarResult();
            $rows = $quotes->createQueryBuilder('q')->leftJoin('q.client', 'c')->addSelect('c')->andWhere('q.status IN (:states)')->setParameter('states', array_merge($pending, $awaiting))->orderBy('q.updatedAt', 'ASC')->addOrderBy('q.id', 'ASC')->setMaxResults(6)->getQuery()->getResult();
            $data['sales'] = ['pending_count' => $count($pending), 'awaiting_count' => $count($awaiting), 'rows' => $rows];
        }

        if ($canDesign) {
            $items = $this->items->findForDesignQueue();
            $ids = array_values(array_unique(array_map(static fn ($item): int => (int) $item->getServiceOrder()->getId(), $items)));
            $priorities = $this->items->findHighestPrioritiesByServiceOrderIds($ids);
            usort($items, static function ($a, $b) use ($priorities): int {
                $pa = $priorities[$a->getServiceOrder()->getId()] ?? $a->getPriority();
                $pb = $priorities[$b->getServiceOrder()->getId()] ?? $b->getPriority();
                return ($pb->weight() <=> $pa->weight()) ?: ($a->getServiceOrder()->getCreatedAt() <=> $b->getServiceOrder()->getCreatedAt()) ?: ($a->getLineNumber() <=> $b->getLineNumber());
            });
            $counts = ['PENDING_REVIEW' => 0, 'IN_REVIEW' => 0, 'BLOCKED' => 0];
            foreach ($items as $item) { ++$counts[$item->getDesignStatus()->value]; }
            $data['design'] = ['counts' => $counts, 'rows' => array_slice($items, 0, 5), 'priorities' => $priorities];
        }

        if ($canExecute) {
            // Even a manager's personal queue must contain only their assignments.
            $plans = $this->plans->findForProductionQueue($user);
            $rows = [];
            foreach ($plans as $plan) {
                if ($plan->getConditionApplied() === false) { continue; }
                $order = $plan->getServiceOrderItem()->getServiceOrder();
                if ($plan->hasCompletedLatestExecution() && !$plan->hasOpenExecution()) {
                    // A completed attempt can be the origin of a new quality rework.
                    $origin = $order->getProductionStatus() === ProductionOrderStatus::QUALITY_REWORK
                        ? $this->events->findLatestQualityRejectionForPlan($plan)?->getOperationExecution()
                        : null;
                    if ($origin === null || $plan->getLatestExecution() !== $origin || $plan->hasExecutionOrigin($origin)) { continue; }
                } elseif ($order->getProductionStatus() === ProductionOrderStatus::QUALITY_REWORK && !$plan->hasOpenExecution()) {
                    continue;
                }
                $rows[] = $plan;
            }
            $data['work'] = ['count' => count($rows), 'rows' => array_slice($rows, 0, 5)];
        }

        if ($canManageProduction || $canExecute || $canDesign) {
            foreach ($this->reservations->findActiveForProductionQueue($today, $end) as $reservation) {
                $plan = $reservation->getPlan();
                if (!$plan->isActive() || $plan->getConditionApplied() === false || $plan->getServiceOrderItem()->getServiceOrder()->isCancelled()) { continue; }
                if (!$canManageProduction && $plan->getAssignedOperator()?->getId() !== $user->getId()) { continue; }
                $data['agenda'][] = ['sort' => $reservation->getStartsAt()->setTimezone($zone)->format('Y-m-d\TH:i:s'), 'date' => $reservation->getStartsAt(), 'timed' => true, 'type' => 'Reserva de producción', 'title' => $plan->getServiceOrderItem()->getServiceOrder()->getFolio(), 'detail' => $reservation->getEquipment()->getName().' · '.$plan->getOperation()->getName(), 'route' => 'admin_production_board_index', 'id' => null];
            }
        }
        if ($canCalendar) {
            $exceptions = $this->em->getRepository(ProductionCalendarException::class)->createQueryBuilder('e')->leftJoin('e.equipment', 'equipment')->addSelect('equipment')->andWhere('e.isActive = true AND e.type = :type AND e.startsAt < :end AND e.endsAt > :start')->setParameter('type', ProductionCalendarExceptionType::MAINTENANCE->value)->setParameter('start', $today->setTimezone(new \DateTimeZone('UTC')))->setParameter('end', $end->setTimezone(new \DateTimeZone('UTC')))->orderBy('e.startsAt', 'ASC')->setMaxResults(10)->getQuery()->getResult();
            foreach ($exceptions as $exception) {
                $data['agenda'][] = ['sort' => $exception->getStartsAt()->setTimezone($zone)->format('Y-m-d\TH:i:s'), 'date' => $exception->getStartsAt(), 'timed' => true, 'type' => 'Mantenimiento', 'title' => $exception->getEquipment()?->getName() ?? 'Jornada general', 'detail' => $exception->getReason(), 'route' => 'admin_production_calendar_index', 'id' => null];
            }
        }
        usort($data['agenda'], static fn (array $a, array $b): int => strcmp($a['sort'], $b['sort']));
        $data['agenda'] = array_slice($data['agenda'], 0, 8);
        return $data;
    }
}
