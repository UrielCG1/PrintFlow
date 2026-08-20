<?php
declare(strict_types=1);
namespace App\Service\Quotations;
use App\Entity\Quotations\Quotation;
use Dompdf\{Dompdf,Options};
use Twig\Environment;
final class PublicQuoteRequestPdfRenderer
{
 public function __construct(private readonly Environment $twig,private readonly string $issuerName,private readonly string $issuerTaxId,private readonly string $issuerEmail,private readonly string $issuerPhone,private readonly string $issuerAddress){}
 public function render(Quotation $quotation):string{$lines=[];foreach($quotation->getItems() as $item){$snapshot=$item->getCommercialItemSnapshot();$details=$item->getRequestDetails()??[];$lines[]=['number'=>$item->getLineNumber(),'code'=>$snapshot['code']??null,'name'=>$snapshot['name']??'Producto','quantity'=>$item->getQuantity(),'unit_price'=>$item->getUnitPrice(),'subtotal'=>$item->getLineSubtotal(),'notes'=>$details['notes']??null];}$options=new Options();$options->setDefaultFont('DejaVu Sans');$options->setIsRemoteEnabled(false);$options->setIsPhpEnabled(false);$pdf=new Dompdf($options);$pdf->loadHtml($this->twig->render('public_quote_request/pdf.html.twig',['quotation'=>$quotation,'lines'=>$lines,'issuer'=>['name'=>$this->issuerName,'tax_id'=>$this->issuerTaxId,'email'=>$this->issuerEmail,'phone'=>$this->issuerPhone,'address'=>$this->issuerAddress]]),'UTF-8');$pdf->setPaper('letter','portrait');$pdf->render();return $pdf->output();}
 public function filename(Quotation $quotation):string{return 'Cotizacion-'.$quotation->getRequestReference().'.pdf';}
}
