<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Console;

use Basalto\Core\Community\Mounts;
use Basalto\Core\Community\VisitorAccess;
use Basalto\Core\Community\VisitorGroup;
use Basalto\Core\Community\VisitorGroups;
use Basalto\Core\Community\VisitorPermission;
use Basalto\Core\Models\Site;
use Basalto\Modules\Forum\Forum;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\Models\Prefix;
use Basalto\Modules\Forum\NodeType;
use Illuminate\Console\Command;

/**
 * Die Startstruktur der Basalto-Community (CR-006 FO-26), aufgebaut wie die Foren von WoltLab: Allgemein
 * (Neuigkeiten und Hinweise — nur das Team eröffnet —, Fragen vor dem Kauf), Kundenforen (Hilfe und Support, Ideen &
 * Vorschläge mit «Umgesetzte Vorschläge», Fehlermeldungen mit «Erledigte Fehlermeldungen»), Individuelle Anpassung
 * (Themes und Lava, Module und Plugins mit Verweis auf die Entwickler-Doku) und Sonstiges (Off-Topic) — mit Symbolen,
 * Präfixen und Themenfeldern (Version, Hosting, Browser). Läuft mehrfach gefahrlos: Vorhandenes (nach Kurzname) bleibt.
 */
final class ForumSeedCommand extends Command
{
    protected $signature = 'basalto:forum-seed {site : Kennung der Site}';

    protected $description = 'Legt die Startstruktur des Forums an (Basalto-Community), ohne Vorhandenes zu ändern';

    /** @var list<array{0: string, 1: string, 2: string}> Präfix (de), Präfix (en), Farbe */
    public const PREFIXES = [['Frage', 'Question', 'blue'], ['Gelöst', 'Solved', 'green'], ['Fehler', 'Bug', 'red'], ['Behoben', 'Fixed', 'green'],
        ['Idee', 'Idea', 'yellow'], ['Umgesetzt', 'Done', 'lava']];

