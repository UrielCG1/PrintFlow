<?php
declare(strict_types=1);
namespace App\Service\Quotations;
use App\Entity\Quotations\QuoteRequest;
use Dompdf\Dompdf; use Dompdf\Options; use Twig\Environment;
final class PublicQuoteRequestPdfRenderer
{
 public function __construct(private readonly Environment $twig,private readonly string $issuerName,private readonly string $issuerTaxId,private readonly string $issuerEmail,private readonly string $issuerPhone,private readonly string $issuerAddress){}
 public function render(QuoteRequest $request):string{$lines=[];$subtotal=0.0;foreach($request->getItems() as $index=>$item){$unitPrice=100.0;$lineSubtotal=$unitPrice*$item->getQuantity();$subtotal+=$lineSubtotal;$lines[]=['number'=>$index+1,'code'=>$item->getProduct()?->getCode(),'name'=>$item->getProduct()?->getName()??'Producto','quantity'=>$item->getQuantity(),'unit_price'=>$unitPrice,'subtotal'=>$lineSubtotal,'notes'=>$item->getNotes()];}$tax=round($subtotal*.16,2);$options=new Options();$options->setDefaultFont('DejaVu Sans');$options->setIsRemoteEnabled(false);$options->setIsPhpEnabled(false);$pdf=new Dompdf($options);$pdf->loadHtml($this->twig->render('public_quote_request/pdf.html.twig',['request'=>$request,'lines'=>$lines,'subtotal'=>$subtotal,'tax'=>$tax,'total'=>$subtotal+$tax,'issuedAt'=>new \DateTimeImmutable('now',new \DateTimeZone('America/Mexico_City')),'expiresAt'=>new \DateTimeImmutable('+7 days',new \DateTimeZone('America/Mexico_City')),'issuer'=>['name'=>$this->issuerName,'tax_id'=>$this->issuerTaxId,'email'=>$this->issuerEmail,'phone'=>$this->issuerPhone,'address'=>$this->issuerAddress]]),'UTF-8');$pdf->setPaper('letter','portrait');$pdf->render();return $pdf->output();}
 public function filename(QuoteRequest $request):string{return 'Cotizacion-demostrativa-'.$request->getFolio().'.pdf';}
}
