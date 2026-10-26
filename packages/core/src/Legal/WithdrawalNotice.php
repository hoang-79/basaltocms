<?php

declare(strict_types=1);

namespace Basalto\Core\Legal;

use Basalto\Core\Help\LegalTexts;
use Basalto\Core\Vendor;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Hinweis zu Rückgabe und Widerruf in Bestätigungen (CR-011, TB 58): Die Bestätigung eines Vertrags muss die
 * Widerrufsbelehrung auf einem dauerhaften Datenträger enthalten (Richtlinie 2011/83/EU Art. 8 Abs. 7) — darum steht
 * das Wesentliche in der Mail selbst, die ganze Belehrung mit Musterformular hinter dem Link.
 *
 * - `purchase`: Lizenz, Verlängerung, Kauf im Marktplatz — Rückgabe innert 14 Tagen für alle;
 * - `subscription`: Abo der SaaS — Kündigung auf Monatsende, Widerruf für Verbraucher in der EU, auf Verlangen mit
 *   sofortigem Beginn und anteiligem Betrag.
 */
final class WithdrawalNotice
{
    /** @param  'purchase'|'subscription'  $kind */
    public static function apply(MailMessage $mail, string $kind, ?string $lang = null, bool $immediateStart = false): MailMessage
    {
        $email = Vendor::email();
        $mail->line('**'.__('Rückgabe und Widerruf', [], $lang).'**');
        if ($kind === 'subscription') {
            $mail->line(__('Sie können das Abo jederzeit auf das Ende des bezahlten Monats kündigen. Verbraucherinnen und Verbraucher in der EU können diesen Vertrag zudem innert 14 Tagen ab heute ohne Angabe von Gründen widerrufen; eine eindeutige Erklärung an :email genügt. Wir erstatten dann alle Zahlungen innert 14 Tagen auf dem Weg, auf dem Sie bezahlt haben.', ['email' => $email], $lang));
            if ($immediateStart) {
                $mail->line(__('Sie haben verlangt, dass wir sofort beginnen. Widerrufen Sie, zahlen Sie den Anteil der bis dahin erbrachten Leistung.', [], $lang));
            }
        } else {
            $mail->line(__('Sie können diesen Kauf innert 14 Tagen ohne Angabe von Gründen zurückgeben — auch wenn Sie ihn schon verwenden. Eine Nachricht an :email oder aus dem Kundenkonto genügt; wir erstatten den Betrag innert 14 Tagen auf dem Weg, auf dem Sie bezahlt haben. Verbraucherinnen und Verbraucher in der EU haben dieses Recht auch von Gesetzes wegen.', ['email' => $email], $lang));
        }

        $url = LegalTexts::publicUrl('widerruf', $lang);

        return $mail->line(__('Belehrung und Muster-Widerrufsformular: :url', ['url' => '['.$url.']('.$url.')'], $lang));
    }
}
