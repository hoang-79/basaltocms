<?php

declare(strict_types=1);

namespace Basalto\Core\Publish;

/**
 * Vorlagen für Impressum und Datenschutzerklärung (M-14) nach Schweizer DSG und — soweit anwendbar — DSGVO, in
 * DE/EN/IT/FR. Sie beschreiben, was Basalto selbst tut (nur notwendige Cookies, Einbettungen erst nach Klick,
 * Formulare mit Zustimmung und Frist, IP nur als Prüfsumme). Platzhalter `[[…]]` stehen für das, was nur die
 * Betreiberin weiss; `basalto:publish` schaltet nicht live, solange einer offen ist. Keine Rechtsberatung — die
 * Vorlage ersetzt keine Prüfung im Einzelfall (Hinweis im Backend).
 */
final class LegalTemplates
{
    public const KINDS = ['impressum', 'datenschutz'];

    /** @return array{title: string, slug: string} */
    public static function meta(string $kind, string $lang): array
    {
        $titles = [
            'impressum' => ['de' => ['Impressum', 'impressum'], 'en' => ['Legal notice', 'legal-notice'], 'it' => ['Note legali', 'note-legali'], 'fr' => ['Mentions légales', 'mentions-legales']],
            'datenschutz' => ['de' => ['Datenschutzerklärung', 'datenschutz'], 'en' => ['Privacy policy', 'privacy'], 'it' => ['Informativa sulla privacy', 'privacy'], 'fr' => ['Politique de confidentialité', 'confidentialite']],
        ];
        [$title, $slug] = $titles[$kind][$lang] ?? $titles[$kind]['de'];

        return ['title' => $title, 'slug' => $slug];
    }

    /**
     * Abschnitte als Paare [Überschrift oder null, Absätze] — Platzhalter noch offen.
     *
     * @return list<array{0: string|null, 1: list<string>}>
     */
    public static function sections(string $kind, string $lang): array
    {
        $lang = in_array($lang, ['de', 'en', 'it', 'fr'], true) ? $lang : 'de';

        return $kind === 'impressum' ? self::impressum($lang) : self::privacy($lang);
    }

    /** @return list<array{0: string|null, 1: list<string>}> */
    private static function impressum(string $lang): array
    {
        return match ($lang) {
            'en' => [
                ['Responsible for this website', ['[[Firma]]', '[[Adresse]]', 'Email: [[E-Mail]]', 'Represented by: [[Verantwortlich]]', 'Company number / VAT: [[UID]] (enter «none» if there is none)']],
                ['Liability', ['We check the content of this website carefully but cannot guarantee that it is complete, correct or up to date. Links to other websites are outside our responsibility.']],
                ['Copyright', ['Texts, images and graphics on this website belong to [[Firma]] or are used with permission. Any use beyond private purposes requires our consent.']],
            ],
            'it' => [
                ['Responsabile di questo sito', ['[[Firma]]', '[[Adresse]]', 'E-mail: [[E-Mail]]', 'Rappresentato da: [[Verantwortlich]]', 'Numero IDI / IVA: [[UID]] (indicare «nessuno» se non presente)']],
                ['Responsabilità', ['Controlliamo con cura i contenuti di questo sito, ma non possiamo garantirne completezza, correttezza e attualità. I link ad altri siti esulano dalla nostra responsabilità.']],
                ['Diritti d’autore', ['Testi, immagini e grafiche di questo sito appartengono a [[Firma]] o sono utilizzati con autorizzazione. Ogni uso oltre quello privato richiede il nostro consenso.']],
            ],
            'fr' => [
                ['Responsable de ce site', ['[[Firma]]', '[[Adresse]]', 'E-mail : [[E-Mail]]', 'Représenté par : [[Verantwortlich]]', 'Numéro IDE / TVA : [[UID]] (indiquer « aucun » s’il n’y en a pas)']],
                ['Responsabilité', ['Nous vérifions soigneusement le contenu de ce site, sans pouvoir garantir qu’il soit complet, exact et à jour. Les liens vers d’autres sites ne relèvent pas de notre responsabilité.']],
                ['Droits d’auteur', ['Les textes, images et graphiques de ce site appartiennent à [[Firma]] ou sont utilisés avec autorisation. Toute utilisation au-delà d’un usage privé requiert notre accord.']],
            ],
            default => [
                ['Verantwortlich für diese Website', ['[[Firma]]', '[[Adresse]]', 'E-Mail: [[E-Mail]]', 'Vertreten durch: [[Verantwortlich]]', 'Handelsregister / UID: [[UID]] («keine» eintragen, wenn es keine gibt)']],
                ['Haftung', ['Wir prüfen die Inhalte dieser Website sorgfältig, können aber nicht garantieren, dass sie vollständig, richtig und aktuell sind. Für Inhalte verlinkter Websites sind deren Betreiber verantwortlich.']],
                ['Urheberrecht', ['Texte, Bilder und Grafiken dieser Website gehören [[Firma]] oder werden mit Erlaubnis verwendet. Jede Verwendung über den privaten Gebrauch hinaus braucht unsere Zustimmung.']],
            ],
        };
    }

