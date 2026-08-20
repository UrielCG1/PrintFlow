<?php
declare(strict_types=1);
namespace App\Service\Quotations;
use App\Entity\Quotations\Quotation;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
final class PublicQuoteRequestMailer
{
 public function __construct(private readonly MailerInterface $mailer,private readonly PublicQuoteRequestPdfRenderer $pdf,private readonly string $fromAddress,private readonly string $fromName,private readonly string $privacyResponsible,private readonly string $privacyAddress,private readonly string $privacyEmail,private readonly UrlGeneratorInterface $urlGenerator){}
 public function send(Quotation $quotation):void{$email=(new TemplatedEmail())->from(new Address($this->fromAddress,$this->fromName))->to(new Address((string)$quotation->getRequestEmail(),(string)$quotation->getRequestContactName()))->subject(sprintf('Cotización %s | Ooxcorp',$quotation->getRequestReference()))->htmlTemplate('emails/quotations/public_quote_request.html.twig')->context(['quotation'=>$quotation,'privacyResponsible'=>$this->privacyResponsible,'privacyAddress'=>$this->privacyAddress,'privacyEmail'=>$this->privacyEmail,'privacyUrl'=>$this->urlGenerator->generate('app_privacy_notice',[],UrlGeneratorInterface::ABSOLUTE_URL)])->attach($this->pdf->render($quotation),$this->pdf->filename($quotation),'application/pdf');$this->mailer->send($email);}
}
