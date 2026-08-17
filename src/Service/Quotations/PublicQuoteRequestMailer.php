<?php
declare(strict_types=1);
namespace App\Service\Quotations;
use App\Entity\Quotations\QuoteRequest; use Symfony\Bridge\Twig\Mime\TemplatedEmail; use Symfony\Component\Mailer\MailerInterface; use Symfony\Component\Mime\Address; use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
final class PublicQuoteRequestMailer
{
 public function __construct(private readonly MailerInterface $mailer,private readonly PublicQuoteRequestPdfRenderer $pdf,private readonly string $fromAddress,private readonly string $fromName,private readonly string $privacyResponsible,private readonly string $privacyAddress,private readonly string $privacyEmail,private readonly UrlGeneratorInterface $urlGenerator){}
 public function send(QuoteRequest $request):void{$email=(new TemplatedEmail())->from(new Address($this->fromAddress,$this->fromName))->to(new Address((string)$request->getEmail(),(string)$request->getFullName()))->subject(sprintf('Cotización demostrativa %s | PrintFlow',$request->getFolio()))->htmlTemplate('emails/quotations/public_quote_request.html.twig')->context(['request'=>$request,'privacyResponsible'=>$this->privacyResponsible,'privacyAddress'=>$this->privacyAddress,'privacyEmail'=>$this->privacyEmail,'acceptanceUrl'=>$this->urlGenerator->generate('quotation_public_accept',['token'=>$request->getPublicToken()],UrlGeneratorInterface::ABSOLUTE_URL)])->attach($this->pdf->render($request),$this->pdf->filename($request),'application/pdf');$this->mailer->send($email);}
}
