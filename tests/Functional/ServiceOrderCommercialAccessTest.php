<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Quotations\QuotationData;
use App\Application\Quotations\QuotationItemData;
use App\Application\Quotations\QuotationManager;
use App\Application\Orders\ServiceOrderManager;
use App\Application\Orders\ServiceOrderCancellationManager;
use App\Application\Inventory\InventoryCommitmentManager;
use App\Application\Inventory\InventoryReceiptCommand;
use App\Application\Inventory\InventoryReceiptLineInput;
use App\Application\Inventory\InventoryReceiptManager;
use App\Entity\Materials\Material;
use App\Entity\Materials\MaterialCategory;
use App\Entity\Materials\MaterialVariant;
use App\Application\Production\ProductionExecutionCommand;
use App\Application\Production\ProductionExecutionManager;
use App\Application\Production\ProductionPlanManager;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Operations\Operation;
use App\Entity\Operations\OperationArea;
use App\Entity\Users\Permission;
use App\Entity\Users\Role;
use App\Entity\Users\User;
use App\Enum\Quotations\QuotationResponseChannel;
use App\Tests\Support\IntegrationFixtures;
use App\Tests\Support\IsolatedKernel;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class ServiceOrderCommercialAccessTest extends TestCase
{
    private IsolatedKernel $kernel;
    private KernelBrowser $browser;
    private array $fixtures;
    private ServiceOrder $serviceOrder;
    private int $quotationId;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('Run PHP with -d extension=pdo_sqlite for isolated HTTP tests.');
        }

        $directory = dirname(__DIR__, 2).'/var/integration-qa/'.bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->kernel = new IsolatedKernel($directory);
        $this->kernel->boot();

        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $this->fixtures = IntegrationFixtures::seed($em);
        $this->serviceOrder = $this->createAcceptedOrder();
        $this->createUserWithPermissions('qa-production', 'ROLE_PRODUCTION', [
            'service_orders.view',
            'service_orders.plan',
            'production.assign',
            'service_orders.commitment.manage',
            'production_routes.manage',
            'production.priority.manage',
            'production.schedule.reprogram',
        ]);
        $this->createUserWithPermissions('qa-operator', 'ROLE_OPERATOR', [
            'service_orders.view',
        ]);
        $this->createUserWithPermissions('qa-sales', 'ROLE_SALES', [
            'service_orders.view',
            'service_orders.view_commercial',
            'service_orders.commitment.manage',
            'quotations.view',
            'quotations.create',
            'quotations.download_pdf',
        ]);

        $this->browser = new KernelBrowser($this->kernel);
    }

    protected function tearDown(): void
    {
        \Symfony\Component\Clock\Clock::set(new \Symfony\Component\Clock\NativeClock());
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
    }

    public function testProductionCanSeeOperationalOrderButNoCommercialAmountsOrSourceDocuments(): void
    {
        $this->assertOperationalAccessWithoutCommercialData('qa-production');
    }

    public function testCancellingAnUnstartedOrderIsTraceableAndCannotBeRepeated(): void
    {
        $services = $this->kernel->getContainer()->get('test.service_container');
        $manager = $services->get(ServiceOrderCancellationManager::class);

        $result = $manager->cancel($this->serviceOrder, 'Trabajo cancelado por el cliente antes de producir.', $this->fixtures['user']);

        self::assertSame([], $result->releasedCommitments);
        self::assertTrue($this->serviceOrder->isCancelled());
        self::assertFalse($this->serviceOrder->canCancelProduction());
        $this->expectException(\DomainException::class);
        $manager->cancel($this->serviceOrder, 'Intento de cancelación duplicado.', $this->fixtures['user']);
    }

    public function testCancellationReleasesPendingInventoryCommitmentAfterPhysicalReceipt(): void
    {
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $services = $this->kernel->getContainer()->get('test.service_container');
        $category = (new MaterialCategory())->setCode('QA-PILOT')->setName('Piloto inventario');
        $material = (new Material())
            ->setCategory($category)
            ->setMeasurementUnit($this->fixtures['area'])
            ->setCode('QA-PILOT-MATERIAL')
            ->setName('Material para ensayo de reservas');
        $variant = new MaterialVariant($material, 'QA-PILOT-MATERIAL-DEFAULT', $this->fixtures['area'], $this->fixtures['area'], $this->fixtures['area']);
        foreach ([$category, $material, $variant] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $services->get(InventoryReceiptManager::class)->receive(new InventoryReceiptCommand(
            idempotencyKey: 'qa-pilot-receipt-'.bin2hex(random_bytes(8)),
            lines: [new InventoryReceiptLineInput($variant, '10')],
            reference: 'QA-PILOT-001',
        ), $this->fixtures['user']);
        $commitment = $services->get(InventoryCommitmentManager::class)->commit(
            $this->serviceOrder,
            $variant,
            '6',
            $this->fixtures['user'],
        );
        self::assertSame('6.000000', $commitment->getPendingQuantity());

        $result = $services->get(ServiceOrderCancellationManager::class)->cancel(
            $this->serviceOrder,
            'Cliente canceló el trabajo antes de consumir el material.',
            $this->fixtures['user'],
        );
        $balance = $em->getConnection()->fetchAssociative(
            'SELECT on_hand_quantity, committed_quantity FROM material_variant_costs WHERE material_variant_id = ?',
            [$variant->getId()],
        );

        self::assertCount(1, $result->releasedCommitments);
        self::assertEquals(10, $balance['on_hand_quantity']);
        self::assertEquals(0, $balance['committed_quantity']);
        self::assertSame('0.000000', $commitment->getPendingQuantity());
        self::assertTrue($this->serviceOrder->isCancelled());
    }

    public function testOperatorCanSeeOperationalOrderButNoCommercialAmountsOrSourceDocuments(): void
    {
        $this->assertOperationalAccessWithoutCommercialData('qa-operator');
    }

    public function testRouteConfigurationIsLimitedToProductionManagers(): void
    {
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $operator = $em->getRepository(User::class)->findOneBy(['username' => 'qa-operator']);
        self::assertInstanceOf(User::class, $operator);
        $this->browser->loginUser($operator);
        $this->browser->request('GET', '/admin/produccion/rutas');
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());

        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $productionManager = $em->getRepository(User::class)->findOneBy(['username' => 'qa-production']);
        self::assertInstanceOf(User::class, $productionManager);
        $this->browser->loginUser($productionManager);
        $this->browser->request('GET', '/admin/produccion/rutas');
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $this->assertNoCommercialData((string) $this->browser->getResponse()->getContent(), 'production routes');
        $product = $this->fixtures['lona'];
        $this->browser->request('GET', '/admin/produccion/rutas/productos/'.$product->getId());
        self::assertSame(200, $this->browser->getResponse()->getStatusCode(), 'product route time standard form');
        self::assertStringContainsString('Minutos base por partida', (string) $this->browser->getResponse()->getContent());
        self::assertStringContainsString('Minutos por unidad', (string) $this->browser->getResponse()->getContent());
    }

    public function testProductionCanAssignAReservedOperationAfterPlanningButNotAfterItStarts(): void
    {
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $productionManager = $em->getRepository(User::class)->findOneBy(['username' => 'qa-production']);
        self::assertInstanceOf(User::class, $productionManager);
        $firstOperator = $em->getRepository(User::class)->findOneBy(['username' => 'qa-operator']);
        $operatorRole = $em->getRepository(Role::class)->findOneBy(['code' => 'ROLE_OPERATOR']);
        self::assertInstanceOf(User::class, $firstOperator);
        self::assertInstanceOf(Role::class, $operatorRole);
        $secondOperator = (new User())
            ->setUsername('qa-assigned-second')
            ->setFullName('Operador QA alterno')
            ->setEmail('qa-assigned-second@example.invalid')
            ->setMustChangePassword(false)
            ->setPassword('unused')
            ->addRole($operatorRole);
        $em->persist($secondOperator);
        $em->flush();
        $area = (new OperationArea())->setCode('QA-ASSIGN')->setName('Acabados QA');
        $operation = (new Operation())->setOperationArea($area)->setCode('QA-ASSIGN-OP')->setName('Acabado QA');
        $machine = (new \App\Entity\Equipment\Equipment())->setCode('QA-ASSIGN-EQ')->setName('Equipo QA')->setPrimaryOperation($operation);
        $item = $this->serviceOrder->getItems()->first();
        self::assertInstanceOf(ServiceOrderItem::class, $item);
        $plan = (new ServiceOrderOperationPlan())
            ->setSequenceNumber(1)
            ->setOperation($operation)
            ->setCreatedBy($productionManager)
            ->setOperationSnapshot(['code' => 'QA-ASSIGN-OP', 'name' => 'Acabado QA'])
            ->setEquipment($machine, ['name' => 'Equipo QA']);
        $item->addOperationPlan($plan);
        $start = new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC'));
        $reservation = (new \App\Entity\Production\ProductionReservation())
            ->setPlan($plan)
            ->setEquipment($machine)
            ->setCreatedBy($productionManager)
            ->setSegmentNumber(1)
            ->setStartsAt($start)
            ->setEndsAt($start->modify('+30 minutes'));
        $plan->addReservation($reservation);
        foreach ([$area, $operation, $machine, $reservation] as $entity) { $em->persist($entity); }
        $em->flush();

        $manager = $this->kernel->getContainer()->get('test.service_container')->get(ProductionPlanManager::class);
        $manager->assignOperator($plan, $firstOperator, $productionManager);
        $this->serviceOrder->setCommitmentDate(new \DateTimeImmutable('+3 days'))->markPlanned($productionManager);
        $manager->assignOperator($plan, $secondOperator, $productionManager);
        self::assertSame($secondOperator, $plan->getAssignedOperator());

        $execution = new \App\Entity\Production\ProductionOperationExecution($plan, $secondOperator, $productionManager, 1);
        $plan->addExecution($execution);
        $em->persist($execution);
        $em->flush();

        try {
            $manager->assignOperator($plan, $firstOperator, $productionManager);
            self::fail('A started operation must not be reassigned.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('después de iniciar', $exception->getMessage());
        }
    }

    public function testOperatorCannotAccessBulkAssignmentEndpoint(): void
    {
        $operator = $this->kernel->getContainer()->get('doctrine')->getManager()
            ->getRepository(User::class)->findOneBy(['username' => 'qa-operator']);
        self::assertInstanceOf(User::class, $operator);
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $area = (new OperationArea())->setCode('QA-ASSIGN-DENIED')->setName('Acabados QA');
        $operation = (new Operation())->setOperationArea($area)->setCode('QA-ASSIGN-DENIED-OP')->setName('Acabado QA');
        $item = $this->serviceOrder->getItems()->first();
        self::assertInstanceOf(ServiceOrderItem::class, $item);
        $plan = (new ServiceOrderOperationPlan())
            ->setSequenceNumber(1)
            ->setOperation($operation)
            ->setCreatedBy($this->fixtures['user'])
            ->setOperationSnapshot(['code' => 'QA-ASSIGN-DENIED-OP', 'name' => 'Acabado QA']);
        $item->addOperationPlan($plan);
        $em->persist($area);
        $em->persist($operation);
        $em->flush();

        $this->browser->loginUser($operator);
        $this->browser->request('POST', '/admin/ordenes-servicio/'.$this->serviceOrder->getId().'/operadores/asignar-pendientes', [
            '_token' => 'invalid',
            'operator_id' => '1',
        ]);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());
        $this->browser->request('POST', '/admin/ordenes-servicio/'.$this->serviceOrder->getId().'/operaciones/'.$plan->getId().'/operador', [
            '_token' => 'invalid',
            'operator_id' => '1',
        ]);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());
    }

    public function testExecutionQualityAndDeliveryViewsDoNotExposeCommercialAmounts(): void
    {
        $shopfloor = $this->createUserWithPermissions('qa-shopfloor', 'ROLE_QA_SHOPFLOOR', [
            'production.view', 'production.execute', 'service_orders.view',
        ]);
        $quality = $this->createUserWithPermissions('qa-quality', 'ROLE_QA_QUALITY', [
            'quality.review', 'quality.return', 'quality.release', 'service_orders.view',
        ]);
        $delivery = $this->createUserWithPermissions('qa-delivery', 'ROLE_QA_DELIVERY', [
            'delivery.ship', 'delivery.confirm', 'service_orders.view',
        ]);
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $area = (new OperationArea())->setCode('QA-FINISH')->setName('Acabados QA');
        $operation = (new Operation())->setOperationArea($area)->setCode('QA-LAMINADO')->setName('Laminado QA');
        $plan = (new ServiceOrderOperationPlan())
            ->setSequenceNumber(1)
            ->setOperation($operation)
            ->setCreatedBy($this->fixtures['user'])
            ->setOperationSnapshot(['code' => 'QA-LAMINADO', 'name' => 'Laminado QA', 'area_name' => 'Acabados QA'])
            ->assignOperator($shopfloor);
        $item = $this->serviceOrder->getItems()->first();
        self::assertInstanceOf(ServiceOrderItem::class, $item);
        $item->addOperationPlan($plan);
        $this->serviceOrder
            ->setCommitmentDate(new \DateTimeImmutable('2026-10-16'))
            ->markPlanned($this->fixtures['user']);
        $em->persist($area);
        $em->persist($operation);
        $em->flush();

        foreach ([
            [$shopfloor, '/admin/produccion/ejecucion', 'execution queue'],
            [$quality, '/admin/produccion/calidad', 'quality queue'],
            [$delivery, '/admin/produccion/entregas', 'delivery queue'],
        ] as [$user, $path, $context]) {
            $this->browser->loginUser($user);
            $this->browser->request('GET', $path);
            self::assertSame(200, $this->browser->getResponse()->getStatusCode(), $path);
            $html = (string) $this->browser->getResponse()->getContent();
            $this->assertNoCommercialData($html, $context);
            self::assertStringNotContainsString('Total contratado', $html, $context);
            self::assertStringNotContainsString('P. unitario', $html, $context);
            if ($context === 'execution queue') {
                self::assertStringContainsString('Laminado QA', $html);
            }
        }

        $this->browser->loginUser($shopfloor);
        foreach ([
            '/admin/produccion/calidad',
            '/admin/produccion/entregas',
            '/admin/produccion/calidad/ordenes/'.$this->serviceOrder->getId().'/liberar',
        ] as $path) {
            $this->browser->request(str_ends_with($path, '/liberar') ? 'POST' : 'GET', $path);
            self::assertSame(403, $this->browser->getResponse()->getStatusCode(), $path);
            $this->assertNoCommercialData((string) $this->browser->getResponse()->getContent(), $path);
        }
    }

    public function testDesignReviewShowsPlanningDependencyBeforeOrderIsReady(): void
    {
        $designer = $this->createUserWithPermissions('qa-designer-pending', 'ROLE_QA_DESIGNER_PENDING', [
            'production.view', 'production.design.review', 'service_orders.view',
        ]);
        $item = $this->serviceOrder->getItems()->first();
        self::assertInstanceOf(ServiceOrderItem::class, $item);

        $this->browser->loginUser($designer);
        $this->browser->request('GET', '/admin/diseno/partidas/'.$item->getId());
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $html = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('Pendiente de planificación', $html);
        self::assertStringNotContainsString('Iniciar revisión de Diseño', $html);
        $this->assertNoCommercialData($html, 'design detail pending planning');
    }

    public function testPauseFreesMachineAndSuspendsDependenciesThenResumesOnlyRemainingTime(): void
    {
        $clock = new \Symfony\Component\Clock\MockClock('2026-10-12 08:00:00 America/Mexico_City');
        \Symfony\Component\Clock\Clock::set($clock);
        [$target, $next, $other, $pinned, $operator] = $this->createSchedulingScenario();
        $services = $this->kernel->getContainer()->get('test.service_container');
        $manager = $services->get(ProductionExecutionManager::class);
        $execution = $manager->start($target, $operator, $this->productionCommand());
        $clock->modify('+30 minutes');
        $pauseCommand = $this->productionCommand('Se requiere corregir el archivo.');
        $manager->pause($execution, $operator, $pauseCommand);
        $forecast = $services->get(\App\Application\Production\ProductionCommitmentForecaster::class)->forOrder($this->serviceOrder);
        self::assertNull($forecast['order']['production_finish']);
        self::assertStringContainsString('pausada o bloqueada', $forecast['order']['risk_cause']);
        self::assertSame(1800, $execution->getActiveSeconds());
        self::assertSame([], $this->futureReservations($target, $clock->now()));
        self::assertSame([], $this->futureReservations($next, $clock->now()));
        self::assertSame('08:30', $this->futureReservations($other, $clock->now())[0]->getStartsAt()->setTimezone(new \DateTimeZone('America/Mexico_City'))->format('H:i'));
        self::assertSame('12:00', $this->futureReservations($pinned, $clock->now())[0]->getStartsAt()->setTimezone(new \DateTimeZone('America/Mexico_City'))->format('H:i'));
        $connection = $this->kernel->getContainer()->get('doctrine')->getConnection();
        self::assertSame('2026-10-12 22:00:00', $connection->fetchOne("SELECT r.starts_at FROM production_reservations r JOIN equipment e ON e.id = r.equipment_id WHERE e.code = 'QA-EQ-UNRELATED' AND r.is_active = 1"), 'Una máquina de otra partida no se reorganiza por esta pausa.');
        $count = $target->getReservations()->count() + $other->getReservations()->count();
        $manager->pause($execution, $operator, $pauseCommand);
        self::assertSame($count, $target->getReservations()->count() + $other->getReservations()->count(), 'El reintento HTTP no vuelve a mover reservas.');
        $incidents = $services->get(\App\Application\Production\ProductionIncidentManager::class);
        $incidents->open($target, $operator, $this->productionCommand('Se solicita el archivo corregido.'));
        $incidents->resolve($target, $operator, $this->productionCommand('El archivo corregido fue recibido.'));
        self::assertSame('PAUSED', $execution->getStatus()->value);
        self::assertSame([], $this->futureReservations($target, $clock->now()), 'Resolver el bloqueo conserva la pausa hasta que el operador reanude.');

        $clock->modify('+75 minutes');
        $manager->resume($execution, $operator, $this->productionCommand());
        $rows = $this->futureReservations($target, $clock->now());
        self::assertSame(5400, array_sum(array_map(static fn ($row): int => $row->getEndsAt()->getTimestamp() - $row->getStartsAt()->getTimestamp(), $rows)));
        self::assertSame('09:45', $rows[0]->getStartsAt()->setTimezone(new \DateTimeZone('America/Mexico_City'))->format('H:i'));
        $nextRows = $this->futureReservations($next, $clock->now());
        self::assertGreaterThanOrEqual($rows[array_key_last($rows)]->getEndsAt(), $nextRows[0]->getStartsAt());
        $forecast = $services->get(\App\Application\Production\ProductionCommitmentForecaster::class)->forOrder($this->serviceOrder);
        self::assertNotNull($forecast['order']['production_finish']);
        $this->assertNoMachineOverlaps();
        self::assertTrue($target->getReservations()->count() > 1, 'La agenda anterior se conserva.');
    }

    public function testIncidentResolutionRestoresReservationsAndForecastWithoutRestartingAnAttempt(): void
    {
        $clock = new \Symfony\Component\Clock\MockClock('2026-10-12 08:00:00 America/Mexico_City');
        \Symfony\Component\Clock\Clock::set($clock);
        [$target, $next, $other, $pinned, $operator] = $this->createSchedulingScenario();
        $services = $this->kernel->getContainer()->get('test.service_container');
        $manager = $services->get(\App\Application\Production\ProductionIncidentManager::class);
        $manager->open($target, $operator, $this->productionCommand('Falta confirmar la especificación.'));
        self::assertSame([], $this->futureReservations($target, $clock->now()));
        self::assertSame([], $this->futureReservations($next, $clock->now()));
        self::assertNull($services->get(\App\Application\Production\ProductionCommitmentForecaster::class)->forOrder($this->serviceOrder)['order']['production_finish']);
        $clock->modify('+1 hour');
        $command = $this->productionCommand('La especificación quedó confirmada.');
        $manager->resolve($target, $operator, $command);
        self::assertNull($target->getLatestExecution(), 'Resolver una incidencia no inicia trabajo automáticamente.');
        self::assertNotEmpty($this->futureReservations($target, $clock->now()));
        self::assertNotEmpty($this->futureReservations($next, $clock->now()));
        $forecast = $services->get(\App\Application\Production\ProductionCommitmentForecaster::class)->forOrder($this->serviceOrder)['order'];
        self::assertNotNull($forecast['production_finish']);
        self::assertSame('2026-10-15', $forecast['suggested_delivery']->format('Y-m-d'));
        $count = $target->getReservations()->count();
        $manager->resolve($target, $operator, $command);
        self::assertSame($count, $target->getReservations()->count());
        $this->assertNoMachineOverlaps();
    }

    public function testBusyMachineRejectsResumptionAndKeepsThePausedAttemptAndAgenda(): void
    {
        $clock = new \Symfony\Component\Clock\MockClock('2026-10-12 08:00:00 America/Mexico_City');
        \Symfony\Component\Clock\Clock::set($clock);
        [$target, $next, $other, $pinned, $operator] = $this->createSchedulingScenario();
        $manager = $this->kernel->getContainer()->get('test.service_container')->get(ProductionExecutionManager::class);
        $execution = $manager->start($target, $operator, $this->productionCommand());
        $manager->pause($execution, $operator, $this->productionCommand('Esperando autorización del archivo.'));
        $manager->start($other, $operator, $this->productionCommand());
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $connection = $em->getConnection();
        $before = $connection->fetchAllAssociative('SELECT * FROM production_reservations ORDER BY id');
        try {
            $manager->resume($execution, $operator, $this->productionCommand());
            self::fail('No debe interrumpir otra máquina activa.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('ejecutando otro trabajo', $exception->getMessage());
        }
        self::assertSame('PAUSED', $connection->fetchOne('SELECT status FROM production_operation_executions WHERE id = ?', [$execution->getId()]));
        self::assertSame($before, $connection->fetchAllAssociative('SELECT * FROM production_reservations ORDER BY id'));
    }

    public function testBootstrapTransfersDeliveryConfirmationToSalesAndHttpEnforcesIt(): void
    {
        $quality = $this->createUserWithPermissions('qa-quality-managed', 'ROLE_QUALITY_DELIVERY', ['delivery.confirm', 'delivery.ship', 'quality.review']);
        $services = $this->kernel->getContainer()->get('test.service_container');
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($this->kernel);
        $command = new \Symfony\Component\Console\Tester\CommandTester($application->find('app:security:bootstrap'));
        self::assertSame(0, $command->execute([]));
        self::assertSame(0, $command->execute([]));
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $salesRole = $em->getRepository(Role::class)->findOneBy(['code' => 'ROLE_SALES']);
        $qualityRole = $em->getRepository(Role::class)->findOneBy(['code' => 'ROLE_QUALITY_DELIVERY']);
        self::assertTrue($salesRole->hasPermission('delivery.confirm'));
        self::assertFalse($qualityRole->hasPermission('delivery.confirm'));
        self::assertTrue($qualityRole->hasPermission('delivery.ship'));
        self::assertTrue($em->getRepository(Role::class)->findOneBy(['code' => 'ROLE_ADMIN'])->hasPermission('equipment.create'));
        $sales = $em->getRepository(User::class)->findOneBy(['username' => 'qa-sales']);
        $this->serviceOrder->setCommitmentDate(new \DateTimeImmutable('2026-10-16'))->markPlanned($this->fixtures['user'])->beginProduction()->waitForQualityReview()->markProductionCompleted();
        $this->serviceOrder->setFulfillmentMethod(\App\Enum\Orders\ServiceOrderFulfillmentMethod::PICKUP)->markReadyForPickup();
        $em->flush();
        $path = '/admin/produccion/entregas/ordenes/'.$this->serviceOrder->getId();
        $this->browser->loginUser($sales);
        $crawler = $this->browser->request('GET', $path);
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $form = $crawler->filter('form[action="'.$path.'/confirmar"]')->form();
        $payload = $form->getPhpValues();
        $this->browser->loginUser($quality);
        $this->browser->request('POST', $path.'/confirmar', $payload);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());
        $this->browser->request('GET', $path);
        $this->assertNoCommercialData((string) $this->browser->getResponse()->getContent(), 'quality delivery observation');
        self::assertStringNotContainsString('action="'.$path.'/confirmar"', (string) $this->browser->getResponse()->getContent());
        $this->browser->loginUser($sales);
        $this->browser->request('POST', $path.'/confirmar', $payload);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        self::assertSame('DELIVERED', $em->getConnection()->fetchOne('SELECT delivery_status FROM service_orders WHERE id = ?', [$this->serviceOrder->getId()]));
    }

    public function testPinnedReservationRejectsImmediateResumptionWithoutPartialChanges(): void
    {
        $clock = new \Symfony\Component\Clock\MockClock('2026-10-12 08:00:00 America/Mexico_City');
        \Symfony\Component\Clock\Clock::set($clock);
        [$target, $next, $other, $pinned, $operator] = $this->createSchedulingScenario();
        $manager = $this->kernel->getContainer()->get('test.service_container')->get(ProductionExecutionManager::class);
        $execution = $manager->start($target, $operator, $this->productionCommand());
        $manager->pause($execution, $operator, $this->productionCommand('Esperando autorización del archivo.'));
        $pinned->getReservations()->first()->reschedule($clock->now(), $clock->now()->modify('+1 hour'));
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $em->flush();
        $connection = $em->getConnection();
        $before = $connection->fetchAllAssociative('SELECT * FROM production_reservations ORDER BY id');
        try {
            $manager->resume($execution, $operator, $this->productionCommand());
            self::fail('No debe desplazar la reserva fijada para reanudar inmediatamente.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('no puede reanudarse ahora', $exception->getMessage());
        }
        self::assertSame('PAUSED', $connection->fetchOne('SELECT status FROM production_operation_executions WHERE id = ?', [$execution->getId()]));
        self::assertSame($before, $connection->fetchAllAssociative('SELECT * FROM production_reservations ORDER BY id'));
    }

    public function testManualStationsCommandCreatesCompatibleEquipmentOnceWithoutInventingCapacity(): void
    {
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        foreach (['PRE-DISENO' => 'Preprensa', 'ACA-ENROLLADO' => 'Acabados'] as $code => $name) {
            $area = (new OperationArea())->setCode($name)->setName($name);
            $operation = (new Operation())->setCode($code)->setName($name)->setOperationArea($area);
            $em->persist($area);
            $em->persist($operation);
        }
        $em->flush();
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($this->kernel);
        $command = new \Symfony\Component\Console\Tester\CommandTester($application->find('app:equipment:ensure-manual-stations'));
        self::assertSame(0, $command->execute(['--actor' => 'qa-local', '--dry-run' => true]));
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM equipment'));
        self::assertSame(0, $command->execute(['--actor' => 'qa-local']));
        self::assertSame(0, $command->execute(['--actor' => 'qa-local']));
        self::assertSame(2, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM equipment'));
        foreach (['EQ-PRE-MESA-01' => 'PRE-DISENO', 'EQ-ACA-ESTACION-01' => 'ACA-ENROLLADO'] as $code => $operationCode) {
            $equipment = $em->getRepository(\App\Entity\Equipment\Equipment::class)->findOneBy(['code' => $code]);
            self::assertSame($operationCode, $equipment->getPrimaryOperation()->getCode());
            self::assertNull($equipment->getCapacityRate());
            self::assertNull($equipment->getCapacityValidatedAt());
            self::assertTrue($equipment->isSelectableForFutureExecution());
        }
    }

    private function productionCommand(?string $note = null): ProductionExecutionCommand
    {
        return new ProductionExecutionCommand(bin2hex(random_bytes(16)), $this->serviceOrder->getTraceabilityVersion(), $note);
    }

    /** @return array{ServiceOrderOperationPlan, ServiceOrderOperationPlan, ServiceOrderOperationPlan, ServiceOrderOperationPlan, User} */
    private function createSchedulingScenario(): array
    {
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $operator = $this->createUserWithPermissions('qa-scheduled-worker', 'ROLE_QA_SCHEDULED', ['production.view', 'production.execute']);
        $area = (new OperationArea())->setCode('QA-SCHEDULE')->setName('Acabados QA');
        $operation = (new Operation())->setOperationArea($area)->setCode('QA-FINISH')->setName('Acabados QA');
        $secondOperation = (new Operation())->setOperationArea($area)->setCode('QA-FINISH-2')->setName('Acabado posterior QA');
        $unrelatedOperation = (new Operation())->setOperationArea($area)->setCode('QA-UNRELATED')->setName('Trabajo independiente QA');
        $firstMachine = (new \App\Entity\Equipment\Equipment())->setCode('QA-EQ-1')->setName('Equipo QA 1')->setPrimaryOperation($operation);
        $secondMachine = (new \App\Entity\Equipment\Equipment())->setCode('QA-EQ-2')->setName('Equipo QA 2')->setPrimaryOperation($secondOperation);
        $unrelatedMachine = (new \App\Entity\Equipment\Equipment())->setCode('QA-EQ-UNRELATED')->setName('Equipo independiente QA')
            ->setPrimaryOperation($unrelatedOperation)->setStatus(\App\Enum\Equipment\EquipmentStatus::INACTIVE);
        foreach ([$area, $operation, $secondOperation, $unrelatedOperation, $firstMachine, $secondMachine, $unrelatedMachine] as $entity) { $em->persist($entity); }
        $firstItem = $this->serviceOrder->getItems()->first();
        $firstItem->beginDesignReview()->markReadyForPrint();
        $items = [$firstItem];
        foreach ([2, 3, 4] as $number) {
            $item = (new ServiceOrderItem())->setCommercialItem($this->fixtures['lona'])->setLineNumber($number)
                ->setTrackingCode('QA-SCHED-'.$number)->setQuantity('1')->setUnitPrice('100')->setLineSubtotal('100')
                ->setCommercialItemSnapshot(['name' => 'Trabajo QA '.$number]);
            $item->beginDesignReview()->markReadyForPrint();
            $this->serviceOrder->addItem($item);
            $items[] = $item;
        }
        $specs = [[$items[0], 1, $firstMachine, 120, '08:00'], [$items[0], 2, $secondMachine, 60, '10:00'],
            [$items[1], 1, $firstMachine, 60, '10:00'], [$items[2], 1, $firstMachine, 60, '12:00'],
            [$items[3], 1, $unrelatedMachine, 60, '16:00']];
        $plans = [];
        foreach ($specs as [$item, $sequence, $machine, $minutes, $hour]) {
            $plan = (new ServiceOrderOperationPlan())->setSequenceNumber($sequence)->setOperation($machine->getPrimaryOperation())
                ->setCreatedBy($this->fixtures['user'])->setOperationSnapshot(['code' => 'QA-FINISH', 'name' => 'Acabados QA'])
                ->setEquipment($machine, ['name' => $machine->getName()])->assignOperator($operator)
                ->setManualEstimate(null, null, $minutes, 'Duración de prueba operativa.', [], $operator);
            $item->addOperationPlan($plan);
            $start = new \DateTimeImmutable('2026-10-12 '.$hour, new \DateTimeZone('America/Mexico_City'));
            $row = (new \App\Entity\Production\ProductionReservation())->setPlan($plan)->setEquipment($machine)->setCreatedBy($operator)
                ->setSegmentNumber(1)->setStartsAt($start)->setEndsAt($start->modify('+'.$minutes.' minutes'));
            $plan->addReservation($row);
            $em->persist($row);
            $plans[] = $plan;
        }
        $plans[3]->setSchedulePinned(true);
        $this->serviceOrder->setCommitmentDate(new \DateTimeImmutable('2026-10-16'))->markPlanned($operator);
        $em->flush();
        return [...array_slice($plans, 0, 4), $operator];
    }

    /** @return list<\App\Entity\Production\ProductionReservation> */
    private function futureReservations(ServiceOrderOperationPlan $plan, \DateTimeImmutable $now): array
    {
        return array_values(array_filter($plan->getReservations()->toArray(), static fn ($row): bool => $row->isActive() && $row->getEndsAt() > $now));
    }

    private function assertNoMachineOverlaps(): void
    {
        $connection = $this->kernel->getContainer()->get('doctrine')->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM production_reservations a JOIN production_reservations b ON a.id < b.id AND a.equipment_id = b.equipment_id AND a.starts_at < b.ends_at AND a.ends_at > b.starts_at WHERE a.is_active = 1 AND b.is_active = 1'));
    }

    public function testCompletedProductionCanBeReviewedReleasedAndDeliveredAsSeparateHitos(): void
    {
        $quality = $this->createUserWithPermissions('qa-quality-flow', 'ROLE_QA_QUALITY_FLOW', [
            'quality.review', 'quality.return', 'quality.release', 'service_orders.view',
        ]);
        $delivery = $this->createUserWithPermissions('qa-delivery-flow', 'ROLE_QA_DELIVERY_FLOW', [
            'delivery.ship', 'delivery.confirm', 'service_orders.view',
        ]);
        $operator = $this->createUserWithPermissions('qa-worker-flow', 'ROLE_QA_WORKER_FLOW', [
            'production.view', 'production.execute', 'service_orders.view',
        ]);
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $area = (new OperationArea())->setCode('QA-FINISH')->setName('Acabados QA');
        $operation = (new Operation())->setOperationArea($area)->setCode('QA-LAMINADO')->setName('Laminado QA');
        $plan = (new ServiceOrderOperationPlan())
            ->setSequenceNumber(1)
            ->setOperation($operation)
            ->setCreatedBy($this->fixtures['user'])
            ->setOperationSnapshot(['code' => 'QA-LAMINADO', 'name' => 'Laminado QA', 'area_name' => 'Acabados QA'])
            ->assignOperator($operator);
        $item = $this->serviceOrder->getItems()->first();
        self::assertInstanceOf(ServiceOrderItem::class, $item);
        $item->beginDesignReview()->markReadyForPrint();
        $item->addOperationPlan($plan);
        $this->serviceOrder
            ->setCommitmentDate(new \DateTimeImmutable('2026-10-16'))
            ->markPlanned($this->fixtures['user']);
        $em->persist($area);
        $em->persist($operation);
        $em->flush();

        $executionManager = $this->kernel->getContainer()->get('test.service_container')->get(ProductionExecutionManager::class);
        $execution = $executionManager->start(
            $plan,
            $operator,
            new ProductionExecutionCommand(bin2hex(random_bytes(16)), $this->serviceOrder->getTraceabilityVersion()),
        );
        $em->refresh($this->serviceOrder);
        $executionManager->complete(
            $execution,
            $operator,
            new ProductionExecutionCommand(bin2hex(random_bytes(16)), $this->serviceOrder->getTraceabilityVersion(), 'Diseño terminado para revisión de Calidad.'),
        );
        $em->refresh($this->serviceOrder);
        self::assertSame('WAITING_QUALITY', $this->serviceOrder->getProductionStatus()->value);

        $this->browser->loginUser($quality);
        $this->browser->request('GET', '/admin/produccion/calidad/ordenes/'.$this->serviceOrder->getId());
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $qualityHtml = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('Laminado QA', $qualityHtml);
        self::assertStringContainsString('Devolver a retrabajo', $qualityHtml);
        self::assertStringContainsString('Liberar producción', $qualityHtml);
        $this->assertNoCommercialData($qualityHtml, 'quality review detail');

        $returnAction = sprintf(
            '/admin/produccion/calidad/ordenes/%d/partidas/%d/operaciones/%d/intentos/%d/devolver',
            $this->serviceOrder->getId(),
            $item->getId(),
            $plan->getId(),
            $execution->getId(),
        );
        $returnForm = $this->browser->getCrawler()->filter('form[action="'.$returnAction.'"]')->form();
        $returnForm['note'] = 'Defecto de laminado detectado en inspección visual.';
        $this->browser->submit($returnForm);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());

        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $this->serviceOrder = $em->getRepository(ServiceOrder::class)->find($this->serviceOrder->getId());
        self::assertInstanceOf(ServiceOrder::class, $this->serviceOrder);
        self::assertSame('QUALITY_REWORK', $this->serviceOrder->getProductionStatus()->value);

        $this->browser->loginUser($operator);
        $this->browser->request('GET', '/admin/produccion/ejecucion');
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $executionHtml = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('Iniciar retrabajo · intento 1', $executionHtml);
        $this->assertNoCommercialData($executionHtml, 'quality rework queue');
        $startForm = $this->browser->getCrawler()->filter(sprintf(
            'form[action="/admin/produccion/ejecucion/operaciones/%d/iniciar"]',
            $plan->getId(),
        ))->form();
        $this->browser->submit($startForm);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());

        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $plan = $em->getRepository(ServiceOrderOperationPlan::class)->find($plan->getId());
        self::assertInstanceOf(ServiceOrderOperationPlan::class, $plan);
        $rework = $plan->getLatestExecution();
        self::assertInstanceOf(\App\Entity\Production\ProductionOperationExecution::class, $rework);
        self::assertSame($execution->getId(), $rework->getOriginExecution()?->getId());
        $this->serviceOrder = $em->getRepository(ServiceOrder::class)->find($this->serviceOrder->getId());
        self::assertInstanceOf(ServiceOrder::class, $this->serviceOrder);
        $operator = $em->getRepository(User::class)->find($operator->getId());
        self::assertInstanceOf(User::class, $operator);
        $executionManager = $this->kernel->getContainer()->get('test.service_container')->get(ProductionExecutionManager::class);
        $executionManager->complete(
            $rework,
            $operator,
            new ProductionExecutionCommand(bin2hex(random_bytes(16)), $this->serviceOrder->getTraceabilityVersion(), 'Retrabajo de laminado completado.'),
        );
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $this->serviceOrder = $em->getRepository(ServiceOrder::class)->find($this->serviceOrder->getId());
        self::assertInstanceOf(ServiceOrder::class, $this->serviceOrder);
        self::assertSame('WAITING_QUALITY', $this->serviceOrder->getProductionStatus()->value);

        $this->browser->loginUser($quality);
        $this->browser->request('GET', '/admin/produccion/calidad/ordenes/'.$this->serviceOrder->getId());
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $this->assertNoCommercialData((string) $this->browser->getResponse()->getContent(), 'quality review after rework');
        $releaseForm = $this->browser->getCrawler()->filter(sprintf(
            'form[action="/admin/produccion/calidad/ordenes/%d/liberar"]',
            $this->serviceOrder->getId(),
        ))->form();
        $this->browser->submit($releaseForm);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $this->serviceOrder = $em->getRepository(ServiceOrder::class)->find($this->serviceOrder->getId());
        self::assertInstanceOf(ServiceOrder::class, $this->serviceOrder);
        self::assertSame('PRODUCTION_COMPLETED', $this->serviceOrder->getProductionStatus()->value);

        $this->browser->loginUser($delivery);
        $this->browser->request('GET', '/admin/produccion/entregas/ordenes/'.$this->serviceOrder->getId());
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $this->assertNoCommercialData((string) $this->browser->getResponse()->getContent(), 'delivery detail before method');
        $methodForm = $this->browser->getCrawler()->filter(sprintf(
            'form[action="/admin/produccion/entregas/ordenes/%d/modalidad"]',
            $this->serviceOrder->getId(),
        ))->form();
        $methodForm['fulfillment_method'] = 'pickup';
        $this->browser->submit($methodForm);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $this->serviceOrder = $em->getRepository(ServiceOrder::class)->find($this->serviceOrder->getId());
        self::assertInstanceOf(ServiceOrder::class, $this->serviceOrder);
        self::assertSame('READY_FOR_PICKUP', $this->serviceOrder->getDeliveryStatus()->value);

        $this->browser->request('GET', '/admin/produccion/entregas/ordenes/'.$this->serviceOrder->getId());
        $confirmForm = $this->browser->getCrawler()->filter(sprintf(
            'form[action="/admin/produccion/entregas/ordenes/%d/confirmar"]',
            $this->serviceOrder->getId(),
        ))->form();
        $this->browser->submit($confirmForm);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $this->serviceOrder = $em->getRepository(ServiceOrder::class)->find($this->serviceOrder->getId());
        self::assertInstanceOf(ServiceOrder::class, $this->serviceOrder);
        self::assertSame('DELIVERED', $this->serviceOrder->getDeliveryStatus()->value);
    }

    public function testOperatorCannotChangeCommitmentOrPriorityAndProductionPreviewHasNoCommercialAmounts(): void
    {
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $item = $this->serviceOrder->getItems()->first();
        self::assertInstanceOf(ServiceOrderItem::class, $item);
        $operator = $em->getRepository(User::class)->findOneBy(['username' => 'qa-operator']);
        self::assertInstanceOf(User::class, $operator);
        $this->browser->loginUser($operator);

        foreach ([
            '/admin/ordenes-servicio/'.$this->serviceOrder->getId().'/planificacion',
            '/admin/ordenes-servicio/'.$this->serviceOrder->getId().'/confirmar-planificacion',
            '/admin/ordenes-servicio/'.$this->serviceOrder->getId().'/partidas/'.$item->getId().'/prioridad/vista-previa',
        ] as $path) {
            $this->browser->request('POST', $path);
            self::assertSame(403, $this->browser->getResponse()->getStatusCode(), $path);
            $this->assertNoCommercialData((string) $this->browser->getResponse()->getContent(), $path);
        }

        $production = $em->getRepository(User::class)->findOneBy(['username' => 'qa-production']);
        self::assertInstanceOf(User::class, $production);
        $this->browser->loginUser($production);
        $this->browser->request('GET', '/admin/ordenes-servicio/'.$this->serviceOrder->getId());
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $tokenSelector = sprintf(
            'form[action="/admin/ordenes-servicio/%d/partidas/%d/prioridad/vista-previa"] input[name="_token"]',
            $this->serviceOrder->getId(),
            $item->getId(),
        );
        $csrf = $this->browser->getCrawler()->filter($tokenSelector)->attr('value');
        self::assertNotEmpty($csrf);
        $this->browser->request(
            'POST',
            '/admin/ordenes-servicio/'.$this->serviceOrder->getId().'/partidas/'.$item->getId().'/prioridad/vista-previa',
            ['_token' => $csrf, 'priority' => 'high', 'reason' => 'Pedido de prueba prioritaria'],
        );
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        self::assertStringContainsString('Revisar cambio a prioridad Alta', (string) $this->browser->getResponse()->getContent());
        $this->assertNoCommercialData((string) $this->browser->getResponse()->getContent(), 'priority preview');

        $confirmForm = $this->browser->getCrawler()->filter(sprintf(
            'form[action="/admin/ordenes-servicio/%d/partidas/%d/prioridad/confirmar"]',
            $this->serviceOrder->getId(),
            $item->getId(),
        ))->form();
        $this->browser->submit($confirmForm);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode(), 'Confirming a priority preview must complete without a DBAL date binding error.');
        $this->browser->followRedirect();
        $currentEntityManager = $this->kernel->getContainer()->get('doctrine')->getManager();
        $updatedItem = $currentEntityManager->getRepository(ServiceOrderItem::class)->find($item->getId());
        self::assertInstanceOf(ServiceOrderItem::class, $updatedItem);
        self::assertSame('high', $updatedItem->getPriority()->value);
    }

    public function testOrderCannotBeFinalizedWhenItsRouteIsMissing(): void
    {
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $production = $em->getRepository(User::class)->findOneBy(['username' => 'qa-production']);
        self::assertInstanceOf(User::class, $production);
        $this->browser->loginUser($production);

        $this->browser->request('GET', '/admin/ordenes-servicio/'.$this->serviceOrder->getId());
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $html = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('Progreso de la orden', $html);
        self::assertStringContainsString('Planificación operativa', $html);
        self::assertStringNotContainsString('Reservar agenda completa', $html);
        self::assertStringNotContainsString('Confirmar planificación', $html);
        self::assertStringNotContainsString('Marcar como planificada', $html);
        $this->assertNoCommercialData($html, 'unplanned order without route');
    }

    public function testProductionCanReserveAndConfirmThePlanInOneAction(): void
    {
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $production = $em->getRepository(User::class)->findOneBy(['username' => 'qa-production']);
        self::assertInstanceOf(User::class, $production);
        $item = $this->serviceOrder->getItems()->first();
        self::assertInstanceOf(ServiceOrderItem::class, $item);

        $area = (new OperationArea())->setCode('QA-CONFIRM')->setName('Impresión QA');
        $operation = (new Operation())->setOperationArea($area)->setCode('QA-CONFIRM-OP')->setName('Impresión QA');
        $machine = (new \App\Entity\Equipment\Equipment())
            ->setCode('QA-CONFIRM-EQ')
            ->setName('Plotter QA')
            ->setPrimaryOperation($operation);
        $plan = (new ServiceOrderOperationPlan())
            ->setSequenceNumber(1)
            ->setOperation($operation)
            ->setCreatedBy($production)
            ->setOperationSnapshot(['code' => 'QA-CONFIRM-OP', 'name' => 'Impresión QA', 'area_name' => 'Impresión QA'])
            ->setEquipment($machine, ['name' => 'Plotter QA']);
        $item->addOperationPlan($plan);
        $this->serviceOrder->setCommitmentDate(new \DateTimeImmutable('+5 days'));
        foreach ([$area, $operation, $machine] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $planManager = $this->kernel->getContainer()->get('test.service_container')->get(ProductionPlanManager::class);
        $planManager->estimate($plan, null, null, '60', 'Estimación manual de QA', $production);

        $this->browser->loginUser($production);
        $this->browser->request('GET', '/admin/ordenes-servicio/'.$this->serviceOrder->getId());
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $html = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('Tiempo de trabajo estimado', $html);
        self::assertStringContainsString('Fin de producción pronosticado', $html);
        self::assertStringContainsString('Confirmar planificación', $html);
        $this->assertNoCommercialData($html, 'planning confirmation view');

        $form = $this->browser->getCrawler()->filter(sprintf(
            'form[action="/admin/ordenes-servicio/%d/confirmar-planificacion"]',
            $this->serviceOrder->getId(),
        ))->form();
        $this->browser->submit($form);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        $this->browser->followRedirect();
        self::assertStringContainsString('Planificación confirmada', (string) $this->browser->getResponse()->getContent());

        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $savedOrder = $em->getRepository(ServiceOrder::class)->find($this->serviceOrder->getId());
        self::assertInstanceOf(ServiceOrder::class, $savedOrder);
        self::assertTrue($savedOrder->isPlanned());
        self::assertCount(1, $em->getRepository(\App\Entity\Production\ProductionReservation::class)->findActiveForServiceOrder($savedOrder));
    }

    public function testInitialCommitmentNeedsNoReasonButChangesRequireOne(): void
    {
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $production = $em->getRepository(User::class)->findOneBy(['username' => 'qa-production']);
        self::assertInstanceOf(User::class, $production);
        $this->browser->loginUser($production);

        $this->browser->request('GET', '/admin/ordenes-servicio/'.$this->serviceOrder->getId());
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $planningFormSelector = sprintf(
            'form[action="/admin/ordenes-servicio/%d/planificacion"]',
            $this->serviceOrder->getId(),
        );
        $form = $this->browser->getCrawler()->filter($planningFormSelector)->form();
        $form['service_order_planning[commitmentDate]'] = '2026-10-16';
        $form['service_order_planning[reason]'] = '';
        $this->browser->submit($form);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        $this->browser->followRedirect();
        self::assertStringContainsString('Fecha compromiso actualizada correctamente.', (string) $this->browser->getResponse()->getContent());

        $currentEntityManager = $this->kernel->getContainer()->get('doctrine')->getManager();
        $savedOrder = $currentEntityManager->getRepository(ServiceOrder::class)->find($this->serviceOrder->getId());
        self::assertInstanceOf(ServiceOrder::class, $savedOrder);
        self::assertSame('2026-10-16', $savedOrder->getCommitmentDate()?->format('Y-m-d'));
        self::assertNull($savedOrder->getCommitmentReason());

        $this->browser->request('GET', '/admin/ordenes-servicio/'.$this->serviceOrder->getId());
        $form = $this->browser->getCrawler()->filter($planningFormSelector)->form();
        $form['service_order_planning[commitmentDate]'] = '2026-10-17';
        $form['service_order_planning[reason]'] = '';
        $this->browser->submit($form);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        $this->browser->followRedirect();
        self::assertStringContainsString(
            'Indica el motivo del cambio (de 5 a 500 caracteres).',
            (string) $this->browser->getResponse()->getContent(),
        );

        $currentEntityManager = $this->kernel->getContainer()->get('doctrine')->getManager();
        $savedOrder = $currentEntityManager->getRepository(ServiceOrder::class)->find($this->serviceOrder->getId());
        self::assertInstanceOf(ServiceOrder::class, $savedOrder);
        self::assertSame('2026-10-16', $savedOrder->getCommitmentDate()?->format('Y-m-d'));
        self::assertNull($savedOrder->getCommitmentReason());

        $this->browser->request('GET', '/admin/ordenes-servicio/'.$this->serviceOrder->getId());
        $form = $this->browser->getCrawler()->filter($planningFormSelector)->form();
        $form['service_order_planning[commitmentDate]'] = '2026-10-17';
        $form['service_order_planning[reason]'] = 'Confirmación de fecha solicitada por el cliente';
        $this->browser->submit($form);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        $this->browser->followRedirect();
        self::assertStringContainsString(
            'Fecha compromiso actualizada correctamente.',
            (string) $this->browser->getResponse()->getContent(),
        );

        $currentEntityManager = $this->kernel->getContainer()->get('doctrine')->getManager();
        $savedOrder = $currentEntityManager->getRepository(ServiceOrder::class)->find($this->serviceOrder->getId());
        self::assertInstanceOf(ServiceOrder::class, $savedOrder);
        self::assertSame('2026-10-17', $savedOrder->getCommitmentDate()?->format('Y-m-d'));
        self::assertSame('Confirmación de fecha solicitada por el cliente', $savedOrder->getCommitmentReason());
    }

    private function assertOperationalAccessWithoutCommercialData(string $username): void
    {
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $employee = $em->getRepository(User::class)->findOneBy(['username' => $username]);
        self::assertInstanceOf(User::class, $employee);
        $this->browser->loginUser($employee);

        foreach ([
            '/admin/ordenes-servicio' => ['Órdenes de servicio', 'QA-OS-900001'],
            '/admin/ordenes-servicio/'.$this->serviceOrder->getId() => ['Partidas de la orden', 'Ancho 100 cm x alto 200 cm'],
        ] as $path => $visibleText) {
            $this->browser->request('GET', $path);
            self::assertSame(200, $this->browser->getResponse()->getStatusCode(), $path.' for '.$username);
            $html = (string) $this->browser->getResponse()->getContent();
            foreach ($visibleText as $text) {
                self::assertStringContainsString($text, $html, $path.' for '.$username);
            }
            if ($path === '/admin/ordenes-servicio/'.$this->serviceOrder->getId()) {
                self::assertStringContainsString('data-controller="ui--scroll-position"', $html);
                self::assertStringContainsString('ui--scroll-position#remember', $html);
            }
            $this->assertNoCommercialData($html, $path.' for '.$username);
        }

        foreach ([
            '/admin/cotizaciones/'.$this->quotationId,
            '/admin/cotizaciones/'.$this->quotationId.'/pdf',
            '/admin/cotizaciones/contexto-cliente/'.$this->fixtures['client']->getId(),
            '/admin/cotizaciones/contexto-producto/'.$this->fixtures['lona']->getId(),
            '/admin/cotizaciones/preview-precio/'.$this->fixtures['lona']->getId().'?quantity=2',
        ] as $path) {
            $this->browser->request('GET', $path);
            self::assertSame(403, $this->browser->getResponse()->getStatusCode(), $path.' for '.$username);
            $this->assertNoCommercialData((string) $this->browser->getResponse()->getContent(), $path.' for '.$username);
        }

        $this->browser->jsonRequest(
            'POST',
            '/admin/ordenes-servicio/'.$this->serviceOrder->getId().'/partidas/'.$this->serviceOrder->getItems()->first()->getId().'/operaciones/reordenar',
            [],
        );
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());
        $this->assertNoCommercialData((string) $this->browser->getResponse()->getContent(), 'reordenar JSON for '.$username);

        $itemId = $this->serviceOrder->getItems()->first()->getId();
        foreach ([
            '/admin/ordenes-servicio/'.$this->serviceOrder->getId().'/partidas/'.$itemId.'/dividir',
            '/admin/ordenes-servicio/'.$this->serviceOrder->getId().'/partidas/'.$itemId.'/corregir-medidas',
        ] as $path) {
            $this->browser->request('POST', $path, ['quantities' => "1\n1", 'finished_width_cm' => '110', 'reason' => 'Prueba de permisos']);
            self::assertSame(403, $this->browser->getResponse()->getStatusCode(), $path.' for '.$username);
            $this->assertNoCommercialData((string) $this->browser->getResponse()->getContent(), $path.' for '.$username);
        }
    }

    public function testSalesCanSeeCommercialAmountsOnServiceOrder(): void
    {
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $sales = $em->getRepository(User::class)->findOneBy(['username' => 'qa-sales']);
        self::assertInstanceOf(User::class, $sales);
        $this->browser->loginUser($sales);

        foreach ([
            '/admin/ordenes-servicio' => ['13,791.11'],
            '/admin/ordenes-servicio/'.$this->serviceOrder->getId() => ['12,345.67', '13,791.11'],
        ] as $path => $visibleAmounts) {
            $this->browser->request('GET', $path);
            self::assertSame(200, $this->browser->getResponse()->getStatusCode(), $path);
            $html = (string) $this->browser->getResponse()->getContent();
            foreach ($visibleAmounts as $amount) {
                self::assertStringContainsString($amount, $html, $path);
            }
        }

        $this->browser->request(
            'GET',
            '/admin/cotizaciones/preview-precio/'.$this->fixtures['lona']->getId().'?quantity=2',
        );
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        self::assertStringContainsString('80.00', (string) $this->browser->getResponse()->getContent());
    }

    public function testSplitReplacesTheOrderLineAndPreservesQuantityAndOrderTotal(): void
    {
        $manager = $this->kernel->getContainer()->get('test.service_container')->get(ServiceOrderManager::class);
        $original = $this->serviceOrder->getItems()->first();
        self::assertInstanceOf(ServiceOrderItem::class, $original);
        $acceptedSource = $original->getSourceQuotationItem();
        $orderTotal = $this->serviceOrder->getTotal();

        $rows = $manager->splitItem($this->serviceOrder, $original, "1\n1", 'División de QA para probar dos subtrabajos', $this->fixtures['user']);

        self::assertCount(2, $rows);
        self::assertSame('1.000000', $rows[0]->getQuantity());
        self::assertSame('1.000000', $rows[1]->getQuantity());
        self::assertSame('12345.67', $rows[0]->getLineSubtotal());
        self::assertSame('12345.67', $rows[1]->getLineSubtotal());
        self::assertSame('1.1', $rows[0]->getDisplayLineNumber());
        self::assertSame('1.2', $rows[1]->getDisplayLineNumber());
        self::assertSame($acceptedSource, $rows[0]->getSourceQuotationItem());
        self::assertNull($rows[1]->getSourceQuotationItem());
        self::assertSame($rows[0], $rows[1]->getSplitParentItem());
        self::assertSame($orderTotal, $this->serviceOrder->getTotal());
        self::assertCount(2, $this->serviceOrder->getItems());

        $sales = $this->kernel->getContainer()->get('doctrine')->getManager()->getRepository(User::class)->findOneBy(['username' => 'qa-sales']);
        self::assertInstanceOf(User::class, $sales);
        $this->browser->loginUser($sales);
        $this->browser->request('GET', '/admin/ordenes-servicio/'.$this->serviceOrder->getId());
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $salesHtml = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('1.1', $salesHtml);
        self::assertStringContainsString('1.2', $salesHtml);
        self::assertGreaterThanOrEqual(4, substr_count($salesHtml, '12,345.67'));

        $operator = $this->kernel->getContainer()->get('doctrine')->getManager()->getRepository(User::class)->findOneBy(['username' => 'qa-operator']);
        self::assertInstanceOf(User::class, $operator);
        $this->browser->loginUser($operator);
        $this->browser->request('GET', '/admin/ordenes-servicio/'.$this->serviceOrder->getId());
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $operatorHtml = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('1.1', $operatorHtml);
        self::assertStringContainsString('1.2', $operatorHtml);
        $this->assertNoCommercialData($operatorHtml, 'divided order visible to Operator');
    }

    public function testProductionCorrectionDoesNotChangeAcceptedQuotationOrCommercialValues(): void
    {
        $manager = $this->kernel->getContainer()->get('test.service_container')->get(ServiceOrderManager::class);
        $item = $this->serviceOrder->getItems()->first();
        self::assertInstanceOf(ServiceOrderItem::class, $item);
        $quotedSpecifications = $item->getSpecificationsSnapshot();
        $unitPrice = $item->getUnitPrice();
        $lineSubtotal = $item->getLineSubtotal();
        $orderTotal = $this->serviceOrder->getTotal();

        $manager->correctProductionSpecifications(
            $this->serviceOrder,
            $item,
            '110',
            null,
            'Ajuste solicitado al revisar el archivo del cliente',
            $this->fixtures['user'],
        );

        self::assertSame($quotedSpecifications, $item->getSpecificationsSnapshot());
        self::assertSame($unitPrice, $item->getUnitPrice());
        self::assertSame($lineSubtotal, $item->getLineSubtotal());
        self::assertSame($orderTotal, $this->serviceOrder->getTotal());
        self::assertSame('110.000000', $item->getProductionSpecificationsSnapshot()['finished_width_cm']);
        self::assertSame('technical_correction', $item->getProductionSpecificationsSnapshot()['_production_adjustment']['type']);
        self::assertSame('Las medidas de producción se corrigieron; revisa la ruta y confírmala antes de planificar.', $item->getPlanningIssue());
    }

    public function testManualMaterialsUseHttpPermissionsAndIdempotencyWithoutInventoryMovements(): void
    {
        $manager = $this->createUserWithPermissions('qa-material-manager', 'ROLE_QA_MATERIAL_MANAGER', ['production.view', 'production.assign']);
        $operator = $this->createUserWithPermissions('qa-material-worker', 'ROLE_QA_MATERIAL_WORKER', ['production.view', 'production.execute']);
        $otherOperator = $this->createUserWithPermissions('qa-material-other', 'ROLE_QA_MATERIAL_OTHER', ['production.view', 'production.execute']);
        $plan = $this->createMaterialTestPlan($operator);
        $planId = $plan->getId();
        $queue = '/admin/produccion/ejecucion';
        $plannedPath = $queue.'/operaciones/'.$planId.'/materiales/prevision';

        $this->browser->loginUser($manager);
        $crawler = $this->browser->request('GET', $queue);
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $form = $crawler->filter('form[action="'.$plannedPath.'"]')->form([
            'material_name' => 'Película de laminado QA', 'quantity' => '4.5', 'unit' => 'm²',
        ]);
        $plannedPayload = $form->getPhpValues();
        $this->browser->submit($form);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode(), 'Material previsto debe guardarse por HTTP.');
        $this->browser->request('POST', $plannedPath, $plannedPayload);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        $this->browser->followRedirect();
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        self::assertStringContainsString('Película de laminado QA', (string) $this->browser->getResponse()->getContent());
        $connection = $this->kernel->getContainer()->get('doctrine')->getConnection();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM production_material_usages'));

        $this->browser->loginUser($operator);
        $this->browser->request('POST', $plannedPath, $plannedPayload);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());
        $crawler = $this->browser->request('GET', $queue);
        $startForm = $crawler->filter('form[action="'.$queue.'/operaciones/'.$planId.'/iniciar"]')->form();
        $this->browser->submit($startForm);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        $crawler = $this->browser->followRedirect();
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $form = $crawler->filter('form[action$="/materiales/usados"]')->form([
            'material_name' => 'Película de laminado QA', 'quantity' => '5', 'unit' => 'm²',
            'waste' => '0.5', 'waste_reason' => 'Recorte de prueba.',
        ]);
        $usedPath = parse_url($form->getUri(), PHP_URL_PATH);
        $usedPayload = $form->getPhpValues();

        // Keep the session's valid CSRF token: rejection must come from ownership, not a forged token.
        $this->browser->loginUser($otherOperator);
        $this->browser->request('POST', $usedPath, $usedPayload);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        $this->browser->followRedirect();
        self::assertStringContainsString('Solo la persona asignada', (string) $this->browser->getResponse()->getContent());
        $connection = $this->kernel->getContainer()->get('doctrine')->getConnection();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM production_material_usages'));

        $this->browser->loginUser($operator);
        $this->browser->request('POST', $usedPath, array_replace($usedPayload, ['_token' => 'forged']));
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());
        $this->browser->request('POST', $usedPath, $usedPayload);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        $this->browser->request('POST', $usedPath, $usedPayload);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        $this->browser->followRedirect();
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $this->assertNoCommercialData((string) $this->browser->getResponse()->getContent(), 'manual material capture');
        $connection = $this->kernel->getContainer()->get('doctrine')->getConnection();
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM production_material_usages'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM inventory_movements'));
        $usage = $connection->fetchAssociative('SELECT * FROM production_material_usages WHERE actual_quantity IS NOT NULL');
        self::assertIsArray($usage);
        self::assertEquals(5, $usage['actual_quantity']);
        self::assertEquals(4.5, $usage['planned_quantity']);
        self::assertEquals(0.5, $usage['waste_quantity']);
        self::assertNull($usage['material_variant_id']);
        self::assertNull($usage['inventory_movement_id']);
    }

    public function testPrintingShowsRecordedMaterialWithItsMeasurementDateAndWithoutCommercialData(): void
    {
        $operator = $this->createUserWithPermissions('qa-printing-material', 'ROLE_QA_PRINTING_MATERIAL', ['production.view']);
        $plan = $this->createMaterialTestPlan($operator, 'IMP-IMPRESION');
        $execution = new \App\Entity\Production\ProductionOperationExecution($plan, $operator, $operator, 1);
        $plan->addExecution($execution);
        $execution->complete('Impresión de prueba terminada.');
        $usage = new \App\Entity\Production\ProductionMaterialUsage(
            $plan->getServiceOrderItem(), null, null, '4.5',
            \App\Enum\Production\CalculationMethod::FIXED, 1, [], 'Lona de prueba QA', 'm²',
        );
        $usage->assignExecutionContext($plan, $execution, []);
        $usage->recordActual('5', '0.5', \App\Enum\Inventory\QuantitySource::MEASURED, $operator, 'CAPTURA_MANUAL', 'Recorte de prueba.');
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $em->persist($execution);
        $em->persist($usage);
        $em->flush();

        $this->browser->loginUser($operator);
        $this->browser->request('GET', '/admin/impresion/operaciones/'.$plan->getId());
        self::assertSame(200, $this->browser->getResponse()->getStatusCode(), 'El consumo registrado debe renderizarse.');
        $html = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('Lona de prueba QA', $html);
        self::assertStringContainsString('Intento 1', $html);
        $this->assertNoCommercialData($html, 'printing material history');
    }

    private function createMaterialTestPlan(User $operator, string $code = 'QA-LAMINADO'): ServiceOrderOperationPlan
    {
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $area = (new OperationArea())->setCode($code === 'IMP-IMPRESION' ? 'IMPRESION' : 'QA-MATERIAL')->setName('Acabados QA');
        $operation = (new Operation())->setOperationArea($area)->setCode($code)->setName('Operación material QA');
        $plan = (new ServiceOrderOperationPlan())->setSequenceNumber(1)->setOperation($operation)
            ->setCreatedBy($this->fixtures['user'])
            ->setOperationSnapshot(['code' => $code, 'name' => 'Operación material QA', 'area_name' => 'Acabados QA'])
            ->assignOperator($operator);
        $item = $this->serviceOrder->getItems()->first();
        self::assertInstanceOf(ServiceOrderItem::class, $item);
        $item->beginDesignReview()->markReadyForPrint();
        $item->addOperationPlan($plan);
        $this->serviceOrder->setCommitmentDate(new \DateTimeImmutable('2026-10-16'))->markPlanned($this->fixtures['user']);
        $em->persist($area);
        $em->persist($operation);
        $em->flush();
        return $plan;
    }

    /** @param list<string> $codes */
    private function createUserWithPermissions(string $username, string $roleCode, array $codes): User
    {
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $role = (new Role())->setCode($roleCode)->setName($roleCode);
        foreach ($codes as $code) {
            $permission = $em->getRepository(Permission::class)->findOneBy(['code' => $code]);
            if (!$permission instanceof Permission) {
                [$module, $action] = explode('.', $code, 2);
                $permission = (new Permission())
                    ->setCode($code)
                    ->setModule($module)
                    ->setAction($action)
                    ->setName($code);
                $em->persist($permission);
            }
            $role->addPermission($permission);
        }

        $user = (new User())
            ->setUsername($username)
            ->setFullName($username)
            ->setEmail($username.'@example.invalid')
            ->setMustChangePassword(false)
            ->setPassword('unused')
            ->addRole($role);
        $em->persist($role);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function createAcceptedOrder(): ServiceOrder
    {
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $data = new QuotationData();
        $data->client = $this->fixtures['client'];
        $data->commercialContactId = (string) $this->fixtures['contact']->getId();
        $data->fiscalAddressId = (string) $this->fixtures['fiscal']->getId();
        $data->deliveryAddressId = (string) $this->fixtures['delivery']->getId();

        $line = new QuotationItemData();
        $line->commercialItem = $this->fixtures['lona'];
        $line->commercialCategory = $this->fixtures['category'];
        $line->quantity = '2';
        $line->specifications = ['finished_width_cm' => '100', 'finished_height_cm' => '200'];
        $data->addItem($line);

        $manager = $this->kernel->getContainer()->get('test.service_container')->get(QuotationManager::class);
        $quotation = $manager->create($data, $this->fixtures['user']);
        $quotation->issue('QA-Q-900001', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $quotation->accept(
            QuotationResponseChannel::EMAIL,
            'Contacto de QA',
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'COMMERCIAL-ACCEPTANCE-PRIVATE-987654',
            'COMMERCIAL-EVIDENCE-PRIVATE-987655',
        );
        $em->flush();
        $this->quotationId = (int) $quotation->getId();

        $order = (new ServiceOrder())
            ->setSourceQuotation($quotation)
            ->setCreatedBy($this->fixtures['user'])
            ->setFolio('QA-OS-900001')
            ->setSourceQuotationFolio('QA-Q-900001')
            ->setQuotationSnapshot([
                'accepted_at' => '2026-10-10T16:00:00+00:00',
                'acceptance_channel' => 'EMAIL',
                'acceptance_contact' => 'Contacto de QA',
                'acceptance_notes' => 'COMMERCIAL-ACCEPTANCE-PRIVATE-987654',
                'acceptance_evidence_reference' => 'COMMERCIAL-EVIDENCE-PRIVATE-987655',
            ])
            ->setClientSnapshot([
                'business_name' => 'Cliente operativo QA',
                'legal_name' => 'Cliente operativo QA S.A.',
                'tax_id' => 'RFC-PRIVATE-987656',
            ])
            ->setNotes('COMMERCIAL-ORDER-NOTE-PRIVATE-987657')
            ->setCurrency('MXN')
            ->setTotals('12345.67', '456.78', '11888.89', '1902.22', '13791.11');

        $quotationItem = $quotation->getItems()->first();
        self::assertNotFalse($quotationItem);
        $item = (new ServiceOrderItem())
            ->setSourceQuotationItem($quotationItem)
            ->setCommercialItem($this->fixtures['lona'])
            ->setLineNumber(1)
            ->setTrackingCode('OS-2026-000001-P001')
            ->setQuantity('2')
            ->setUnitPrice('12345.67')
            ->setLineSubtotal('24691.34')
            ->setCommercialItemSnapshot(['name' => 'Lona QA', 'code' => 'QA-LONA'])
            ->setSpecificationsSnapshot(['summary' => 'Ancho 100 cm x alto 200 cm'])
            ->setProductionSpecificationsSnapshot([
                'summary' => 'Ancho 100 cm x alto 200 cm',
                'finished_width_cm' => '100',
                'finished_height_cm' => '200',
            ])
            ->setSpecificationSchemaVersion(1);
        $order->addItem($item);
        $em->persist($order);
        $em->flush();

        return $order;
    }

    private function assertNoCommercialData(string $response, string $context): void
    {
        foreach ([
            '12,345.67',
            '13,791.11',
            '24,691.34',
            '456.78',
            '1,902.22',
            'COMMERCIAL-ACCEPTANCE-PRIVATE-987654',
            'COMMERCIAL-EVIDENCE-PRIVATE-987655',
            'COMMERCIAL-ORDER-NOTE-PRIVATE-987657',
            'RFC-PRIVATE-987656',
        ] as $privateValue) {
            self::assertStringNotContainsString($privateValue, $response, $context);
        }
    }
}
