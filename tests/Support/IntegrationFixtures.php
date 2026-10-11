<?php
declare(strict_types=1);
namespace App\Tests\Support;
use App\Entity\Catalog\{CommercialCategory, CommercialItem, MeasurementUnit, ItemPriceRule, CommercialCharacteristic, CommercialItemCharacteristic};
use App\Entity\Clients\{Client, ClientClass, ClientContact, ClientAddress};
use App\Entity\Common\{Contact, Address};
use App\Entity\Users\{User, Role, Permission};
use App\Enum\Catalog\{CommercialItemType, ItemPriceRuleType, CommercialCharacteristicInputType};
use App\Enum\Quotations\{QuotationItemSpecificationProfile, QuotationStatus};
use Doctrine\ORM\EntityManagerInterface;

final class IntegrationFixtures
{
    public static function seed(EntityManagerInterface $em): array
    {
        $role = (new Role())->setCode('ROLE_ADMIN')->setName('QA');
        $codes = ['dashboard.view','catalog.view','catalog.items.create','catalog.items.update','catalog.items.toggle_status','catalog.items.update_price','catalog.items.configure_characteristics','catalog.categories.manage','catalog.units.manage','catalog.characteristics.manage'];
        foreach (['view','create','update','issue','send','manage_status','create_revision','download_pdf'] as $action) { $codes[] = 'quotations.'.$action; }
        foreach ($codes as $code) {
            [$module, $action] = explode('.', $code, 2);
            $permission = (new Permission())->setCode($code)->setModule($module)->setAction($action)->setName($code);
            $em->persist($permission); $role->addPermission($permission);
        }
        $user = (new User())->setUsername('qa-local')->setFullName('QA local')->setEmail('qa@example.invalid')->setMustChangePassword(false)->setPassword(password_hash('qa-local-only-2026', PASSWORD_BCRYPT))->addRole($role);
        $clientClass = new ClientClass('C', 'Clase C QA');
        $client = (new Client())->setBusinessName('Cliente QA con nombre comercial extenso para revisar la captura')->setEmail('client@example.invalid')->setClientClass($clientClass);
        $contact = (new ClientContact($client, (new Contact('Contacto de prueba'))->setLastName('Con apellido extenso')))->setEmail('contact@example.invalid')->setIsPrimary(true);
        $contact->confirmEmail();
        $fiscal = (new ClientAddress($client, new Address('Avenida de prueba con una descripción extensa', '123', '76000', 'Querétaro')))->setLabel('Domicilio fiscal de QA')->setIsFiscalAddress(true);
        $delivery = (new ClientAddress($client, new Address('Calle de entrega de prueba con nombre largo', '456', '76000', 'Querétaro')))->setLabel('Domicilio de entrega de QA')->setIsDeliveryAddress(true);
        $category = (new CommercialCategory())->setCode('QA')->setName('Gran formato QA');
        $area = (new MeasurementUnit())->setCode('M2')->setName('Metro cuadrado')->setSymbol('m²')->setDimensionType('AREA')->setDecimalScale(4)->setAllowsFraction(true);
        $piece = (new MeasurementUnit())->setCode('PZA')->setName('Pieza')->setSymbol('pza')->setDecimalScale(0)->setAllowsFraction(false);
        // Synthetic prices exist only in the isolated QA database.
        $lona = (new CommercialItem())->setCode('QA-LONA')->setName('Lona QA')->setType(CommercialItemType::PRODUCT)->setCategory($category)->setMeasurementUnit($area)->setBasePrice('100')->setQuotationSpecificationProfile(QuotationItemSpecificationProfile::LARGE_FORMAT);
        $vinil = (new CommercialItem())->setCode('QA-VINIL')->setName('Vinil QA')->setType(CommercialItemType::PRODUCT)->setCategory($category)->setMeasurementUnit($area)->setBasePrice('100')->setQuotationSpecificationProfile(QuotationItemSpecificationProfile::LARGE_FORMAT);
        $banner = (new CommercialItem())->setCode('QA-BANNER')->setName('Banner QA')->setType(CommercialItemType::PRODUCT)->setCategory($category)->setMeasurementUnit($piece)->setBasePrice('200')->setQuotationSpecificationProfile(QuotationItemSpecificationProfile::LARGE_FORMAT);
        $tier = (new ItemPriceRule())->setCommercialItem($lona)->setRuleType(ItemPriceRuleType::QUANTITY_TIER)->setMinQuantity('2')->setUnitPrice('80');
        foreach ([$role,$user,$clientClass,$client,$contact,$fiscal,$delivery,$category,$area,$piece,$lona,$vinil,$banner,$tier] as $entity) { $em->persist($entity); }
        foreach (['FINISHED_WIDTH_CM'=>'Ancho terminado','FINISHED_HEIGHT_CM'=>'Alto terminado'] as $code=>$name) {
            $characteristic = (new CommercialCharacteristic())->setCode($code)->setName($name)->setInputType(CommercialCharacteristicInputType::DECIMAL)->setUnitLabel('cm');
            $em->persist($characteristic);
            foreach ([$lona,$vinil,$banner] as $item) {
                $em->persist((new CommercialItemCharacteristic())->setCommercialItem($item)->setCharacteristic($characteristic)->setIsRequired(true));
            }
        }
        $em->flush();
        foreach (QuotationStatus::cases() as $status) { $em->getConnection()->insert('quotation_statuses', ['code'=>$status->value,'name'=>$status->value,'display_order'=>0,'is_terminal'=>0,'is_active'=>1]); }
        return compact('user','clientClass','client','contact','fiscal','delivery','category','area','piece','lona','vinil','banner','tier');
    }
}
