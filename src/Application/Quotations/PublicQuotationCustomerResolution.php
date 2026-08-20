<?php
declare(strict_types=1);
namespace App\Application\Quotations;
use App\Entity\Clients\{Client,ClientContact};
final readonly class PublicQuotationCustomerResolution
{
 public function __construct(public Client $client,public ClientContact $contact){}
}
