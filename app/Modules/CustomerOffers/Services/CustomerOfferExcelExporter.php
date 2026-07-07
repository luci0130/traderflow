<?php

namespace App\Modules\CustomerOffers\Services;

use App\Models\Tenant;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Support\Countries;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Builds a styled commercial-offer XLSX from a customer offer, split into a
 * header (title + merchant/tenant details and offer dates), a body (the
 * coloured products table sourced from the offer items) and a footer notice.
 *
 * Internal details (client name, offer number) are deliberately left out of the
 * document; only the offer number is reused for the download filename.
 */
class CustomerOfferExcelExporter
{
    private const TITLE = 'Ofertă comercială - legume și fructe proaspete';

    private const FOOTER_NOTICE = 'Notă: Prețurile sunt exprimate fără TVA și includ livrarea la client. '
        .'Oferta este valabilă în limita stocului disponibil. '
        .'Cantitățile și frecvența livrărilor se stabilesc în funcție de necesarul beneficiarului.';

    private const ACCENT = '2E7D32';      // header green

    private const ACCENT_SOFT = 'E8F5E9'; // zebra green

    private const GRID = 'CFD8DC';        // table border grey

    private const FOOTER_BG = 'FFF8E1';   // footer cream

    private const FOOTER_FG = '5D4037';

    /**
     * Products-table column headers, in order. The price header gets the offer
     * currency appended at export time.
     *
     * @var array<int, string>
     */
    private const COLUMNS = [
        'Categorie',
        'Nume produs',
        'Origine',
        'Ambalare',
        'Calitate',
        'Calibru',
        'Preț fără TVA',
        'Cantitate disponibilă',
        'Observații',
    ];

    /**
     * Width of each of the 9 columns.
     *
     * @var array<int, float>
     */
    private const COLUMN_WIDTHS = [16, 26, 14, 16, 12, 12, 16, 18, 34];

