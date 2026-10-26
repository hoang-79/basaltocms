# Commandes

Toutes les commandes de Basalto se trouvent dans l’espace de noms `basalto`. `php artisan list basalto` affiche la liste
actuelle, `php artisan <befehl> --help` les options d’une commande. La plupart des commandes proviennent du noyau
(`packages/core`) ; `basalto:check`, `basalto:lava-import` et `basalto:lsp` de Lava (`packages/lava`),
`basalto:tests-sync` du module Gestion des tests, `basalto:licensing-revocations` du module Vente de licences.

![Le planning du scheduler : ce qui s’exécute chaque minute, chaque heure et chaque nuit](bilder/scheduler.svg)

## Configuration et connexion

| Commande | Finalité | Arguments et options principaux |
| --- | --- | --- |
| `basalto:install` | migrer la base de données, créer le premier site et le premier compte, afficher un lien à usage unique ; peut être relancée plusieurs fois sans risque | `--site`, `--name`, `--locale` (`de` par défaut), `--timezone` (`Europe/Zurich` par défaut), `--user`, `--email`, `--minutes` (60 par défaut) |
| `basalto:login-link` | lien à usage unique pour la connexion d’un compte | `<email>`, `--minutes` (15 par défaut) |

## Sites et contenus

| Commande | Finalité | Arguments et options principaux |
| --- | --- | --- |
| `basalto:publish` | vérifier le site (liens, images, textes alternatifs, langues obligatoires, pages légales, formulaires, redirections), préchauffer et mettre en ligne | `<site>`, `--dry-run` (vérifier seulement), `--ohne-seed` (ne pas importer le seed) |
| `basalto:seed` | importer les contenus de départ du `seed` du thème (idempotent) ; `--auffrischen` réimporte ce qui n’a pas été modifié | `<site>`, `--auffrischen` |
| `basalto:legal` | créer les mentions légales ou la déclaration de protection des données à partir d’un modèle, sous forme de brouillon | `<site>`, `<art>` (`impressum` ou `datenschutz`) |
| `basalto:publish-scheduled` | publier les versions planifiées dont l’échéance est atteinte | — (s’exécute dans le scheduler) |
| `basalto:page-cache` | préchauffer ou vider le cache des pages | `<aktion>` (`warm` ou `clear`), `--site` (sinon tous) |
| `basalto:search-index` | reconstruire l’index de recherche, par exemple après un import ou un changement de pilote | `--site` (sinon tous) |
| `basalto:docs-import` | créer ou actualiser la documentation Markdown (`docs/**/*.md`, traductions issues de `docs/<sprache>/`) sous forme de pages d’un type — pour basaltocms.com ; seul ce qui a changé reçoit une nouvelle version, les textes juridiques restent à l’écart | `<site>`, `--path` (`docs` par défaut), `--type` (`doc` par défaut) |

## Thèmes et Lava

| Commande | Finalité | Arguments et options principaux |
| --- | --- | --- |
| `basalto:check` | vérifier un thème Lava et afficher le fil rouge de A à Z | `--theme` (dossier, `themes/nguyen-development` par défaut), `--site` (thème issu de la base de données), `--json` |
| `basalto:lava-import` | reprendre un thème Lava depuis des fichiers dans la table `lava_sources` | `<site>`, `--theme`, `--scope` (`theme` ou `site`) |
| `basalto:tokens` | générer les design tokens sous forme de variables CSS | `[<site>]` (sinon le site standard), `--tailwind` (en tant que `@theme` pour Tailwind 4) |
| `basalto:theme-pack` | empaqueter un thème avec manifeste et signature facultative | `<name>` (dossier sous `themes/`), `--output` (sinon `storage/app/themes`), `--key` |
| `basalto:theme-verify` | vérifier un paquet de thème par rapport au manifeste et à la signature | `<path>`, `--public-key` |
| `basalto:lsp` | serveur de langage pour Lava (Language Server Protocol) pour VS Code et PhpStorm | `--stdio` (par défaut) |