    /**
     * Knoten: Kurzname, Titel, Beschreibung, Art, Einstellungen (icon, prefixes als Bezeichnungen, fields, closed, team_only, link), Kinder.
     *
     * @var list<array{0: string, 1: array<string, string>, 2: array<string, string>, 3: NodeType, 4: array<string, mixed>, 5: list<mixed>}>
     */
    public const STRUCTURE = [
        ['allgemein', ['de' => 'Allgemein', 'en' => 'General', 'fr' => 'Général', 'it' => 'Generale'], ['de' => 'Neuigkeiten und Fragen rund um Basalto.', 'en' => 'News and questions about Basalto.', 'fr' => 'Actualités et questions autour de Basalto.', 'it' => 'Novità e domande su Basalto.'], NodeType::Category, [], [
            ['ankundigungen', ['de' => 'Neuigkeiten und Hinweise', 'en' => 'News and notes', 'fr' => 'Actualités et annonces', 'it' => 'Novità e avvisi'], ['de' => 'Neue Versionen, Termine und wichtige Hinweise des Teams.', 'en' => 'New releases, dates and important notes from the team.', 'fr' => 'Nouvelles versions, dates et annonces importantes de l’équipe.', 'it' => 'Nuove versioni, date e avvisi importanti del team.'], NodeType::Board, ['icon' => 'megaphone', 'team_only' => true], []],
            ['fragen-vor-dem-kauf', ['de' => 'Fragen vor dem Kauf', 'en' => 'Pre-sales questions', 'fr' => 'Questions avant l’achat', 'it' => 'Domande prima dell’acquisto'], ['de' => 'Lizenzen, Preise, Hosting — fragen Sie, bevor Sie sich entscheiden.', 'en' => 'Licences, prices, hosting — ask before you decide.', 'fr' => 'Licences, prix, hébergement — posez vos questions avant de décider.', 'it' => 'Licenze, prezzi, hosting — chieda prima di decidere.'], NodeType::Board, ['icon' => 'question', 'prefixes' => ['Frage', 'Gelöst']], []],
        ]],
        ['kundenforen', ['de' => 'Kundenforen', 'en' => 'Customer forums', 'fr' => 'Forums clients', 'it' => 'Forum clienti'], ['de' => 'Hilfe untereinander und vom Team.', 'en' => 'Help from each other and from the team.', 'fr' => 'Entraide entre utilisateurs et aide de l’équipe.', 'it' => 'Aiuto reciproco e dal team.'], NodeType::Category, [], [
            ['hilfe-und-support', ['de' => 'Hilfe und Support', 'en' => 'Help and support', 'fr' => 'Aide et support', 'it' => 'Aiuto e supporto'], ['de' => 'Fragen zu Installation, Einrichtung und Betrieb.', 'en' => 'Questions about installation, setup and operation.', 'fr' => 'Questions sur l’installation, la configuration et l’exploitation.', 'it' => 'Domande su installazione, configurazione e gestione.'], NodeType::Board,
                ['icon' => 'lightbulb', 'prefixes' => ['Frage', 'Gelöst'], 'fields' => ['version', 'hosting']], []],
            ['ideen', ['de' => 'Ideen & Vorschläge', 'en' => 'Ideas & suggestions', 'fr' => 'Idées et suggestions', 'it' => 'Idee e proposte'], ['de' => 'Wünsche und Vorschläge für kommende Versionen.', 'en' => 'Wishes and suggestions for coming releases.', 'fr' => 'Souhaits et suggestions pour les prochaines versions.', 'it' => 'Desideri e proposte per le prossime versioni.'], NodeType::Board, ['icon' => 'wand', 'prefixes' => ['Idee', 'Umgesetzt']], [
                ['umgesetzte-vorschlaege', ['de' => 'Umgesetzte Vorschläge', 'en' => 'Implemented suggestions', 'fr' => 'Suggestions réalisées', 'it' => 'Proposte realizzate'], ['de' => 'Was aus Ihren Ideen geworden ist.', 'en' => 'What became of your ideas.', 'fr' => 'Ce que sont devenues vos idées.', 'it' => 'Che cosa è nato dalle Sue idee.'], NodeType::Board, ['icon' => 'star', 'closed' => true, 'prefixes' => ['Umgesetzt']], []],
            ]],
            ['fehlermeldungen', ['de' => 'Fehlermeldungen', 'en' => 'Bug reports', 'fr' => 'Signalements de bugs', 'it' => 'Segnalazioni di errori'], ['de' => 'Etwas funktioniert nicht? Hier melden — mit Version und Schritten.', 'en' => 'Something broken? Report it here — with version and steps.', 'fr' => 'Quelque chose ne fonctionne pas ? Signalez-le ici — avec la version et les étapes.', 'it' => 'Qualcosa non funziona? Lo segnali qui — con versione e passaggi.'], NodeType::Board,
                ['icon' => 'bug', 'prefixes' => ['Fehler', 'Behoben'], 'fields' => ['version', 'browser']], [
                    ['erledigte-fehlermeldungen', ['de' => 'Erledigte Fehlermeldungen', 'en' => 'Resolved bug reports', 'fr' => 'Bugs résolus', 'it' => 'Errori risolti'], ['de' => 'Behobene Fehler zum Nachlesen.', 'en' => 'Fixed bugs for reference.', 'fr' => 'Bugs corrigés, pour référence.', 'it' => 'Errori corretti, da consultare.'], NodeType::Board, ['icon' => 'bug', 'closed' => true, 'prefixes' => ['Behoben']], []],
                ]],
        ]],
        ['anpassung', ['de' => 'Individuelle Anpassung', 'en' => 'Customisation', 'fr' => 'Personnalisation', 'it' => 'Personalizzazione'], ['de' => 'Themes, Lava und eigene Module.', 'en' => 'Themes, Lava and your own modules.', 'fr' => 'Thèmes, Lava et modules personnels.', 'it' => 'Temi, Lava e moduli propri.'], NodeType::Category, [], [
            ['themes-und-module', ['de' => 'Themes und Lava', 'en' => 'Themes and Lava', 'fr' => 'Thèmes et Lava', 'it' => 'Temi e Lava'], ['de' => 'Eigene Themes, Vorlagen und Lava-Teile.', 'en' => 'Your own themes, templates and Lava parts.', 'fr' => 'Vos thèmes, modèles et parties Lava.', 'it' => 'Temi, modelli e parti Lava propri.'], NodeType::Board, ['icon' => 'pencil', 'prefixes' => ['Frage', 'Gelöst']], []],
            ['module-und-plugins', ['de' => 'Module und Plugins', 'en' => 'Modules and plugins', 'fr' => 'Modules et plugins', 'it' => 'Moduli e plugin'], ['de' => 'Plugins entwickeln, Schnittstellen, Marktplatz.', 'en' => 'Developing plugins, interfaces, marketplace.', 'fr' => 'Développer des plugins, interfaces, marketplace.', 'it' => 'Sviluppo di plugin, interfacce, marketplace.'], NodeType::Board, ['icon' => 'wrench', 'prefixes' => ['Frage', 'Gelöst']], [
                ['entwickler-doku', ['de' => 'Entwickler-Doku', 'en' => 'Developer docs', 'fr' => 'Documentation développeurs', 'it' => 'Documentazione per sviluppatori'], ['de' => 'Lava, Module und Schnittstellen in der Doku.', 'en' => 'Lava, modules and interfaces in the docs.', 'fr' => 'Lava, modules et interfaces dans la documentation.', 'it' => 'Lava, moduli e interfacce nella documentazione.'], NodeType::Link, ['icon' => 'book', 'link' => '/docs'], []],
            ]],
        ]],
        ['sonstiges', ['de' => 'Sonstiges', 'en' => 'Miscellaneous', 'fr' => 'Divers', 'it' => 'Varie'], ['de' => 'Alles, was sonst nirgends passt.', 'en' => 'Everything that fits nowhere else.', 'fr' => 'Tout ce qui ne trouve pas sa place ailleurs.', 'it' => 'Tutto ciò che non trova posto altrove.'], NodeType::Category, [], [
            ['off-topic', ['de' => 'Off-Topic', 'en' => 'Off-topic', 'fr' => 'Hors sujet', 'it' => 'Fuori tema'], ['de' => 'Plaudern, Vorstellen, Feierabend.', 'en' => 'Chat, introductions, after hours.', 'fr' => 'Discussions, présentations, détente.', 'it' => 'Chiacchiere, presentazioni, tempo libero.'], NodeType::Board, ['icon' => 'chat'], []],
        ]],
    ];

