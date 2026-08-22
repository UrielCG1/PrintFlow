<?php

namespace App\Application\Clients;

use App\Entity\Clients\Client;
use App\Entity\Users\User;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Clients\{ClientAddress,ClientBranch,ClientBranchAddress,ClientBranchPhone,ClientContact,ClientPhone};
use App\Entity\Common\{Address,Contact,ContactPhone,Phone};

final class ClientManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function create(ClientData $data, User $actor): Client
    {
        $this->validateContactEmails($data);
        return $this->entityManager->wrapInTransaction(function () use ($data, $actor): Client {
            $client = new Client();
            $this->applyData($client, $data);

            $this->entityManager->persist($client);
            $this->entityManager->flush();
            $this->syncStructuredData($client, $data);
            $this->synchronizeIndividualHolder($client, $data);
            $this->entityManager->flush();

            $this->auditLogger->record(
                actor: $actor,
                action: 'client.created',
                entityType: 'client',
                entityId: $client->getId(),
                newValues: $this->snapshot($client),
            );

            $this->entityManager->flush();

            return $client;
        });
    }

    public function update(Client $client, ClientData $data, User $actor): void
    {
        $this->validateContactEmails($data);
        $oldValues = $this->snapshot($client);

        $this->applyData($client, $data);
        $this->syncStructuredData($client, $data);
        $this->synchronizeIndividualHolder($client, $data);

        $newValues = $this->snapshot($client);

        if ($oldValues === $newValues) {
            return;
        }

        $this->entityManager->wrapInTransaction(function () use (
            $client,
            $actor,
            $oldValues,
            $newValues,
        ): void {
            $this->auditLogger->record(
                actor: $actor,
                action: 'client.updated',
                entityType: 'client',
                entityId: $client->getId(),
                oldValues: $oldValues,
                newValues: $newValues,
            );

            $this->entityManager->flush();
        });
    }

    public function setActive(Client $client, bool $isActive, User $actor): void
    {
        if ($client->isActive() === $isActive) {
            return;
        }

        $oldValues = $this->snapshot($client);
        $client->setIsActive($isActive);
        $newValues = $this->snapshot($client);

        $this->entityManager->wrapInTransaction(function () use (
            $client,
            $actor,
            $isActive,
            $oldValues,
            $newValues,
        ): void {
            $this->auditLogger->record(
                actor: $actor,
                action: $isActive ? 'client.activated' : 'client.deactivated',
                entityType: 'client',
                entityId: $client->getId(),
                oldValues: $oldValues,
                newValues: $newValues,
            );

            $this->entityManager->flush();
        });
    }

    private function applyData(Client $client, ClientData $data): void
    {
        $client
            ->setClientType($data->clientType)
            ->setBusinessName((string) $data->businessName)
            ->setTaxId($data->taxId)
            ->setLegalName($data->legalName)
            ->setBusinessActivity($data->businessActivity)
            ->setWebsite($data->website)
            ->setBirthDate($data->birthDate)
            ->setTaxRegimeCode($data->taxRegimeCode)
            ->setBillingEmail($data->billingEmail)
            ->setDefaultCfdiUseCode($data->defaultCfdiUseCode)
            ->setCategory($data->category)
            ->setEmail($data->email)
            ->setNotes($data->notes);
    }

    private function validateContactEmails(ClientData $data): void
    {
        $repository = $this->entityManager->getRepository(ClientContact::class);
        $seen = [];
        foreach ($data->branches as $branch) {
            foreach ($branch->contacts as $contact) {
                $email = strtolower(trim((string) $contact->businessEmail));
                if ($email === '') { throw new \DomainException('El correo laboral de cada contacto es obligatorio.'); }
                if (isset($seen[$email])) { throw new \DomainException(sprintf('El correo laboral %s está repetido en el formulario.', $email)); }
                $seen[$email] = true;
                if ($repository->findOneByBusinessEmail($email, $contact->id) !== null) { throw new \DomainException(sprintf('El correo laboral %s ya está registrado en otro contacto.', $email)); }
            }
        }
    }

    private function synchronizeIndividualHolder(Client $client, ClientData $data): void
    {
        if ($data->clientType !== 'INDIVIDUAL') {
            $formerHolder = $client->getIndividualHolderContact();
            if ($formerHolder !== null) { $formerHolder->setDepartment('Contacto comercial'); if ($formerHolder->getJobTitle() === 'Titular del cliente') { $formerHolder->setJobTitle(null); } }
            $client->setIndividualHolderContact(null);
            return;
        }
        if ($data->branches !== []) { throw new \DomainException('Una persona física no puede tener sucursales.'); }
        $email = strtolower(trim((string) $data->email));
        if ($email === '') { throw new \DomainException('El correo del titular es obligatorio.'); }
        $holder = $client->getIndividualHolderContact();
        if ($this->entityManager->getRepository(ClientContact::class)->findOneByBusinessEmail($email, $holder?->getId()) !== null) { throw new \DomainException('El correo del titular ya está registrado en otro contacto.'); }
        if ($holder === null) {
            $parts = preg_split('/\s+/', trim((string) $data->businessName), 2) ?: [];
            $person = new Contact($parts[0] ?? 'Cliente');
            $holder = new ClientContact($client, $person);
            $this->entityManager->persist($person); $this->entityManager->persist($holder); $client->setIndividualHolderContact($holder);
        }
        $parts = preg_split('/\s+/', trim((string) $data->businessName), 2) ?: [];
        $holder->getContact()->setFirstName($parts[0] ?? 'Cliente')->setLastName($parts[1] ?? null)->setPersonalEmail($email)->setBirthDate($data->birthDate)->setIsActive(true);
        $holder->setEmail($email)->setPhone($client->getPhone())->setDepartment('Titular')->setJobTitle('Titular del cliente')->setCanRequestProducts(true)->setIsPrimary(true)->setIsActive(true);
        $client->setBusinessName($holder->getFullName())->setEmail($email);
    }

    private function syncStructuredData(Client $client, ClientData $data): void
    {
        $this->syncClientPhones($client, $data->phones);
        foreach($this->entityManager->getRepository(ClientContact::class)->findBy(['client'=>$client]) as $contact){$contact->setIsPrimary(false);}
        foreach($this->entityManager->getRepository(ClientAddress::class)->findBy(['client'=>$client]) as $address){$address->setIsDefaultFiscal(false)->setIsDefaultDelivery(false);}
        $this->entityManager->flush();
        $existingBranches = [];
        foreach ($this->entityManager->getRepository(ClientBranch::class)->findBy(['client'=>$client]) as $branch) { $existingBranches[(int)$branch->getId()]=$branch; $branch->setIsMain(false); }
        $kept=[];
        foreach ($data->branches as $branchData) {
            $branch = $branchData->id ? ($existingBranches[$branchData->id] ?? null) : null;
            if (!$branch) { $branch=new ClientBranch($client,(string)$branchData->code,(string)$branchData->name); $this->entityManager->persist($branch); }
            $branch->setCode((string)$branchData->code)->setName((string)$branchData->name)->setCategory($branchData->category)->setEmail($branchData->email)->setNotes($branchData->notes)->setIsMain($branchData->isMain)->setIsActive(true);
            $this->entityManager->flush(); $kept[(int)$branch->getId()]=true;
            $this->syncBranchPhones($branch,$branchData->phones); $this->syncBranchAddresses($branch,$branchData->addresses); $this->syncBranchContacts($client,$branch,$branchData->contacts);
        }
        foreach ($existingBranches as $id=>$branch) { if(!isset($kept[$id])){$branch->setIsActive(false)->setIsMain(false);foreach($this->entityManager->getRepository(ClientBranchPhone::class)->findBy(['branch'=>$branch]) as $phone){$phone->setIsActive(false);}foreach($this->entityManager->getRepository(ClientContact::class)->findBy(['client'=>$client,'branch'=>$branch]) as $contact){$contact->setIsActive(false);}foreach($this->entityManager->getRepository(ClientBranchAddress::class)->findBy(['branch'=>$branch]) as $address){$address->setIsActive(false);$this->entityManager->getRepository(ClientAddress::class)->findOneBy(['client'=>$client,'address'=>$address->getAddress()])?->setIsActive(false);}} }
    }

    /** @param list<ClientPhoneData> $rows */
    private function syncClientPhones(Client $client,array $rows):void
    {
        $existing=[]; foreach($client->getPhones() as $a){$existing[(int)$a->getId()]=$a;$a->setIsPrimary(false);} $kept=[];
        foreach($rows as $d){$a=$d->id?($existing[$d->id]??null):null;if(!$a){$a=new ClientPhone($client,new Phone($d->type,(string)$d->number));$this->entityManager->persist($a);} $this->applyPhone($a->getPhone(),$d);$a->setLabel($d->label)->setIsPrimary($d->isPrimary)->setIsActive(true);if($a->getId())$kept[$a->getId()]=true;}
        foreach($existing as $id=>$a){if(!isset($kept[$id]))$a->setIsActive(false);}
    }
    /** @param list<ClientPhoneData> $rows */
    private function syncBranchPhones(ClientBranch $branch,array $rows):void
    {
        $repo=$this->entityManager->getRepository(ClientBranchPhone::class);$existing=[];foreach($repo->findBy(['branch'=>$branch]) as $a){$existing[(int)$a->getId()]=$a;$a->setIsPrimary(false);} $kept=[];
        foreach($rows as $d){$a=$d->id?($existing[$d->id]??null):null;if(!$a){$a=new ClientBranchPhone($branch,new Phone($d->type,(string)$d->number));$this->entityManager->persist($a);$this->entityManager->persist($a->getPhone());}$this->applyPhone($a->getPhone(),$d);$a->setLabel($d->label)->setIsPrimary($d->isPrimary)->setIsActive(true);if($a->getId())$kept[$a->getId()]=true;}
        foreach($existing as $id=>$a){if(!isset($kept[$id]))$a->setIsActive(false);}
    }
    /** @param list<ClientBranchAddressData> $rows */
    private function syncBranchAddresses(ClientBranch $branch,array $rows):void
    {
        $repo=$this->entityManager->getRepository(ClientBranchAddress::class);$existing=[];foreach($repo->findBy(['branch'=>$branch]) as $a){$existing[(int)$a->getId()]=$a;$a->setIsDefault(false);} $kept=[];
        foreach($rows as $d){$a=$d->id?($existing[$d->id]??null):null;if(!$a){$address=new Address((string)$d->street,(string)$d->exteriorNumber,(string)$d->postalCode,(string)$d->city);$a=new ClientBranchAddress($branch,$address,$d->type);$this->entityManager->persist($address);$this->entityManager->persist($a);} $address=$a->getAddress();$address->setStreet((string)$d->street)->setExteriorNumber((string)$d->exteriorNumber)->setInteriorNumber($d->interiorNumber)->setNeighborhood($d->neighborhood)->setPostalCode((string)$d->postalCode)->setCity((string)$d->city)->setState($d->state)->setCountryCode($d->countryCode)->setNotes($d->notes);$a->setAddressType($d->type)->setDeliveryZone($d->type==='DELIVERY'?$d->deliveryZone:null)->setDeliveryCost($d->type==='DELIVERY'&&$d->deliveryCost!==null?(string)$d->deliveryCost:null)->setIsDefault($d->isDefault)->setIsActive(true);$clientAddress=$this->entityManager->getRepository(ClientAddress::class)->findOneBy(['client'=>$branch->getClient(),'address'=>$address]);if(!$clientAddress){$clientAddress=new ClientAddress($branch->getClient(),$address);$this->entityManager->persist($clientAddress);}$clientAddress->setLabel($branch->getName().' - '.$d->type)->setAddressType($d->type)->setDeliveryZone($d->type==='DELIVERY'?$d->deliveryZone:null)->setDeliveryCost($d->type==='DELIVERY'&&$d->deliveryCost!==null?(string)$d->deliveryCost:'0')->setIsActive(true);if($d->type==='FISCAL'){$clientAddress->setIsDefaultFiscal($d->isDefault);}if($d->type==='DELIVERY'){$clientAddress->setIsDefaultDelivery($d->isDefault);}if($d->type==='FISCAL'&&$d->isDefault){$branch->getClient()->setFiscalAddress($address);}if($a->getId())$kept[$a->getId()]=true;}
        foreach($existing as $id=>$a){if(!isset($kept[$id])){$a->setIsActive(false);$clientAddress=$this->entityManager->getRepository(ClientAddress::class)->findOneBy(['client'=>$branch->getClient(),'address'=>$a->getAddress()]);$clientAddress?->setIsActive(false);}}
    }
    /** @param list<ClientInlineContactData> $rows */
    private function syncBranchContacts(Client $client,ClientBranch $branch,array $rows):void
    {
        $repo=$this->entityManager->getRepository(ClientContact::class);$existing=[];foreach($repo->findBy(['client'=>$client,'branch'=>$branch]) as $a){$existing[(int)$a->getId()]=$a;$a->setIsPrimary(false);} $this->entityManager->flush();$kept=[];
        $submittedEmails=[];foreach($rows as $d){$email=strtolower(trim((string)$d->businessEmail));if($email===''||isset($submittedEmails[$email])){throw new \DomainException($email===''?'El correo laboral de cada contacto es obligatorio.':'El correo laboral no puede repetirse entre contactos.');}$submittedEmails[$email]=true;$a=$d->id?($existing[$d->id]??null):null;if($repo->findOneByBusinessEmail($email,$a?->getId())!==null){throw new \DomainException(sprintf('El correo laboral %s ya está registrado en otro contacto.',$email));}if(!$a){$person=new Contact((string)$d->firstName);$a=new ClientContact($client,$person);$this->entityManager->persist($person);$this->entityManager->persist($a);} $person=$a->getContact();$person->setFirstName((string)$d->firstName)->setLastName($d->lastName)->setPersonalEmail($d->personalEmail)->setBirthDate($d->birthDate)->setWorkDays($d->workDays)->setWorkHours($d->workHours)->setNotes($d->notes)->setIsActive(true);$a->setBranch($branch)->setDepartment($d->department)->setJobTitle($d->jobTitle)->setEmail($email)->setCanRequestProducts($d->canRequestProducts)->setIsPrimary($d->isPrimary)->setIsActive(true);$this->syncContactPhones($person,$d->phones);if($a->getId())$kept[$a->getId()]=true;}
        foreach($existing as $id=>$a){if(!isset($kept[$id]))$a->setIsActive(false);}
    }
    /** @param list<ClientPhoneData> $rows */
    private function syncContactPhones(Contact $contact,array $rows):void
    {
        $existing=[];foreach($contact->getPhones() as $a){$existing[(int)$a->getId()]=$a;$a->setIsPrimary(false);} $kept=[];foreach($rows as $d){$a=$d->id?($existing[$d->id]??null):null;if(!$a){$a=new ContactPhone($contact,new Phone($d->type,(string)$d->number));$this->entityManager->persist($a);}$this->applyPhone($a->getPhone(),$d);$a->setLabel($d->label)->setIsPrimary($d->isPrimary)->setIsActive(true);if($a->getId())$kept[$a->getId()]=true;}foreach($existing as $id=>$a){if(!isset($kept[$id]))$a->setIsActive(false);}
    }
    private function applyPhone(Phone $phone,ClientPhoneData $d):void{$phone->setPhoneType($d->type)->setCountryCode($d->countryCode)->setAreaCode($d->areaCode)->setNumber((string)$d->number)->setExtension($d->extension)->setNotes($d->notes);}
    /**
     * @return array<string, bool|float|int|string|null>
     */
    private function snapshot(Client $client): array
    {
        return [
            'client_type' => $client->getClientType(),
            'individual_holder_contact_id' => $client->getIndividualHolderContact()?->getId(),
            'business_name' => $client->getBusinessName(),
            'tax_id' => $client->getTaxId(),
            'legal_name' => $client->getLegalName(),
            'business_activity' => $client->getBusinessActivity(),
            'website' => $client->getWebsite(),
            'birth_date' => $client->getBirthDate()?->format('Y-m-d'),
            'tax_regime_code' => $client->getTaxRegimeCode(),
            'billing_email' => $client->getBillingEmail(),
            'default_cfdi_use_code' => $client->getDefaultCfdiUseCode(),
            'client_category_id' => $client->getCategory()?->getId(),
            'category_discount_percentage' => $client->getDefaultDiscountPercent(),
            'email' => $client->getEmail(),
            'phone' => $client->getPhone(),
            'notes' => $client->getNotes(),
            'is_active' => $client->isActive(),
        ];
    }
}