## Exploitation

| Commande | Finalité | Arguments et options principaux |
| --- | --- | --- |
| `basalto:heartbeat` | écrire un horodatage dans le cache — preuve que le scheduler tourne (`/up`) | — (s’exécute dans le scheduler) |
| `basalto:cleanup` | supprimer le journal et les versions archivées selon les délais de conservation | — (s’exécute dans le scheduler) |
| `basalto:media-purge` | supprimer définitivement les médias qui se trouvent dans la corbeille depuis plus longtemps que le délai | `--days` (30 par défaut) |
| `basalto:error-alarm` | avertir les exploitants par e-mail lorsque les exceptions signalées s’accumulent (avec limitation) | — (s’exécute dans le scheduler ; seuils sous Administration › Paramètres › Exploitation) |

## Sauvegarde et transfert

| Commande | Finalité | Arguments et options principaux |
| --- | --- | --- |
| `basalto:backup` | sauvegarder la base de données et les médias, avec manifeste et sommes de contrôle | `--path` (sinon `storage/app/backups`) |
| `basalto:backup-verify` | vérifier une sauvegarde (sommes de contrôle, test de restauration avec SQLite) | `[<archiv>]` (sinon la plus récente), `--path` |
| `basalto:export` | exporter un site complet (JSON et médias) | `<site>`, `--path` (fichier ou dossier, sinon `storage/app/exports`), `--with-submissions` |
| `basalto:import` | créer un site à partir d’un export | `<zip>`, `--handle` (sinon le handle de l’export) |

Détails sous [Sauvegarde](backup.md).

## Licence et protection des données

| Commande | Finalité | Arguments et options principaux |
| --- | --- | --- |
| `basalto:license` | afficher et consigner l’état de la licence, vérifier une clé ; pour basaltocms.com, également émettre et bloquer | `--check=<schlüssel>` ; pour l’émission `--keypair`, `--issue`, `--revoke`, etc., voir [Licence](lizenz.md) |
| `basalto:privacy` | fournir les informations sur une personne ou supprimer ses données (LPD/RGPD) | `<email>`, `--export=<datei>` (informations en JSON), `--delete` (supprimer le compte et les soumissions), `--force` (sans confirmation) |

## Développement et tests

Non destinées à l’exploitation courante.

