<?php
declare(strict_types=1);
namespace App\Service\Quotations;
use App\Entity\Clients\ClientContact;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
final class PublicCustomerNumberMailer
{
 public function __construct(private readonly MailerInterface $mailer,private readonly string $fromAddress,private readonly string $fromName){}
 /** @param list<ClientContact> $contacts */
 public function send(string $email,array $contacts):void{$message=(new TemplatedEmail())->from(new Address($this->fromAddress,$this->fromName))->to($email)->subject('Tu número de cliente | PrintFlow')->htmlTemplate('emails/quotations/customer_number_recovery.html.twig')->context(['contacts'=>$contacts]);$this->mailer->send($message);}
}
