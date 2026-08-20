<?php
declare(strict_types=1);
namespace App\Application\Quotations;
use App\Entity\Clients\{Client,ClientCategory,ClientContact};
use App\Entity\Common\Contact;
use App\Repository\Clients\ClientRepository;
use Doctrine\ORM\EntityManagerInterface;
final class PublicQuotationClientResolver
{
 public function __construct(private readonly ClientRepository $clients,private readonly EntityManagerInterface $em){}

 public function resolve(PublicQuotationRequestData $data,?ClientContact $verifiedContact=null):PublicQuotationCustomerResolution
 {
  if($verifiedContact instanceof ClientContact)return new PublicQuotationCustomerResolution($verifiedContact->getClient(),$verifiedContact);

  $lookupName=trim((string)$data->companyName)?:trim((string)$data->fullName);
  $client=$this->clients->findPublicMatch($lookupName,(string)$data->email,(string)$data->phone);
  if(!$client instanceof Client)$client=$this->createClient($data);

  $contact=$this->findMatchingContact($client,(string)$data->email,(string)$data->phone);
  if(!$contact instanceof ClientContact)$contact=$this->createContact($client,$data);

  $this->em->flush();
  return new PublicQuotationCustomerResolution($client,$contact);
 }

 private function createClient(PublicQuotationRequestData $data):Client
 {
  $category=$this->em->getRepository(ClientCategory::class)->findOneBy(['code'=>'PROSPECT_NO_PURCHASE','isActive'=>true]);
  if(!$category instanceof ClientCategory)throw new \DomainException('No está configurada la categoría de clientes prospecto.');
  $hasCompany=trim((string)$data->companyName)!=='';
  $client=(new Client())->setBusinessName($hasCompany?trim((string)$data->companyName):trim((string)$data->fullName))->setClientType($hasCompany?'COMPANY':'INDIVIDUAL')->setCategory($category)->setNotes('Creado automáticamente desde una solicitud pública de cotización.');
  if(!$hasCompany)$client->setEmail($data->email)->setPhone($data->phone);
  $this->em->persist($client);
  $this->em->flush();
  return $client;
 }

 private function createContact(Client $client,PublicQuotationRequestData $data):ClientContact
 {
  $parts=preg_split('/\s+/',trim((string)$data->fullName),2)?:[];
  $person=(new Contact($parts[0]??'Contacto'))->setLastName($parts[1]??null)->setPersonalEmail($data->email)->setPrimaryPhone($data->phone);
  $contact=(new ClientContact($client,$person))->setEmail($data->email)->setPhone($data->phone)->setDepartment('Contacto comercial')->setJobTitle('Solicitante')->setIsPrimary($this->hasNoActiveContacts($client))->setCanRequestProducts(true);
  $this->em->persist($contact);
  return $contact;
 }

 private function findMatchingContact(Client $client,string $email,string $phone):?ClientContact
 {
  $email=strtolower(trim($email));$digits=preg_replace('/\D+/','',$phone)??'';
  $id=$this->em->getConnection()->fetchOne("SELECT cc.id FROM client_contacts cc INNER JOIN contacts c ON c.id=cc.contact_id LEFT JOIN contact_phones cp ON cp.contact_id=c.id AND cp.is_active=1 LEFT JOIN phones p ON p.id=cp.phone_id WHERE cc.client_id=:client AND cc.is_active=1 AND ((:email<>'' AND (LOWER(cc.business_email)=:email OR LOWER(c.personal_email)=:email)) OR (:phone<>'' AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(p.number,' ',''),'-',''),'(',''),')',''),'+','')=:phone)) LIMIT 1",['client'=>$client->getId(),'email'=>$email,'phone'=>$digits]);
  return $id===false?null:$this->em->getRepository(ClientContact::class)->find((int)$id);
 }

 private function hasNoActiveContacts(Client $client):bool
 {
  return (int)$this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM client_contacts WHERE client_id=:client AND is_active=1',['client'=>$client->getId()])===0;
 }
}
