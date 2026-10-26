<?php

declare(strict_types=1);

namespace Basalto\Core\Invoices;

/**
 * Das Blatt einer Rechnung als HTML für Dompdf (A4) — TB 11 im Lizenzverkauf gebaut, seit TB 12 Scheibe 2 im Kern für
 * Lizenzverkauf und SaaS: Marke, Absender, Empfänger, Titel, Angaben (Datum, Frist …), Positionen, Summen mit MwSt.,
 * Vermerk «bezahlt»/«storniert» oder Hinweis, unten der QR-Zahlteil (105 mm) bzw. ein Platzhalter. Die Texte kommen
 * fertig übersetzt und die Beträge fertig formatiert — das Blatt kennt weder Bestellung noch Konto.
 *
 * @phpstan-type Sheet array{
 *     lang: string,
 *     title: string,
 *     creditor: list<string>,
 *     vat_number: string,
 *     debtor: list<string>,
 *     meta: list<array{0: string, 1: string}>,
 *     lines: list<array{text: string, detail: string|null, amount: string}>,
 *     sums: list<array{0: string, 1: string}>,
 *     total: array{0: string, 1: string},
 *     vat_note: string|null,
 *     stamp: array{text: string, cancelled: bool}|null,
 *     note: string|null,
 *     qr: string|null,
 *     qr_missing: string|null,
 *     labels: array{position: string, amount: string},
 * }
 */
final class InvoiceSheet
{
    /** @param  Sheet  $sheet */
    public function html(array $sheet): string
    {
        $e = static fn (mixed $v): string => htmlspecialchars(is_scalar($v) ? (string) $v : '', ENT_QUOTES);

        $html = '<!doctype html><html lang="'.$e($sheet['lang']).'"><head><meta charset="utf-8"><title>'.$e($sheet['title']).'</title><style>'
            .'@page{margin:0}body{margin:0;font-family:"DejaVu Sans",sans-serif;font-size:9.5pt;color:#23262b}'
            .'.sheet{padding:18mm 18mm 0 20mm}.brand{font-size:15pt;font-weight:bold;letter-spacing:.5pt}.brand b{color:#e4572e}'
            .'.from{font-size:8pt;color:#62676e;margin-top:2mm}.to{margin-top:14mm;min-height:28mm}'
            .'h1{font-size:15pt;margin:8mm 0 2mm}.meta td{padding:0 6mm 1mm 0;font-size:8.5pt}.meta td:first-child{color:#62676e}'
            .'table.lines{width:100%;border-collapse:collapse;margin-top:6mm}.lines th{text-align:left;font-size:8pt;color:#62676e;border-bottom:1pt solid #23262b;padding:1.5mm 0}'
            .'.lines td{padding:2mm 0;border-bottom:.5pt solid #e3e6ea;vertical-align:top}.lines .amount{text-align:right;white-space:nowrap}.detail{font-size:8pt;color:#62676e}'
            .'.sum td{padding:1mm 0}.sum .total td{font-weight:bold;border-top:1pt solid #23262b;padding-top:2mm}'
            .'.stamp{margin-top:8mm;padding:3mm 4mm;border:1pt solid #2a744a;color:#2a744a;font-weight:bold;display:inline-block}'
            .'.stamp.cancelled{border-color:#b8232f;color:#b8232f}.note{margin-top:4mm;font-size:8.5pt}'
            // Dompdf und die Floats des Zahlteils: Währung und Betrag nebeneinander statt übereinander
            .'#qr-bill #qr-bill-currency,#qr-bill #qr-bill-currency-receipt{float:none!important;display:inline-block;vertical-align:top}#qr-bill #qr-bill-amount,#qr-bill #qr-bill-amount-receipt{display:inline-block;vertical-align:top}'
            .'.qr{position:absolute;left:0;bottom:0;width:210mm;height:105mm}.qr-missing{position:absolute;left:20mm;right:18mm;bottom:20mm;border:1pt dashed #62676e;padding:6mm;color:#62676e}'
            // Abstände so, dass Plan, zwei Zusätze, drei Summenzeilen und der Hinweis über dem Zahlteil (105 mm) Platz haben
            .'</style></head><body><div class="sheet">';
        $html .= '<div class="brand">basalt<b>o</b></div><div class="from">'.implode(' · ', array_map($e, $sheet['creditor']))
            .($sheet['vat_number'] !== '' ? ' · '.$e($sheet['vat_number']) : '').'</div>';
        $html .= '<div class="to">'.implode('<br>', array_map($e, $sheet['debtor'])).'</div>';
        $html .= '<h1>'.$e($sheet['title']).'</h1><table class="meta">';
        foreach ($sheet['meta'] as [$label, $value]) {
            $html .= '<tr><td>'.$e($label).'</td><td>'.$e($value).'</td></tr>';
        }
        $html .= '</table><table class="lines"><tr><th>'.$e($sheet['labels']['position']).'</th><th class="amount">'.$e($sheet['labels']['amount']).'</th></tr>';
        foreach ($sheet['lines'] as $line) {
            $html .= '<tr><td>'.$e($line['text']).($line['detail'] !== null ? '<div class="detail">'.$e($line['detail']).'</div>' : '').'</td><td class="amount">'.$e($line['amount']).'</td></tr>';
        }
        $html .= '</table><table class="sum" style="width:80mm;margin:4mm 0 0 auto">';
        foreach ($sheet['sums'] as [$label, $value]) {
            $html .= '<tr><td>'.$e($label).'</td><td style="text-align:right">'.$e($value).'</td></tr>';
        }
        $html .= '<tr class="total"><td>'.$e($sheet['total'][0]).'</td><td style="text-align:right">'.$e($sheet['total'][1]).'</td></tr></table>';
        if ($sheet['vat_note'] !== null) {
            $html .= '<p class="note">'.$e($sheet['vat_note']).'</p>';
        }
        if ($sheet['stamp'] !== null) {
            $html .= '<div class="stamp'.($sheet['stamp']['cancelled'] ? ' cancelled' : '').'">'.$e($sheet['stamp']['text']).'</div>';
        }
        if ($sheet['note'] !== null) {
            $html .= '<p class="note">'.$e($sheet['note']).'</p>';
        }
        $html .= '</div>';
        if ($sheet['qr'] !== null) {
            $html .= '<div class="qr">'.$sheet['qr'].'</div>';
        } elseif ($sheet['qr_missing'] !== null) {
            $html .= '<div class="qr-missing">'.$e($sheet['qr_missing']).'</div>';
        }

        return $html.'</body></html>';
    }

