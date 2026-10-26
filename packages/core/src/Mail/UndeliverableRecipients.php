<?php

declare(strict_types=1);

namespace Basalto\Core\Mail;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;

/**
 * Adressen, die nie Mail empfangen (TB 64): Endungen und Domains, die RFC 2606 und RFC 6761 für Beispiele und Tests
 * reservieren — `.example`, `.test`, `.invalid`, `.localhost`, `example.com/.net/.org`. Testdaten tragen solche
 * Adressen (`@testdaten.example`); mit echtem Versand (Testing auf cyon) gäbe jede Benachrichtigung an sie einen
 * Rückläufer und kostete Kontingent (cyon: 100 Mails je Stunde). Vor dem Versand fallen sie aus An, Cc und Bcc; bleibt
 * niemand, geht die Mail nicht hinaus — nur ein Eintrag im Log.
 */
final class UndeliverableRecipients
{
    private const TLDS = ['example', 'test', 'invalid', 'localhost'];

    private const DOMAINS = ['example.com', 'example.net', 'example.org'];

    public static function boot(): void
    {
        Event::listen(MessageSending::class, static function (MessageSending $event): ?bool {
            $message = $event->message;
            $kept = 0;
            $dropped = [];
            foreach (['to' => 'To', 'cc' => 'Cc', 'bcc' => 'Bcc'] as $method => $header) {
                /** @var list<Address> $addresses */
                $addresses = $message->{'get'.$header}();
                $keep = array_values(array_filter($addresses, static fn (Address $address): bool => ! self::reserved($address->getAddress())));
                if (count($keep) === count($addresses)) {
                    $kept += count($keep);

                    continue;
                }
                foreach ($addresses as $address) {
                    if (self::reserved($address->getAddress())) {
                        $dropped[] = $address->getAddress();
                    }
                }
                $message->getHeaders()->remove($header);
                if ($keep !== []) {
                    $message->{$method}(...$keep);
                }
                $kept += count($keep);
            }
            if ($dropped === []) {
                return null;
            }
            Log::info('Mail an reservierte Adressen nicht versendet', ['to' => $dropped, 'subject' => $message->getSubject()]);

            return $kept > 0 ? null : false;
        });
    }

    public static function reserved(string $address): bool
    {
        $domain = strtolower(trim((string) substr($address, (int) strrpos($address, '@') + 1), '. '));
        $tld = substr($domain, (int) strrpos('.'.$domain, '.'));
        if (in_array($tld, self::TLDS, true)) {
            return true;
        }
        foreach (self::DOMAINS as $reserved) {
            if ($domain === $reserved || str_ends_with($domain, '.'.$reserved)) {
                return true;
            }
        }

        return false;
    }
}