    /** @var array<string, array{label: array<string, string>, type: string, options?: list<string>, required?: bool}> */
    public const FIELDS = [
        'version' => ['label' => ['de' => 'Version', 'en' => 'Version', 'fr' => 'Version', 'it' => 'Versione'], 'type' => 'text'],
        'hosting' => ['label' => ['de' => 'Hosting', 'en' => 'Hosting', 'fr' => 'Hébergement', 'it' => 'Hosting'], 'type' => 'select', 'options' => ['Shared Hosting', 'Eigener Server (VPS)', 'Laravel Cloud', 'Docker', 'Basalto SaaS']],
        'browser' => ['label' => ['de' => 'Browser', 'en' => 'Browser', 'fr' => 'Navigateur', 'it' => 'Browser'], 'type' => 'select', 'options' => ['Chrome', 'Firefox', 'Safari', 'Edge', 'anderer']],
    ];

    public function handle(VisitorAccess $access, VisitorGroups $groups): int
    {
        $site = Site::query()->where('handle', (string) $this->argument('site'))->first();
        if ($site === null) {
            $this->error('Keine Site mit dieser Kennung.');

            return self::FAILURE;
        }
        $created = self::build($site, self::STRUCTURE, $access, $groups);
        $page = app(Mounts::class)->entryFor($site, Forum::MOUNT) !== null ? 'Die Seite der Community steht.' : 'Noch keine Seite mit dem Forum — im Extranet unter Community «Forum unter /community einrichten».';
        $this->info(sprintf('%s: %d Kategorien und Foren angelegt. %s', $site->handle, $created, $page));

        return self::SUCCESS;
    }

