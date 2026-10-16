<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Equipment\Equipment;
use App\Entity\Operations\Operation;
use App\Entity\Operations\OperationArea;
use App\Entity\Users\Permission;
use App\Entity\Users\Role;
use App\Entity\Users\User;
use App\Tests\Support\IsolatedKernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class EquipmentLabelControllerTest extends TestCase
{
    private IsolatedKernel $kernel;
    private KernelBrowser $browser;
    private Equipment $equipment;
    private User $viewer;
    private User $operator;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('Run PHP with -d extension=pdo_sqlite for isolated equipment tests.');
        }

        $directory = dirname(__DIR__, 2).'/var/equipment-label-qa/'.bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->kernel = new IsolatedKernel($directory);
        $this->kernel->boot();
        $entityManager = $this->kernel->getContainer()->get('doctrine')->getManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $area = (new OperationArea())->setCode('AREA-IMP')->setName('Área de impresión');
        $operation = (new Operation())->setOperationArea($area)->setCode('IMP')->setName('Impresión');
        $this->equipment = (new Equipment())
            ->setPrimaryOperation($operation)
            ->setCode('EQ-IMP-HP-365')
            ->setName('Plotter HP 365')
            ->setBrand('HP')
            ->setModel('365');

        $viewPermission = (new Permission())->setCode('equipment.view')->setModule('equipment')->setAction('view')->setName('Consultar equipos');
        $executePermission = (new Permission())->setCode('production.execute')->setModule('production')->setAction('execute')->setName('Ejecutar producción');
        $viewerRole = (new Role())->setCode('ROLE_QA_EQUIPMENT_VIEW')->setName('Equipos QA')->addPermission($viewPermission);
        $operatorRole = (new Role())->setCode('ROLE_QA_PRINTING_OPERATOR')->setName('Operador QA')->addPermission($executePermission);
        $this->viewer = $this->user('equipment-label-viewer', $viewerRole);
        $this->operator = $this->user('equipment-label-operator', $operatorRole);

        foreach ([$area, $operation, $this->equipment, $viewPermission, $executePermission, $viewerRole, $operatorRole, $this->viewer, $this->operator] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $this->browser = new KernelBrowser($this->kernel);
    }

    protected function tearDown(): void
    {
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
    }

    public function testEquipmentListOffersAPrintableQrWithTheCodeExpectedByPrinting(): void
    {
        $this->browser->loginUser($this->viewer);
        $this->browser->request('GET', '/admin/equipos?q=EQ-IMP-HP-365');

        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        self::assertStringContainsString('/admin/equipos/'.$this->equipment->getId().'/etiqueta', (string) $this->browser->getResponse()->getContent());

        $this->browser->request('GET', '/admin/equipos/'.$this->equipment->getId().'/etiqueta');
        $html = (string) $this->browser->getResponse()->getContent();

        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        self::assertStringContainsString('<svg', $html);
        self::assertStringContainsString('EQ-IMP-HP-365', $html);
        self::assertStringContainsString('Plotter HP 365', $html);
        self::assertStringContainsString('alt="OoxCorp"', $html);
        self::assertStringContainsString('private', (string) $this->browser->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));
    }

    public function testPrintingOperatorCanOpenTheLabelWithoutEquipmentAdministrationAccess(): void
    {
        $this->browser->loginUser($this->operator);
        $this->browser->request('GET', '/admin/equipos/'.$this->equipment->getId().'/etiqueta');

        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        self::assertStringContainsString('EQ-IMP-HP-365', (string) $this->browser->getResponse()->getContent());

        $this->browser->request('GET', '/admin/equipos');
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());
    }

    private function user(string $username, Role $role): User
    {
        return (new User())
            ->setUsername($username)
            ->setFullName($username)
            ->setEmail($username.'@example.invalid')
            ->setPassword('not-used-in-this-test')
            ->setMustChangePassword(false)
            ->addRole($role);
    }
}
