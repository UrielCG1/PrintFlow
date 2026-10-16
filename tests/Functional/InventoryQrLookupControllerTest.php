<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Inventory\InventoryStockUnit;
use App\Entity\Materials\Material;
use App\Entity\Materials\MaterialCategory;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Users\Permission;
use App\Entity\Users\Role;
use App\Entity\Users\User;
use App\Enum\Catalog\MeasurementDimensionType;
use App\Tests\Support\IsolatedKernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class InventoryQrLookupControllerTest extends TestCase
{
    private IsolatedKernel $kernel;
    private KernelBrowser $browser;
    private EntityManagerInterface $entityManager;
    private InventoryStockUnit $stockUnit;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('Run PHP with -d extension=pdo_sqlite for isolated inventory tests.');
        }

        $directory = dirname(__DIR__, 2).'/var/inventory-qr-lookup-qa/'.bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->kernel = new IsolatedKernel($directory);
        $this->kernel->boot();
        $this->entityManager = $this->kernel->getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $unit = (new MeasurementUnit())
            ->setCode('ML')
            ->setName('Metro lineal')
            ->setSymbol('m')
            ->setDimensionType(MeasurementDimensionType::LENGTH)
            ->setConversionFactor('1')
            ->setDecimalScale(4)
            ->setAllowsFraction(true);
        $category = (new MaterialCategory())->setCode('QA-PILOTO')->setName('Piloto');
        $material = (new Material())
            ->setCategory($category)
            ->setMeasurementUnit($unit)
            ->setCode('QA-LONA')
            ->setName('Lona de ensayo QA');
        $variant = new MaterialVariant($material, 'QA-LONA-ANCHO-1P5000M', $unit, $unit, $unit);
        $this->stockUnit = new InventoryStockUnit($variant, '10');

        $permission = (new Permission())
            ->setCode('inventory.view')
            ->setModule('inventory')
            ->setAction('view')
            ->setName('Consultar inventario');
        $role = (new Role())->setCode('ROLE_QA_INVENTORY')->setName('Inventario QA')->addPermission($permission);
        $user = (new User())
            ->setUsername('inventory-qr-qa')
            ->setFullName('Inventario QA')
            ->setEmail('inventory-qr-qa@example.invalid')
            ->setPassword('not-used-in-this-test')
            ->setMustChangePassword(false)
            ->addRole($role);

        foreach ([$unit, $category, $material, $variant, $this->stockUnit, $permission, $role, $user] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $this->browser = new KernelBrowser($this->kernel);
        $this->browser->loginUser($user);
    }

    protected function tearDown(): void
    {
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
    }

    public function testQrLookupShowsTheUnitWithoutCreatingACountOrMovement(): void
    {
        $token = $this->lookupCsrfToken();
        $this->browser->request('POST', '/admin/inventario/recepciones/consultar-qr', [
            '_token' => $token,
            'qr_payload' => $this->stockUnit->getQrPayload(),
        ]);

        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        self::assertStringContainsString('q='.$this->stockUnit->getUnitCode(), (string) $this->browser->getResponse()->headers->get('Location'));
        $this->browser->followRedirect();
        self::assertStringContainsString($this->stockUnit->getUnitCode(), (string) $this->browser->getResponse()->getContent());
        self::assertStringContainsString('10.000000 m', (string) $this->browser->getResponse()->getContent());
        self::assertSame('10.000000', $this->stockUnit->getRemainingQuantity());
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM inventory_cycle_counts'));
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM inventory_movements'));
    }

    public function testUnknownQrDoesNotExposeOrAlterInventory(): void
    {
        $token = $this->lookupCsrfToken();
        $this->browser->request('POST', '/admin/inventario/recepciones/consultar-qr', [
            '_token' => $token,
            'qr_payload' => 'PF:MAT:1:'.str_repeat('f', 48),
        ]);

        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        $this->browser->followRedirect();
        self::assertStringContainsString('no corresponde a una unidad registrada', (string) $this->browser->getResponse()->getContent());
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM inventory_cycle_counts'));
    }

    private function lookupCsrfToken(): string
    {
        $crawler = $this->browser->request('GET', '/admin/inventario/recepciones');
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());

        return (string) $crawler->filter('form[action="/admin/inventario/recepciones/consultar-qr"] input[name="_token"]')->attr('value');
    }
}
