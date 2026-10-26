# Surveillance

Basalto affiche les chiffres d’exploitation là où vous regardez de toute façon : sous **Administration › Exploitation**
dans le backend. S’y ajoutent le tableau de bord de [Laravel Pulse](https://laravel.com/docs/pulse) et une alerte par
e-mail lorsque les erreurs s’accumulent — le tout sans service tiers.

![La page « Exploitation » du backend : pulsation, file d’attente, base de données, stockage, sauvegardes et tâches](../verwaltung/bilder/betrieb.webp)

| Quoi | Où | Qui le voit |
| --- | --- | --- |
| Pulsation, file d’attente, base de données, cache, stockage, sauvegardes, scheduler | Administration › Exploitation, `/up`, `/up/live` | les personnes autorisées à administrer le site |
| Indicateurs de Pulse (dernière heure, tous les sites) et état de l’alerte d’erreurs | Administration › Exploitation, section « Indicateurs de Pulse » | exploitants de l’installation |
| Tableau de bord Pulse avec toutes les cartes | `/admin/pulse` | exploitants de l’installation |
| Alerte d’erreurs | e-mail | exploitants de l’installation |

Les **exploitants de l’installation** sont le propriétaire et l’administration du compte de l’installation — le premier
compte que crée `php artisan basalto:install`. Les exploitants ou administrateurs d’un site isolé ne voient pas les
chiffres de Pulse, car ceux-ci englobent tous les sites de l’installation (sur le SaaS, également ceux des autres
clients). Les comptes désactivés ne comptent pas.

## Pulse

Pulse fait partie de l’application (`laravel/pulse` 1.8) et enregistre dans la base de données de l’application :
tables `pulse_values`, `pulse_entries` et `pulse_aggregates`, créées par `php artisan migrate`. La configuration se
trouve dans `config/pulse.php`.

- **Tableau de bord :** `/<BASALTO_BACKEND_PATH>/pulse`, donc le plus souvent `/admin/pulse` (chemin propre avec
  `PULSE_PATH`). Les invités arrivent sur la connexion ; toutes les autres personnes, hormis les exploitants de
  l’installation, reçoivent 403. Le lien figure aussi sur la page « Exploitation ».
- **Sur « Exploitation » :** exceptions signalées (les plus fréquentes avec leur emplacement), requêtes HTTP lentes
  au-dessus du seuil (`PULSE_SLOW_REQUESTS_THRESHOLD`, 1000 ms par défaut), requêtes SQL lentes, jobs traités, en
  échec et remis en file, jobs lents — chaque fois sur la dernière heure.
- **Désactiver :** `PULSE_ENABLED=false`. Pulse n’enregistre alors plus rien ; « Exploitation » l’indique et affiche
  les derniers chiffres saisis. Dans `phpunit.xml`, Pulse est désactivé.
- **Conservation :** Pulse supprime lui-même les entrées au bout de sept jours (`PULSE_STORAGE_KEEP`).
- **Trafic important :** échantillonnage par enregistreur (`PULSE_USER_REQUESTS_SAMPLE_RATE=0.1`, etc.) ou ingestion
  via Redis (`PULSE_INGEST_DRIVER=redis`, avec en permanence `php artisan pulse:work`). Sur un hébergement simple,
  ni l’un ni l’autre n’est nécessaire.
- **Cache des cartes :** les cartes placent leurs requêtes sous forme d’objets dans le cache ; Laravel 13 ne
  désérialise pas d’objets depuis le cache (`cache.serializable_classes`). Basalto les place donc, requête par requête,
  dans le store array (`PULSE_CACHE_DRIVER=array`, valeur par défaut dans `config/pulse.php`). Conséquence :
  `php artisan pulse:restart` n’atteint pas les processus `pulse:check`/`pulse:work` en cours — redémarrer ces
  processus après le déploiement (Laravel Cloud le fait de toute façon).
- **Carte des serveurs :** la mémoire et le CPU n’apparaissent que si `php artisan pulse:check` tourne en permanence
  (sur Laravel Cloud, comme processus d’arrière-plan). Sur un hébergement simple, la carte reste vide — ce n’est pas une
  erreur.
- **Bases de données :** Pulse prend en charge SQLite, MariaDB, MySQL et PostgreSQL. Sur SQL Server, la migration
  ignore les tables ; « Exploitation » indique alors « Pulse n’est pas configuré », et `PULSE_ENABLED=false` doit
  figurer dans le `.env`.
- **Protection des données :** Pulse enregistre des identifiants d’utilisateurs (carte « Application Usage »), des
  chemins, des requêtes et les emplacements des exceptions, aucun contenu. Basalto affiche les personnes sans Gravatar
  (aucun appel à un service tiers). Le tableau de bord de Pulse lui-même charge des ressources tierces : la police de
  `fonts.bunny.net` et, dans la carte « Slow Outgoing Requests », les logos des hôtes cibles depuis `unavatar.io`. Cela
  ne concerne que les exploitants, pas les visiteurs du site.
- **Livewire :** Pulse apporte Livewire. Basalto désactive son intégration automatique (`livewire.inject_assets`) afin
  que `livewire.js` n’arrive jamais dans les pages du site ou du backend.

## Alerte d’erreurs

Basalto compte chaque exception signalée par minute dans le cache — que Pulse enregistre ou non. Le scheduler appelle
`php artisan basalto:error-alarm` chaque minute. Si la fenêtre contient au moins autant d’exceptions que le seuil, un
e-mail part vers les exploitants de l’installation ; ensuite, le calme règne pendant la durée de la pause, même si les
erreurs persistent. Les exceptions non signalées (404, validation) ne comptent pas.

| Paramètre (Administration › Paramètres › Exploitation) | Clé | Valeur par défaut |
| --- | --- | --- |
| Alerte d’erreurs à partir de | `operations.error_alarm_threshold` | 20 exceptions (0 = pas d’alerte) |
| Fenêtre de l’alerte d’erreurs | `operations.error_alarm_minutes` | 5 minutes |
| Pause après une alerte d’erreurs | `operations.error_alarm_pause_minutes` | 60 minutes |

Conditions : le scheduler tourne (cron chaque minute ou Laravel Cloud) et l’e-mail est configuré (`MAIL_*` dans le
`.env`). Avec `MAIL_MAILER=log`, l’e-mail figure uniquement dans le journal. La carte « Alerte d’erreurs » sur
« Exploitation » affiche le seuil, le nombre actuel, la dernière alerte et le nombre de destinataires.

Vérifier à la main :

```sh
php artisan basalto:error-alarm     # indique « unter der Schwelle », « gedrosselt » ou « Alarm an n Betreiber »
```

(« unter der Schwelle » : sous le seuil ; « gedrosselt » : limité ; « Alarm an n Betreiber » : alerte envoyée à
n exploitants.)

## Surveiller plusieurs instances

Si vous exploitez de nombreuses instances, vous pouvez en plus raccorder
[Laravel Nightwatch](https://nightwatch.laravel.com) : requêtes HTTP, requêtes SQL, jobs et exceptions sur toutes les
instances. Les variables prévues figurent en commentaire dans `.env.example` (`NIGHTWATCH_ENABLED`, `NIGHTWATCH_TOKEN`
et l’échantillonnage) ; vous installez vous-même le paquet `laravel/nightwatch`. Nightwatch transmet des données
d’exploitation à Laravel — vérifiez la sous-traitance et la déclaration de protection des données avant de l’activer.

## Pour continuer

- [Exploitation](../verwaltung/betrieb.md)
- [Hébergement](hosting.md)
- [Commandes](befehle.md)
