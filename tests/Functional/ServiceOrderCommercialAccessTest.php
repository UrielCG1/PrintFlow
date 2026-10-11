<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Quotations\QuotationData;
use App\Application\Quotations\QuotationItemData;
use App\Application\Quotations\QuotationManager;
use App\Application\Orders\ServiceOrderManager;
use App\Application\Production\ProductionExecutionCommand;
use App\Application\Production\ProductionExecutionManager;
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
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
    }

    public function testProductionCanSeeOperationalOrderButNoCommercialAmountsOrSourceDocuments(): void
    {
        $this->assertOperationalAccessWithoutCommercialData('qa-production');
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
        self::assertStringContainsString('Avance de la orden', $html);
        self::assertStringNotContainsString('Reservar todas las operaciones', $html);
        self::assertStringNotContainsString('Marcar como planificada', $html);
        $this->assertNoCommercialData($html, 'unplanned order without route');
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