    /** @return list<array{0: string|null, 1: list<string>}> */
    private static function privacy(string $lang): array
    {
        return match ($lang) {
            'en' => [
                [null, ['This privacy policy explains how [[Firma]] processes personal data on [[Website]]. We comply with the Swiss Federal Act on Data Protection (FADP) and, where applicable, the EU General Data Protection Regulation (GDPR).']],
                ['Controller', ['[[Firma]], [[Adresse]], [[E-Mail]]']],
                ['Visiting the website', ['When you visit the website, our hosting provider processes technical data (IP address, time, page requested, browser) to deliver the pages and keep them secure. This data is deleted after a short period. Hosting: [[Hosting]].']],
                ['Cookies', ['We only use cookies that are technically necessary for the website to work, for example to protect forms. We do not use cookies for analysis or advertising.']],
                ['Contact form', ['If you write to us via the form, we process your details to answer your request. We store the time of your consent; we store your IP address only as an irreversible checksum. Entries are deleted after [[Aufbewahrung]] days.']],
                ['Embedded content', ['Videos and maps from other providers (such as YouTube, Vimeo or map services) are only loaded once you click on them. Only then is data transferred to the provider.']],
                ['Your rights', ['You can request information about your data, have it corrected or deleted, and object to its processing. Write to [[E-Mail]]. You may also lodge a complaint with the Federal Data Protection and Information Commissioner (FDPIC) or, in the EU, with your supervisory authority.']],
                ['Status', ['This privacy policy was last updated on [[Datum]].']],
            ],
            'it' => [
                [null, ['Questa informativa spiega come [[Firma]] tratta i dati personali su [[Website]]. Rispettiamo la Legge federale sulla protezione dei dati (LPD) e, ove applicabile, il Regolamento generale sulla protezione dei dati dell’UE (GDPR).']],
                ['Titolare del trattamento', ['[[Firma]], [[Adresse]], [[E-Mail]]']],
                ['Visita del sito', ['Quando visitate il sito, il nostro fornitore di hosting tratta dati tecnici (indirizzo IP, ora, pagina richiesta, browser) per fornire le pagine e mantenerle sicure. Questi dati vengono cancellati dopo breve tempo. Hosting: [[Hosting]].']],
                ['Cookie', ['Usiamo solo cookie tecnicamente necessari al funzionamento del sito, ad esempio per proteggere i moduli. Non usiamo cookie di analisi o pubblicità.']],
                ['Modulo di contatto', ['Se ci scrivete tramite il modulo, trattiamo i vostri dati per rispondere alla richiesta. Registriamo l’ora del vostro consenso; l’indirizzo IP solo come somma di controllo irreversibile. Le richieste vengono cancellate dopo [[Aufbewahrung]] giorni.']],
                ['Contenuti incorporati', ['Video e mappe di altri fornitori (come YouTube, Vimeo o servizi di mappe) vengono caricati solo dopo un vostro clic. Solo allora i dati vengono trasmessi al fornitore.']],
                ['I vostri diritti', ['Potete chiedere informazioni sui vostri dati, farli correggere o cancellare e opporvi al trattamento. Scriveteci a [[E-Mail]]. Potete anche rivolgervi all’Incaricato federale della protezione dei dati e della trasparenza (IFPDT) o, nell’UE, alla vostra autorità di controllo.']],
                ['Aggiornamento', ['Questa informativa è stata aggiornata il [[Datum]].']],
            ],
            'fr' => [
                [null, ['Cette politique explique comment [[Firma]] traite les données personnelles sur [[Website]]. Nous respectons la loi fédérale sur la protection des données (LPD) et, lorsqu’il s’applique, le règlement général sur la protection des données de l’UE (RGPD).']],
                ['Responsable du traitement', ['[[Firma]], [[Adresse]], [[E-Mail]]']],
                ['Visite du site', ['Lorsque vous visitez le site, notre hébergeur traite des données techniques (adresse IP, heure, page demandée, navigateur) pour fournir les pages et les sécuriser. Ces données sont supprimées après une courte durée. Hébergement : [[Hosting]].']],
                ['Cookies', ['Nous n’utilisons que des cookies techniquement nécessaires au fonctionnement du site, par exemple pour protéger les formulaires. Nous n’utilisons pas de cookies d’analyse ou de publicité.']],
                ['Formulaire de contact', ['Si vous nous écrivez via le formulaire, nous traitons vos données pour répondre à votre demande. Nous enregistrons l’heure de votre consentement ; votre adresse IP uniquement sous forme de somme de contrôle irréversible. Les demandes sont supprimées après [[Aufbewahrung]] jours.']],
                ['Contenus intégrés', ['Les vidéos et cartes d’autres fournisseurs (comme YouTube, Vimeo ou des services de cartes) ne sont chargées qu’après un clic de votre part. Ce n’est qu’alors que des données sont transmises au fournisseur.']],
                ['Vos droits', ['Vous pouvez demander des informations sur vos données, les faire corriger ou supprimer et vous opposer à leur traitement. Écrivez-nous à [[E-Mail]]. Vous pouvez aussi vous adresser au Préposé fédéral à la protection des données et à la transparence (PFPDT) ou, dans l’UE, à votre autorité de contrôle.']],
                ['Mise à jour', ['Cette politique a été mise à jour le [[Datum]].']],
            ],
            default => [
                [null, ['Diese Datenschutzerklärung beschreibt, wie [[Firma]] auf [[Website]] Personendaten bearbeitet. Wir halten uns an das Schweizer Datenschutzgesetz (DSG) und, soweit anwendbar, an die Datenschutz-Grundverordnung der EU (DSGVO).']],
                ['Verantwortliche Stelle', ['[[Firma]], [[Adresse]], [[E-Mail]]']],
                ['Besuch der Website', ['Beim Besuch der Website bearbeitet unser Hosting-Anbieter technische Daten (IP-Adresse, Zeitpunkt, aufgerufene Seite, Browser), um die Seiten auszuliefern und sicher zu betreiben. Diese Daten werden nach kurzer Zeit gelöscht. Hosting: [[Hosting]].']],
                ['Cookies', ['Wir verwenden nur Cookies, die für den Betrieb der Website technisch nötig sind, etwa zum Schutz von Formularen. Cookies zur Analyse oder Werbung verwenden wir nicht.']],
                ['Kontaktformular', ['Wenn Sie uns über das Formular schreiben, bearbeiten wir Ihre Angaben, um Ihre Anfrage zu beantworten. Wir speichern den Zeitpunkt Ihrer Zustimmung; Ihre IP-Adresse nur als nicht umkehrbare Prüfsumme. Eingänge werden nach [[Aufbewahrung]] Tagen gelöscht.']],
                ['Eingebettete Inhalte', ['Videos und Karten anderer Anbieter (etwa YouTube, Vimeo oder Kartendienste) laden erst, wenn Sie darauf klicken. Erst dann werden Daten an den Anbieter übertragen.']],
                ['Ihre Rechte', ['Sie können Auskunft über Ihre Daten verlangen, sie berichtigen oder löschen lassen und der Bearbeitung widersprechen. Schreiben Sie an [[E-Mail]]. Sie können sich auch an den Eidgenössischen Datenschutz- und Öffentlichkeitsbeauftragten (EDÖB) oder, in der EU, an Ihre Aufsichtsbehörde wenden.']],
                ['Stand', ['Diese Datenschutzerklärung wurde am [[Datum]] zuletzt aktualisiert.']],
            ],
        };
    }
}
