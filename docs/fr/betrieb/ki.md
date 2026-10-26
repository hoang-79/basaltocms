# Configurer les suggestions IA

Le module fait des suggestions à la rédaction avec la **clé du client** : améliorer un texte, textes alternatifs à
partir de l’image, traduction champ par champ et indications SEO (titre de page, description). Il fonctionne via le
[Laravel AI SDK](https://laravel.com/docs/ai-sdk) (`laravel/ai`) et appelle le fournisseur directement depuis
l’installation.

Cela vaut de la même manière pour les installations propres et pour le SaaS.

![Les paramètres des suggestions IA : fournisseur, modèle, clé, limites et fonctions](../redaktion/bilder/ki-einstellungen.webp)

## Principes

- **Uniquement sur clic, toujours une suggestion.** Aucun appel en arrière-plan, aucun au chargement d’une page, aucun
  job. La réponse est une suggestion ; « Appliquer » la place dans le champ, et l’enregistrement automatique écrit dans
  le **brouillon** — jamais dans une version publiée. Seule exception : les textes alternatifs s’appliquent dès
  l’enregistrement (les médias n’ont pas de versions) ; la reprise figure dans le journal (`ai.alt_text.applied`).
- **La clé appartient au client** et est déposée par site. Elle est stockée **chiffrée** avec l’`APP_KEY` dans
  `ai_settings.api_key`, n’est jamais transmise au navigateur (l’interface n’affiche que « définie ✓ · …1234 »),
  n’apparaît jamais dans le journal et ne figure pas dans l’export d’un site. Après un transfert ou un nouvel
  `APP_KEY`, vous la saisissez à nouveau.
- **Chaque utilisation figure dans le journal** (`ai_usages`) : site, utilisateur, fonction, contenu ou média, langue,
  fournisseur, modèle, jetons (si le fournisseur les communique), succès ou type d’erreur, reprise oui/non. **Sans**
  texte, prompt, réponse ni clé. Les messages d’erreur du fournisseur n’arrivent pas dans le log, seulement leur type
  (`rate_limited`, `auth` …).
- **Désactivé d’usine.** À activer sous **Administration › Sites › Modifier le site › onglet « Modules »** ›
  « Suggestions IA ».

## Configuration

1. Activer le module pour le site (voir ci-dessus).
2. **Administration › Suggestions IA** (`/admin/ki`) — uniquement l’exploitant et l’administration du site (ou le
   propriétaire et l’administration du compte) :
   - Choisir le **Fournisseur** : OpenAI, Anthropic (Claude), Google Gemini, Mistral AI, xAI (Grok), DeepSeek, Groq ou
     OpenRouter. Les textes alternatifs nécessitent un fournisseur qui lit les images ; avec DeepSeek et Groq, la
     fonction est désactivée.
   - **Modèle** — vide signifie : la valeur par défaut du SDK pour ce fournisseur (affichée comme espace réservé dans le
     champ). Vous saisissez un modèle de votre choix (par exemple un modèle moins cher) sous le nom qu’il porte chez le
     fournisseur.
   - Coller la **Clé** (clé d’API) et enregistrer. Laisser vide conserve la clé actuelle ; « Retirer la clé » désactive
     tous les boutons IA du site.
   - **Limite journalière** (suggestions par jour pour tout le site, 100 par défaut) et **Plafond de coûts** (jetons
     par mois, vide = sans). S’y ajoute une règle fixe : au plus dix suggestions par personne et par minute.
   - Activer ou désactiver les **Fonctions** une par une.
3. Essayer une fois « ✦ Améliorer le texte » dans l’éditeur. Si un message apparaît au lieu d’une suggestion, il en
   indique le motif (clé refusée, crédit épuisé, fournisseur surchargé, modèle inconnu).

Chaque modification du paramétrage figure dans le journal du noyau (`ai.settings.changed`, pour la clé uniquement
« nouvellement définie »).

## Protection des données

**Les contenus sont transmis au fournisseur.** Chaque suggestion envoie le contenu concerné, avec la clé du site, au
fournisseur choisi : le texte d’un champ, le texte source d’une traduction, le texte d’une page (SEO) ou l’image
(textes alternatifs ; la plus grande variante jusqu’à 1600 px, sinon l’original jusqu’à 8 Mo). L’interface le signale
à chaque endroit : sur la page des paramètres par un avis avec un lien vers la déclaration de protection des données du
fournisseur, et dans chaque fenêtre de suggestion avec le nom du fournisseur.

Ce que vous devriez clarifier :

- **Sous-traitance :** la relation contractuelle avec le fournisseur est celle du client (sa clé, son compte).
  Vérifiez les conditions de celui-ci concernant l’enregistrement des saisies et l’entraînement avec celles-ci ; de
  nombreux fournisseurs proposent pour les accès API un réglage sans entraînement et avec une conservation courte.
- **Localisation :** plusieurs fournisseurs traitent les données aux États-Unis. Pour les données personnelles contenues
  dans les textes, les règles relatives à la communication à l’étranger s’appliquent (nLPD, RGPD).
- **Déclaration de protection des données du site :** mentionner le fournisseur si des contenus comportant des
  données personnelles sont traités.
- **Former la rédaction :** ne rien faire suggérer de confidentiel ; l’aide de la rédaction
  ([Suggestions IA](../redaktion/ki.md)) le dit aussi.

## Passerelle ou proxy

Si le trafic vers le fournisseur passe par une passerelle (proxy d’entreprise, point de terminaison européen),
définissez l’URL de base pour toute l’installation dans le `.env` — les mêmes clés que dans le SDK, par ex.
`OPENAI_URL`, `ANTHROPIC_URL`, `GEMINI_URL`. Le module les reprend pour chaque site ; la clé reste celle du site.

## Sécurité

- Les saisies sont limitées : au plus 12'000 caractères par suggestion ; un texte riche contenant des images, des
  tableaux, des tâches ou des intégrations n’est pas envoyé au fournisseur (ces parties seraient perdues).
- Les sorties sont nettoyées : le texte riche revient en Markdown et passe par le convertisseur du noyau (le HTML brut
  est supprimé, liens uniquement en http(s), mailto, tel ou relatifs) ; le texte brut est livré sans HTML ni caractères
  de contrôle, tronqué à la longueur du champ.
- Droits identiques à ceux de l’édition : améliorer un texte et le SEO exigent le droit de modifier les contenus, la
  traduction le droit sur le contenu, les textes alternatifs le droit sur la médiathèque. Les autres sites ne voient et
  n’utilisent rien.

## Utilisation et coûts

**Administration › Suggestions IA › Utilisation** affiche par mois (les douze derniers) le nombre de suggestions, dont
celles reprises et celles en échec, les jetons et la répartition entre les fonctions, ainsi que les 50 derniers appels
avec la personne, la fonction, le contenu, le modèle, les jetons et le résultat. La facturation a lieu chez le
fournisseur, sur le compte du client — Basalto ne facture rien.

## Tables

| Table | Contenu | Export d’un site |
| --- | --- | --- |
| `ai_settings` | par site : fournisseur, modèle, clé (chiffrée), limite journalière, plafond de coûts, commutateurs | non — secret |
| `ai_usages` | journal d’utilisation sans contenus | non — journal de l’installation source |

## Symptômes d’erreur

| Message | Cause | Remède |
| --- | --- | --- |
| « … a refusé la clé » | clé erronée, bloquée ou sans droit sur le modèle | générer une nouvelle clé chez le fournisseur et la saisir |
| « Le crédit du compte … est épuisé » | crédit prépayé vide | recharger chez le fournisseur |
| « … limite les requêtes » | limite de débit du fournisseur | réessayer plus tard ; le cas échéant, niveau supérieur chez le fournisseur |
| « … n’a pas accepté la requête — le modèle est-il correct ? » | nom de modèle inconnu ou sans saisie d’images | vider le modèle (valeur par défaut du SDK) ou le corriger |
| « La limite journalière … est atteinte » | limite journalière du site | augmenter la limite ou attendre le lendemain |
| « Le plafond de coûts … est atteint » | jetons du mois au-dessus de la limite | augmenter la limite ou la vider |
| Boutons grisés « (IA désactivée) » | aucune clé ou fonction désactivée | vérifier le paramétrage |

## Pour continuer

- [Suggestions IA](../redaktion/ki.md)
- [Administration](../verwaltung/README.md)
