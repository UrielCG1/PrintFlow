<?php

namespace App\Application\Quotations;

use App\Entity\Clients\Client;
use Symfony\Component\Validator\Constraints as Assert;
use App\Entity\Quotations\Quotation;

final class QuotationData
{
    #[Assert\NotNull(message: 'Selecciona un cliente.')]
    public ?Client $client = null;

    #[Assert\NotNull(message: 'Captura la fecha de vigencia.')]
    public ?\DateTimeImmutable $expiresAt = null;

    #[Assert\Length(max: 5000)]
    public ?string $notes = null;

    #[Assert\Regex(
        pattern: '/^(?:0|[1-9]\d{0,2})(?:[.,]\d{1,2})?$/',
        message: 'El descuento debe usar hasta dos decimales.',
    )]
    public ?string $discountPercent = null;

    /**
     * @var list<QuotationItemData>
     */
    #[Assert\Count(
        min: 1,
        max: 100,
        minMessage: 'Agrega al menos una partida.',
        maxMessage: 'Una cotización no puede tener más de {{ limit }} partidas.',
    )]
    #[Assert\Valid]
    public array $items = [];

    public function __construct()
    {
        $timezone = new \DateTimeZone('America/Mexico_City');

        $this->expiresAt = new \DateTimeImmutable('+7 days', $timezone);
    }

    public function addItem(QuotationItemData $item): void
    {
        $this->items[] = $item;
    }

    public function removeItem(QuotationItemData $item): void
    {
        foreach ($this->items as $index => $currentItem) {
            if ($currentItem === $item) {
                unset($this->items[$index]);
                $this->items = array_values($this->items);

                return;
            }
        }
    }
    public static function fromQuotation(Quotation $quotation): self
    {
        $data = new self();

        $data->client = $quotation->getClient();
        $data->expiresAt = $quotation->getExpiresAt();
        $data->notes = $quotation->getNotes();
        $data->discountPercent = $quotation->getDiscountPercent();

        foreach ($quotation->getItems() as $quotationItem) {
            $item = new QuotationItemData();
            $item->commercialItem = $quotationItem->getCommercialItem();
            $item->commercialCategory = $quotationItem->getCommercialItem()->getCategory();
            $item->quantity = $quotationItem->getQuantity();
            $specifications = $quotationItem->getSpecificationsSnapshot();
            $item->specifications = [];

            if (($specifications['profile'] ?? null) === QuotationItemCharacteristicsSpecificationResolver::PROFILE) {
                foreach ($specifications['values'] ?? [] as $value) {
                    if (!is_array($value)
                        || !isset($value['field_key'])
                        || !array_key_exists('submitted_value', $value)) {
                        continue;
                    }

                    $item->specifications[(string) $value['field_key']] = (string) $value['submitted_value'];
                }

                $largeFormatValues = $specifications['large_format']['values'] ?? null;
                if (is_array($largeFormatValues)) {
                    foreach ($largeFormatValues as $field => $value) {
                        if (in_array($field, ['finished_width_cm', 'finished_height_cm'], true)) {
                            $item->specifications[$field] = (string) $value;
                        }
                    }
                }
            } elseif (is_array($specifications['values'] ?? null)) {
                $item->specifications = $specifications['values'];
            }
            $item->quantityMode = ($specifications['billing_quantity']['source'] ?? null) === 'DIMENSIONS'
                ? QuotationItemData::QUANTITY_MODE_AUTO
                : QuotationItemData::QUANTITY_MODE_MANUAL;

            $data->addItem($item);
        }

        return $data;
    }
}