| Commande | Finalité | Arguments et options principaux |
| --- | --- | --- |
| `basalto:benchmark` | test de charge : crée de nombreux sites, contenus et médias et mesure les requêtes déterminantes — **écrit des données de test dans la base de données** | `--sites` (100), `--entries` (1000), `--media` (3334), `--locales` (2), `--runs` (30), `--keep`, `--clean` (supprimer les données de bench), `--json` |
| `basalto:load-test` | test de charge HTTP sur vos propres adresses : requêtes par seconde, p50/p95/p99, erreurs, succès du cache — hôtes étrangers uniquement avec `--remote` ([Test de charge](lasttest.md)) | `<url>…`, `--concurrency` (10), `--duration` (30), `--requests`, `--max-p95`, `--max-errors` (1), `--remote`, `--json` |
| `basalto:tests-sync` | synchroniser les cas de test de la gestion des tests avec les critères de réception | `--source` (`auto`, `docs` ou `bundled`), `--write` |
| `basalto:demo-reset` | **supprime toute la base de données** et reconstruit la démo publique : site de démo avec le seed de son thème, trois comptes pour l’accès rapide (Exploitant, Rédacteur, Traducteur) ; ensuite des données de test dans l’étendue `BASALTO_DEMO_TESTDATA` (`klein` par défaut, `aus` désactive) ; se termine par une somme de contrôle du contenu. Ne s’exécute qu’avec `BASALTO_DEMO=true` et uniquement après confirmation | `--force` (sans confirmation, ainsi dans le scheduler) |
| `basalto:update` | vérifier les mises à jour (noyau, plugins, thèmes auprès de l’éditeur de l’environnement) ou installer le noyau ou un plugin à partir de l’archive signée — avec sauvegarde, mode maintenance et retour en arrière ([Mises à jour](updates.md)) | `--kern`, `--plugin=<hersteller/paket>`, `--benachrichtigen` |
| `basalto:testdaten` | des données de test nombreuses et variées pour le développement, les environnements de test et la démo : équipe du site et de l’extranet, comptes clients (y compris avec des noms délicats), communauté, forum, commentaires, membres, Marketplace et vente de licences — selon les modules activés. Les adresses se terminent par `@testdaten.example`, les e-mails ne partent nulle part ; même exécution, même contenu. En production uniquement en mode démo ([Environnement local](../hersteller/lokal.md#données-de-test)) | `<site>`, `--umfang` (`klein`, `mittel`, `gross`), `--nur=<bereich>`, `--neu` (supprimer au préalable), `--entfernen` |
| `basalto:local` | environnement local de l’éditeur sous Laravel Herd : site « basaltocms » sur `<ordner>.test` (principal), importer les contenus, réécrire dans les contenus les adresses de production vers les adresses locales ; refuse de s’exécuter en production | `--herd-config`, `--no-publish` |
| `basalto:release` | construire l’archive de version pour l’installateur web : fichiers versionnés sans le superflu du développement, `vendor` sans paquets de développement, manifeste avec signature Ed25519 (éditeur) | `--release-version`, `--key`, `--out`, `--source`, `--vendor-from`, `--store`, `--notes` (texte ou fichier : « Quoi de neuf ? » sous Administration › Mises à jour) |

## Planning du scheduler

Basalto planifie les tâches suivantes. Elles s’exécutent tant que le cron appelle `php artisan schedule:run` chaque
minute ([Hébergement](hosting.md)) ; toutes uniquement sur un seul serveur. Les heures s’entendent dans le fuseau
horaire de l’application, qui est UTC (`config/app.php`) : 03:15 correspond en Suisse à 04:15 (heure d’hiver) ou à
05:15 (heure d’été). Vérification avec `php artisan schedule:list`.

| Tâche | Fréquence | Cron | À quoi elle sert |
| --- | --- | --- | --- |
| `basalto:heartbeat` | chaque minute | `* * * * *` | signe de vie pour `/up` et Administration › Exploitation |
| `basalto:publish-scheduled` | chaque minute, sans chevauchement | `* * * * *` | publier les versions planifiées |
| `basalto:error-alarm` | chaque minute | `* * * * *` | vérifier l’accumulation d’erreurs, e-mail avec limitation |
| `queue:work --stop-when-empty --max-time=50 --tries=3` | chaque minute, sans chevauchement | `* * * * *` | traiter la file d’attente — uniquement avec `QUEUE_CONNECTION=database` et `BASALTO_QUEUE_VIA_SCHEDULER` différent de `false` |
| `basalto:license` | toutes les heures | `0 * * * *` | mettre à jour l’état de la licence (hors ligne) |
| `basalto:cleanup` | chaque jour à 03:00 | `0 3 * * *` | passe de suppression selon les délais de conservation |
| `basalto:backup` | chaque jour à 03:15 | `15 3 * * *` | sauvegarde, puis conservation selon `backup.keep` |
| `basalto:demo-reset --force` | chaque jour à 02:30, uniquement avec `BASALTO_DEMO=true` | `30 2 * * *` | reconstruire la démo |
| `basalto:media-purge` | chaque jour à 03:30 | `30 3 * * *` | vider la corbeille de la médiathèque |
| `basalto:update --benachrichtigen` | chaque jour à 04:10, pas en mode SaaS, désactivable avec `BASALTO_UPDATES_CHECK=false` | `10 4 * * *` | vérifier s’il existe des mises à jour ; l’administration est informée une fois de chaque nouvel état |

Administration › Exploitation affiche la dernière exécution de chaque tâche avec son résultat.

## Pour continuer

- [Installation](installation.md)
- [Hébergement](hosting.md)
