<?php

namespace App\Application\Suppliers;

use App\Entity\Suppliers\Supplier;
use App\Entity\Users\User;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\Materials\MaterialRepository;
use App\Application\Clients\ClientPhoneData;
use App\Entity\Suppliers\{SupplierAddress,SupplierBranch,SupplierContact,SupplierPhone};
use App\Entity\Common\{Address,Contact,ContactPhone,Phone};

final class SupplierManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly MaterialRepository $materialRepository,
    ) {
    }

    public function create(SupplierData $data, User $actor): Supplier
    {
        return $this->entityManager->wrapInTransaction(function () use ($data, $actor): Supplier {
            $supplier = new Supplier();
            $this->applyData($supplier, $data);

            $this->entityManager->persist($supplier);
            $this->entityManager->flush();
            $this->syncStructuredData($supplier,$data); $this->entityManager->flush();

            $this->auditLogger->record(
                actor: $actor,
                action: 'supplier.created',
                entityType: 'supplier',
                entityId: $supplier->getId(),
                newValues: $this->snapshot($supplier),
            );

            $this->entityManager->flush();

            return $supplier;
        });
    }

    public function update(Supplier $supplier, SupplierData $data, User $actor): void
    {
        $this->entityManager->wrapInTransaction(function () use ($supplier, $data, $actor): void {
            $oldValues = $this->snapshot($supplier);

            $this->applyData($supplier, $data);
            $this->syncStructuredData($supplier,$data);

            $newValues = $this->snapshot($supplier);

            if ($oldValues === $newValues) {
                return;
            }

            $this->auditLogger->record(
                actor: $actor,
                action: 'supplier.updated',
                entityType: 'supplier',
                entityId: $supplier->getId(),
                oldValues: $oldValues,
                newValues: $newValues,
            );

            $this->entityManager->flush();
        });
    }

    public function setActive(Supplier $supplier, bool $isActive, User $actor): void
    {
        if ($supplier->isActive() === $isActive) {
            return;
        }

        if (
            !$isActive
            && $this->materialRepository->hasActiveForPrimarySupplier($supplier)
        ) {
            throw new \DomainException(
                'No puedes desactivar un proveedor que es principal de materiales activos.',
            );
        }

        $this->entityManager->wrapInTransaction(function () use ($supplier, $isActive, $actor): void {
            $oldValues = $this->snapshot($supplier);

            $supplier->setIsActive($isActive);

            $this->auditLogger->record(
                actor: $actor,
                action: $isActive ? 'supplier.activated' : 'supplier.deactivated',
                entityType: 'supplier',
                entityId: $supplier->getId(),
                oldValues: $oldValues,
                newValues: $this->snapshot($supplier),
            );

            $this->entityManager->flush();
        });
    }

    private function applyData(Supplier $supplier, SupplierData $data): void
    {
        $supplier
            ->setCode((string) $data->code)
            ->setBusinessName((string) $data->businessName)
            ->setLegalName($data->legalName)
            ->setTaxId($data->taxId)
            ->setTaxRegimeCode($data->taxRegimeCode)->setBillingEmail($data->billingEmail)->setDefaultCfdiUseCode($data->defaultCfdiUseCode)
            ->setBusinessActivity($data->businessActivity)
            ->setWebsite($data->website)
            ->setEmail($data->email)
            ->setNotes($data->notes);
    }

    private function syncStructuredData(Supplier $supplier,SupplierData $data):void
    {
        $this->syncPhones($supplier,null,$data->phones);foreach($this->entityManager->getRepository(SupplierContact::class)->findBy(['supplier'=>$supplier]) as $contact){$contact->setIsPrimary(false);}foreach($this->entityManager->getRepository(SupplierBranch::class)->findBy(['supplier'=>$supplier]) as $branch){$branch->setIsMain(false);}$this->entityManager->flush();
        $existing=[];foreach($this->entityManager->getRepository(SupplierBranch::class)->findBy(['supplier'=>$supplier]) as $branch){$existing[(int)$branch->getId()]=$branch;}$kept=[];
        foreach($data->branches as $d){$branch=$d->id?($existing[$d->id]??null):null;if(!$branch){$branch=new SupplierBranch($supplier,(string)$d->code,(string)$d->name);$this->entityManager->persist($branch);}$branch->setCode((string)$d->code)->setName((string)$d->name)->setEmail($d->email)->setNotes($d->notes)->setIsMain($d->isMain)->setIsActive(true);$this->entityManager->flush();$kept[(int)$branch->getId()]=true;$this->syncPhones($supplier,$branch,$d->phones);$this->syncAddresses($supplier,$branch,$d->addresses);$this->syncContacts($supplier,$branch,$d->contacts);}
        foreach($existing as $id=>$branch){if(!isset($kept[$id])){$branch->setIsActive(false)->setIsMain(false);foreach($this->entityManager->getRepository(SupplierPhone::class)->findBy(['supplier'=>$supplier,'branch'=>$branch]) as $a){$a->setIsActive(false);}foreach($this->entityManager->getRepository(SupplierAddress::class)->findBy(['supplier'=>$supplier,'branch'=>$branch]) as $a){$a->setIsActive(false);}foreach($this->entityManager->getRepository(SupplierContact::class)->findBy(['supplier'=>$supplier,'branch'=>$branch]) as $a){$a->setIsActive(false);}}}
    }
    /** @param list<ClientPhoneData> $rows */ private function syncPhones(Supplier $supplier,?SupplierBranch $branch,array $rows):void{$repo=$this->entityManager->getRepository(SupplierPhone::class);$existing=[];foreach($repo->findBy(['supplier'=>$supplier,'branch'=>$branch]) as $a){$existing[(int)$a->getId()]=$a;$a->setIsPrimary(false);}$kept=[];foreach($rows as $d){$a=$d->id?($existing[$d->id]??null):null;if(!$a){$a=(new SupplierPhone($supplier,new Phone($d->type,(string)$d->number)))->setBranch($branch);$this->entityManager->persist($a);}$this->applyPhone($a->getPhone(),$d);$a->setLabel($d->label)->setIsPrimary($d->isPrimary)->setIsActive(true);if($a->getId())$kept[$a->getId()]=true;}foreach($existing as $id=>$a){if(!isset($kept[$id]))$a->setIsActive(false);}}
    /** @param list<\App\Application\Clients\ClientBranchAddressData> $rows */ private function syncAddresses(Supplier $supplier,SupplierBranch $branch,array $rows):void{$repo=$this->entityManager->getRepository(SupplierAddress::class);$existing=[];foreach($repo->findBy(['supplier'=>$supplier,'branch'=>$branch]) as $a){$existing[(int)$a->getId()]=$a;$a->setIsDefault(false);}$kept=[];foreach($rows as $d){$a=$d->id?($existing[$d->id]??null):null;if(!$a){$address=new Address((string)$d->street,(string)$d->exteriorNumber,(string)$d->postalCode,(string)$d->city);$a=(new SupplierAddress($supplier,$address,$d->type))->setBranch($branch);$this->entityManager->persist($address);$this->entityManager->persist($a);}$address=$a->getAddress();$address->setStreet((string)$d->street)->setExteriorNumber((string)$d->exteriorNumber)->setInteriorNumber($d->interiorNumber)->setNeighborhood($d->neighborhood)->setPostalCode((string)$d->postalCode)->setCity((string)$d->city)->setState($d->state)->setCountryCode($d->countryCode)->setNotes($d->notes);$a->setAddressType($d->type)->setDeliveryZone($d->type==='DELIVERY'?$d->deliveryZone:null)->setDeliveryCost($d->type==='DELIVERY'&&$d->deliveryCost!==null?(string)$d->deliveryCost:null)->setIsDefault($d->isDefault)->setIsActive(true);if($d->type==='FISCAL'&&$d->isDefault)$supplier->setFiscalAddress($address);if($a->getId())$kept[$a->getId()]=true;}foreach($existing as $id=>$a){if(!isset($kept[$id]))$a->setIsActive(false);}}
    /** @param list<SupplierInlineContactData> $rows */ private function syncContacts(Supplier $supplier,SupplierBranch $branch,array $rows):void{$repo=$this->entityManager->getRepository(SupplierContact::class);$existing=[];foreach($repo->findBy(['supplier'=>$supplier,'branch'=>$branch]) as $a){$existing[(int)$a->getId()]=$a;$a->setIsPrimary(false);}$kept=[];foreach($rows as $d){$a=$d->id?($existing[$d->id]??null):null;if(!$a){$person=new Contact((string)$d->firstName);$a=(new SupplierContact($supplier,$person))->setBranch($branch);$this->entityManager->persist($person);$this->entityManager->persist($a);}$person=$a->getContact();$person->setFirstName((string)$d->firstName)->setLastName($d->lastName)->setPersonalEmail($d->personalEmail)->setBirthDate($d->birthDate)->setWorkDays($d->workDays)->setWorkHours($d->workHours)->setNotes($d->notes)->setIsActive(true);$a->setDepartment($d->department)->setPosition($d->position)->setBusinessEmail($d->businessEmail)->setNotes($d->notes)->setCanSellProducts($d->canSellProducts)->setIsPrimary($d->isPrimary)->setIsActive(true);$this->syncContactPhones($person,$d->phones);if($a->getId())$kept[$a->getId()]=true;}foreach($existing as $id=>$a){if(!isset($kept[$id]))$a->setIsActive(false);}}
    /** @param list<ClientPhoneData> $rows */ private function syncContactPhones(Contact $contact,array $rows):void{$existing=[];foreach($contact->getPhones() as $a){$existing[(int)$a->getId()]=$a;$a->setIsPrimary(false);}$kept=[];foreach($rows as $d){$a=$d->id?($existing[$d->id]??null):null;if(!$a){$a=new ContactPhone($contact,new Phone($d->type,(string)$d->number));$this->entityManager->persist($a);}$this->applyPhone($a->getPhone(),$d);$a->setLabel($d->label)->setIsPrimary($d->isPrimary)->setIsActive(true);if($a->getId())$kept[$a->getId()]=true;}foreach($existing as $id=>$a){if(!isset($kept[$id]))$a->setIsActive(false);}}
    private function applyPhone(Phone $phone,ClientPhoneData $d):void{$phone->setPhoneType($d->type)->setCountryCode($d->countryCode)->setAreaCode($d->areaCode)->setNumber((string)$d->number)->setExtension($d->extension)->setNotes($d->notes);}

    /**
     * @return array<string, bool|string|null>
     */
    private function snapshot(Supplier $supplier): array
    {
        return [
            'code' => $supplier->getCode(),
            'business_name' => $supplier->getBusinessName(),
            'legal_name' => $supplier->getLegalName(),
            'tax_id' => $supplier->getTaxId(),
            'business_activity' => $supplier->getBusinessActivity(),
            'website' => $supplier->getWebsite(),
            'email' => $supplier->getEmail(),
            'phone' => $supplier->getPhone(),
            'notes' => $supplier->getNotes(),
            'is_active' => $supplier->isActive(),
        ];
    }
}