    /**
     * Ein übersetzter Text für das Blatt: `__()` liefert ein Array, wenn der Schlüssel eine Gruppe der PHP-Sprachdateien
     * nennt — bei den Sätzen der Rechnung nie; dann bliebe die Stelle leer.
     */
    public static function text(mixed $translated): string
    {
        return is_string($translated) ? $translated : '';
    }

    /**
     * Summenzeilen und Total aus Netto, MwSt. und Brutto — mit MwSt. drei Zeilen, ohne nur das Total und ein Vermerk.
     *
     * @return array{sums: list<array{0: string, 1: string}>, total: array{0: string, 1: string}, vat_note: string|null}
     */
    public static function totals(int $net, int $vatRate, int $vat, int $total, string $currency, string $lang): array
    {
        $money = static fn (int $cents): string => Money::format($cents, $currency, $lang);
        if ($vatRate > 0) {
            return ['sums' => [[self::text(__('Total ohne MwSt.', [], $lang)), $money($net)], [self::text(__('MwSt. :rate', ['rate' => Money::rate($vatRate, $lang)], $lang)), $money($vat)]],
                'total' => [self::text(__('Total inkl. MwSt.', [], $lang)), $money($total)], 'vat_note' => null];
        }

        return ['sums' => [], 'total' => [self::text(__('Total', [], $lang)), $money($total)], 'vat_note' => self::text(__('Ohne MwSt. — nicht mehrwertsteuerpflichtig.', [], $lang))];
    }
}
