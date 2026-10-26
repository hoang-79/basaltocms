<?php

declare(strict_types=1);

namespace Basalto\Core\Invoices;

use Sprain\SwissQrBill\DataGroup\Element\AdditionalInformation;
use Sprain\SwissQrBill\DataGroup\Element\CreditorInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentAmountInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentReference;
use Sprain\SwissQrBill\DataGroup\Element\StructuredAddress;
use Sprain\SwissQrBill\PaymentPart\Output\HtmlOutput\HtmlOutput;
use Sprain\SwissQrBill\QrBill;
use Sprain\SwissQrBill\QrCode\QrCode;
use Sprain\SwissQrBill\Reference\QrPaymentReferenceGenerator;
use Sprain\SwissQrBill\Reference\RfCreditorReferenceGenerator;
use Throwable;

/**
 * Der Zahlteil der QR-Rechnung (Swiss Payment Standards, `sprain/swiss-qr-bill`) — TB 11 im Lizenzverkauf gebaut, seit
 * TB 12 Scheibe 2 im Kern für Lizenzverkauf und SaaS: Konto und Adresse des {@see Creditor}, Betrag, Referenz — eine
 * QR-Referenz bei einer QR-IBAN (mit BESR-ID, falls die Bank eine vergibt), sonst eine Creditor Reference (RF). Nur CHF
 * und EUR. Fehlen Angaben, gibt es keinen Zahlteil (null) — die Rechnung zeigt dann einen Platzhalter.
 */
final class SwissQrBill
{
    public const CURRENCIES = ['CHF', 'EUR'];

    public static function supports(string $currency): bool
    {
        return in_array($currency, self::CURRENCIES, true);
    }

    /**
     * Die Zahlungsreferenz aus Jahr und laufender Nummer — null, wenn das Konto fehlt. `$prefix` steht vor der Nummer
     * der RF-Referenz (Lizenzverkauf «R», SaaS je Instanz), damit sich die Referenzen zweier Nummernkreise nie gleichen.
     */
    public function reference(Creditor $creditor, int $year, int $sequence, string $prefix = 'R', string $qrPrefix = ''): ?string
    {
        if ($creditor->iban === '') {
            return null;
        }
        $number = sprintf('%d%04d', $year, $sequence);
        try {
            if (CreditorInformation::create($creditor->iban)->containsQrIban()) {
                return QrPaymentReferenceGenerator::generate($creditor->besrId !== '' ? $creditor->besrId : null, $qrPrefix.$number);
            }

            return RfCreditorReferenceGenerator::generate($prefix.$number);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Der QR-Bill — null, wenn Angaben fehlen oder ungültig sind.
     *
     * @param  array<string, mixed>  $debtor  company|name, street, postal_code, city, country
     */
    public function bill(Creditor $creditor, string $currency, int $totalCents, ?string $reference, string $number, array $debtor): ?QrBill
    {
        if (! self::supports($currency) || ! $creditor->complete()) {
            return null;
        }
        try {
            $bill = QrBill::create();
            $bill->setCreditor($creditor->street !== ''
                ? StructuredAddress::createWithStreet($creditor->name, $creditor->street, $creditor->building !== '' ? $creditor->building : null, $creditor->postalCode, $creditor->city, $creditor->country)
                : StructuredAddress::createWithoutStreet($creditor->name, $creditor->postalCode, $creditor->city, $creditor->country));
            $information = CreditorInformation::create($creditor->iban);
            $bill->setCreditorInformation($information);
            $bill->setPaymentAmountInformation(PaymentAmountInformation::create($currency, $totalCents / 100));
            if ($reference !== null && $reference !== '') {
                $bill->setPaymentReference(PaymentReference::create($information->containsQrIban() ? PaymentReference::TYPE_QR : PaymentReference::TYPE_SCOR, $reference));
            } else {
                $bill->setPaymentReference(PaymentReference::create(PaymentReference::TYPE_NON));
            }
            $bill->setAdditionalInformation(AdditionalInformation::create($number));
            $text = static fn (string $key): string => trim(is_scalar($debtor[$key] ?? null) ? (string) $debtor[$key] : '');
            $name = $text('company') !== '' ? $text('company') : $text('name');
            $country = $text('country') !== '' ? $text('country') : 'CH';
            if ($name !== '' && $text('postal_code') !== '' && $text('city') !== '') {
                $bill->setUltimateDebtor($text('street') !== ''
                    ? StructuredAddress::createWithStreet($name, $text('street'), null, $text('postal_code'), $text('city'), $country)
                    : StructuredAddress::createWithoutStreet($name, $text('postal_code'), $text('city'), $country));
            }

            return $bill->isValid() ? $bill : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Der Zahlteil als HTML für das PDF (SVG: als Vektor scharf im Druck, mit Schweizerkreuz — das PNG verliert es in Dompdf). */
    public function html(?QrBill $bill, string $locale): ?string
    {
        if ($bill === null) {
            return null;
        }

        return (new HtmlOutput($bill, in_array($locale, ['de', 'fr', 'it', 'en'], true) ? $locale : 'de'))
            ->setQrCodeImageFormat(QrCode::FILE_FORMAT_SVG)->getPaymentPart();
    }
}