    /** @return array<string, int> Präfix (de) → ID; fehlende entstehen */
    public static function prefixes(Site $site): array
    {
        $existing = Prefix::query()->withoutGlobalScopes()->where('site_id', $site->id)->get();
        $out = [];
        foreach (self::PREFIXES as $i => [$de, $en, $color]) {
            $prefix = $existing->first(static fn (Prefix $p): bool => ($p->label['de'] ?? '') === $de)
                ?? Prefix::query()->withoutGlobalScopes()->create(['site_id' => $site->id, 'label' => ['de' => $de, 'en' => $en], 'color' => $color, 'position' => $i]);
            $out[$de] = $prefix->id;
        }

        return $out;
    }

    /**
     * Knoten anlegen, die es (nach Kurzname) noch nicht gibt — auch für die Testdaten, mit eigenen Einstellungen.
     *
     * @param  list<mixed>  $structure
     * @param  array<string, mixed>  $extra  zusätzliche Einstellungen für jeden neuen Knoten (etwa die Marke der Testdaten)
     * @param  array<string, Node>  $made  Kurzname → Knoten (auch die schon vorhandenen)
     *
     * @param-out array<string, Node> $made
     */
    public static function build(Site $site, array $structure, VisitorAccess $access, VisitorGroups $groups, array $extra = [], ?Node $parent = null, array &$made = []): int
    {
        $prefixes = self::prefixes($site);
        $created = 0;
        $position = (int) Node::query()->withoutGlobalScopes()->where('site_id', $site->id)->max('position');
        $locales = $site->localeCodes();
        foreach ($structure as [$slug, $title, $description, $type, $options, $children]) {
            $node = Node::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('slug', $slug)->first();
            if ($node === null) {
                $settings = $extra;
                foreach (['icon', 'closed'] as $key) {
                    if (array_key_exists($key, $options)) {
                        $settings[$key] = $options[$key];
                    }
                }
                if (isset($options['prefixes'])) {
                    $settings['prefixes'] = array_values(array_map(static fn (string $label): int => $prefixes[$label], $options['prefixes']));
                }
                if (isset($options['fields'])) {
                    $settings['fields'] = [];
                    foreach ($options['fields'] as $key) {
                        $field = self::FIELDS[$key];
                        $settings['fields'][] = ['key' => $key, 'label' => array_intersect_key($field['label'], array_flip($locales)) ?: ['de' => $field['label']['de']],
                            'type' => $field['type'], 'options' => $field['options'] ?? [], 'required' => $key === 'version' && $slug === 'fehlermeldungen'];
                    }
                }
                $node = new Node(['site_id' => $site->id, 'parent_id' => $parent?->id, 'type' => $type, 'slug' => $slug, 'position' => ++$position,
                    'title' => array_intersect_key($title, array_flip($locales)) ?: ['de' => $title['de']], 'description' => array_intersect_key($description, array_flip($locales))]);
                $node->link_url = $type === NodeType::Link ? (string) ($options['link'] ?? '/') : null;
                $node->settings = $settings === [] ? null : $settings;
                $node->save();
                $created++;
                if (($options['team_only'] ?? false) === true) {
                    // Nur das Team eröffnet Themen; antworten dürfen alle Mitglieder
                    $access->set($site, $groups->byKey($site, VisitorGroup::MEMBERS), Forum::START, VisitorPermission::NO, [Forum::SUBJECT, $node->id]);
                }
                if (($options['hidden'] ?? false) === true) {
                    // Nur Team und Moderation sehen das Forum: Gäste nie, Mitglieder nicht (die Moderation darf es ausdrücklich)
                    $access->set($site, $groups->byKey($site, VisitorGroup::GUESTS), Forum::VIEW, VisitorPermission::NEVER, [Forum::SUBJECT, $node->id]);
                    $access->set($site, $groups->byKey($site, VisitorGroup::MEMBERS), Forum::VIEW, VisitorPermission::NO, [Forum::SUBJECT, $node->id]);
                    foreach ([Forum::VIEW, Forum::READ, Forum::START, Forum::REPLY] as $permission) {
                        $access->set($site, $groups->byKey($site, VisitorGroup::MODERATORS), $permission, VisitorPermission::YES, [Forum::SUBJECT, $node->id]);
                    }
                }
            }
            $made[(string) $slug] = $node;
            $created += self::build($site, $children, $access, $groups, $extra, $node, $made);
        }

        return $created;
    }
}