    /**
     * Write the offer to a temporary XLSX file and return its path. The caller is
     * responsible for streaming and deleting it.
     */
    public function export(CustomerOffer $offer): string
    {
        $offer->loadMissing([
            'tenant',
            'items.product.category',
            'items.supplierProduct.packagingMethod',
            'items.suppliers',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'offer_').'.xlsx';

        $options = new Options;

        foreach (self::COLUMN_WIDTHS as $index => $width) {
            $options->setColumnWidth($width, $index + 1);
        }

        $this->registerMerges($options, $offer->items->count());

        $writer = new Writer($options);
        $writer->openToFile($path);

        $this->writeHeader($writer, $offer);
        $this->writeBody($writer, $offer);
        $this->writeFooter($writer);

        $writer->close();

        return $path;
    }

    /**
     * Merged ranges. OpenSpout merge columns are 0-based (A = 0 … I = 8) while
     * rows are 1-based. The merchant details live in the left block (A:E) and the
     * offer dates in the right block (F:I); the title and footer span A:I.
     */
    private function registerMerges(Options $options, int $itemCount): void
    {
        $options->mergeCells(0, 1, 8, 1); // title           A1:I1

        $options->mergeCells(0, 2, 4, 2); // "Date comerciant" A2:E2
        $options->mergeCells(5, 2, 8, 2); // offer date        F2:I2
        $options->mergeCells(0, 3, 4, 3); // company name      A3:E3
        $options->mergeCells(5, 3, 8, 3); // valid-until date  F3:I3

        $options->mergeCells(0, 4, 4, 4); // address  A4:E4
        $options->mergeCells(0, 5, 4, 5); // email    A5:E5
        $options->mergeCells(0, 6, 4, 6); // phone    A6:E6

        $footerRow = 10 + $itemCount;
        $options->mergeCells(0, $footerRow, 8, $footerRow); // footer A:I
    }

    private function writeHeader(Writer $writer, CustomerOffer $offer): void
    {
        $tenant = $offer->tenant;

        $writer->addRow(Row::fromValues([mb_strtoupper(self::TITLE, 'UTF-8')], $this->titleStyle()));

        $writer->addRow($this->splitRow(
            'Date comerciant',
            'Data ofertare: '.$this->formatDate($offer->offer_date),
            $this->sectionLabelStyle(),
            $this->infoRightStyle(),
        ));

        $writer->addRow($this->splitRow(
            $tenant?->legal_name ?: ($tenant?->name ?? ''),
            'Valabilă până la: '.$this->formatDate($offer->valid_until),
            $this->companyNameStyle(),
            $this->infoRightStyle(),
        ));

        $writer->addRow(Row::fromValues([$this->labelled('Adresă', $this->addressLine($tenant))], $this->companyLineStyle()));
        $writer->addRow(Row::fromValues([$this->labelled('Email', $tenant?->email)], $this->companyLineStyle()));
        $writer->addRow(Row::fromValues([$this->labelled('Telefon', $tenant?->phone)], $this->companyLineStyle()));

        $writer->addRow(Row::fromValues(['']));
    }

    private function writeBody(Writer $writer, CustomerOffer $offer): void
    {
        $header = self::COLUMNS;
        $header[6] = 'Preț fără TVA ('.($offer->currency ?: 'EUR').')';

        $writer->addRow(Row::fromValues($header, $this->tableHeaderStyle()));

        foreach ($offer->items->values() as $index => $item) {
            $writer->addRow(Row::fromValues($this->itemRow($item), $this->zebraStyle($index % 2 === 0)));
        }
    }

    private function writeFooter(Writer $writer): void
    {
        $writer->addRow(Row::fromValues(['']));
        $writer->addRow(Row::fromValues([self::FOOTER_NOTICE], $this->footerStyle()));
    }

    /**
     * One products-table row, sourced from the offer item and its supplier product.
     *
     * @return array<int, string|float|null>
     */
    private function itemRow(CustomerOfferItem $item): array
    {
        $supplierProduct = $item->supplierProduct;

        $category = $supplierProduct?->category ?: $item->product?->category?->name;
        $packaging = $supplierProduct?->default_packaging ?: $supplierProduct?->packagingMethod?->name;

        $securedQuantity = $item->totalSecuredQuantity();

        return [
            $category ?? '',
            $item->product?->name ?? ($supplierProduct?->name ?? ''),
            Countries::label($supplierProduct?->country_of_origin) ?? '',
            $packaging ?? '',
            $supplierProduct?->quality ?? '',
            $supplierProduct?->caliber ?? '',
            $item->sale_price !== null ? (float) $item->sale_price : null,
            $securedQuantity > 0 ? $securedQuantity : null,
            $item->notes ?? '',
        ];
    }

    /**
     * A two-block row: a left value spanning the first five columns and a right
     * value spanning the last four, each with its own style.
     */
    private function splitRow(string $left, string $right, Style $leftStyle, Style $rightStyle): Row
    {
        return new Row([
            Cell::fromValue($left, $leftStyle),
            Cell::fromValue('', $leftStyle),
            Cell::fromValue('', $leftStyle),
            Cell::fromValue('', $leftStyle),
            Cell::fromValue('', $leftStyle),
            Cell::fromValue($right, $rightStyle),
            Cell::fromValue('', $rightStyle),
            Cell::fromValue('', $rightStyle),
            Cell::fromValue('', $rightStyle),
        ]);
    }

    private function labelled(string $label, ?string $value): string
    {
        return $label.': '.(filled($value) ? $value : '-');
    }

    private function addressLine(?Tenant $tenant): ?string
    {
        $line = collect([$tenant?->address, $tenant?->city, Countries::label($tenant?->country)])
            ->filter()
            ->implode(', ');

        return $line !== '' ? $line : null;
    }

    private function formatDate(mixed $date): string
    {
        return $date !== null ? $date->format('d.m.Y') : '-';
    }

    private function titleStyle(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setFontSize(16)
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor(self::ACCENT)
            ->setCellAlignment(CellAlignment::CENTER)
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER);
    }

    private function sectionLabelStyle(): Style
    {
        return (new Style)->setFontBold()->setFontSize(14)->setFontColor(self::ACCENT);
    }

    private function companyNameStyle(): Style
    {
        return (new Style)->setFontBold()->setFontSize(14);
    }

    private function companyLineStyle(): Style
    {
        return (new Style)->setFontSize(14);
    }

    private function infoRightStyle(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setFontSize(14)
            ->setCellAlignment(CellAlignment::RIGHT);
    }

    private function tableHeaderStyle(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setFontSize(12)
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor(self::ACCENT)
            ->setCellAlignment(CellAlignment::CENTER)
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER)
            ->setShouldWrapText()
            ->setBorder($this->cellBorder());
    }

    private function zebraStyle(bool $even): Style
    {
        return (new Style)
            ->setFontSize(12)
            ->setBackgroundColor($even ? self::ACCENT_SOFT : Color::WHITE)
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER)
            ->setShouldWrapText()
            ->setBorder($this->cellBorder());
    }

    private function footerStyle(): Style
    {
        return (new Style)
            ->setFontItalic()
            ->setFontSize(14)
            ->setFontColor(self::FOOTER_FG)
            ->setBackgroundColor(self::FOOTER_BG)
            ->setShouldWrapText()
            ->setCellAlignment(CellAlignment::CENTER)
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER);
    }

    private function cellBorder(): Border
    {
        return new Border(
            new BorderPart(Border::TOP, self::GRID, Border::WIDTH_THIN),
            new BorderPart(Border::RIGHT, self::GRID, Border::WIDTH_THIN),
            new BorderPart(Border::BOTTOM, self::GRID, Border::WIDTH_THIN),
            new BorderPart(Border::LEFT, self::GRID, Border::WIDTH_THIN),
        );
    }
}
