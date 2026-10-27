# Rôles et droits

Le **rôle** détermine qui peut faire quoi dans le backend. Une personne a exactement un rôle par site ; les rôles
eux-mêmes s’appliquent à tous les sites de l’installation (ou du compte SaaS). Vous les gérez sous **Administration ›
Rôles**.

![La matrice des rôles : un niveau par domaine, plus les langues d’un rôle](../verwaltung/bilder/rollen.webp)

## Domaines et niveaux

Un rôle a un **niveau** par **domaine**. Les niveaux s’appuient les uns sur les autres :

| Niveau | Signification |
| --- | --- |
| **aucune** | Le domaine n’apparaît pas dans le menu, ses adresses répondent par 403. |
| **voir** | Lire, sans rien modifier. |
| **modifier** | Créer et modifier. |
| **supprimer** | En plus, supprimer. |
| **tout** | En plus, les paramètres sensibles du domaine — par exemple le webhook d’un formulaire, les paramètres et l’export des membres ou la clé des suggestions IA. |

Les domaines sont classés en trois groupes. Si un rôle ne voit aucun domaine d’un groupe, le sélecteur de mode vers ce
groupe disparaît lui aussi.

| Groupe | Domaines |
| --- | --- |
| **Contenus** | Pages et contenus · Médias · Traduction · Validation et publication · Formulaires et envois · issus de modules : Commentaires, Membres, Suggestions IA |
| **Configuration** | Navigation · Design, thèmes et mises en page · Types et blocs réutilisables · Publication, SEO et redirections |
| **Administration** | Utilisateurs et rôles · Paramètres, modules et sites · Licence · Exploitation · Journal · issus de modules : Vente de licences, Marketplace, Gestion des tests |

Les modules déclarent eux-mêmes leurs domaines ; un module nouvellement installé apparaît immédiatement dans la matrice.

## Modèles

Cinq rôles sont prédéfinis. Vous pouvez les adapter et les **réinitialiser** à tout moment à leur état d’usine — sauf
« Exploitant ».

| Modèle | Peut |
| --- | --- |
| **Exploitant** | Tout. Non modifiable, afin qu’aucun site ne puisse se retrouver verrouillé. Chaque site conserve au moins un exploitant. |
| **Administrateur** | Tout, sauf attribuer le rôle Exploitant. |
| **Rédacteur** | Pages, médias et formulaires jusqu’à « supprimer », traduction, commentaires, membres et suggestions IA « modifier ». Ni configuration, ni administration, ni validation. |
| **Approbateur** | Validation et publication « tout » ; pages, médias, traduction, formulaires et commentaires « voir ». |
| **Traducteur** | Pages et traduction « modifier », médias « voir », suggestions IA « modifier » — dans toutes les langues **sauf la langue source**. |

Le propriétaire et l’administration d’un compte SaaS peuvent tout faire sur tous les sites du compte, comme un
exploitant.

## Rôles propres

**Nouveau rôle** crée un rôle vide (qui ne peut rien) ou une copie d’un rôle existant (« À partir de » ou **Copier**).
Dans la matrice, vous choisissez le niveau de chaque domaine ; « tout sur » règle un groupe entier d’un coup. En bas,
l’aperçu montre ce que voit le rôle (« Contenus · Configuration · Administration ») et combien de personnes l’ont.
Chaque modification s’enregistre d’elle-même (une série de modifications forme une seule ligne dans le journal) et
s’applique à partir de la requête suivante.

Un rôle s’attribue comme auparavant sous **Utilisateurs** (sélection dans la liste ou lors de l’invitation). Un rôle
propre peut être **supprimé** tant que personne ne l’a et qu’aucune invitation ouverte ne le désigne.

## Langues

Pour chaque rôle, vous définissez dans quelles langues il peut modifier les contenus : **toutes**, **toutes sauf la
langue source** ou une **Sélection**. La langue source (langue par défaut du site) n’en fait partie que si vous la
choisissez. Créer, supprimer et déplacer des pages ainsi que modifier leurs paramètres exige la langue source. Le
verrou s’applique dans l’éditeur, dans la vue « Traduction », pour les suggestions IA, sur la page elle-même (éditeur
frontend) et via l’API.

## Règles que personne ne contourne

- **Exploitant reste non modifiable.** Le rôle ne peut être ni modifié ni supprimé ; personne ne peut rétrograder ni
  désactiver le dernier exploitant d’un site.
- **Pas de droits offerts.** Qui attribue, retire ou modifie un rôle doit disposer, dans chaque domaine, au moins du
  même niveau et de chacune de ses langues. Seul un exploitant attribue le rôle Exploitant.
- **Modifier des rôles exige « Utilisateurs et rôles · tout ».** Pour les consulter, « voir » suffit.
- **Les tokens d’API** agissent avec les droits de leur utilisateur — le token d’une personne de la traduction ne
  modifie pas la langue source.
- **Tout figure dans le journal :** rôle créé, modifié (avant/après), réinitialisé, supprimé, et chaque nouvelle
  attribution.

## Pour les développeurs

Un module déclare ses domaines dans son service provider et y rattache ses routes ; les routes sans rattachement exigent
« Paramètres · tout » (mieux vaut trop strict que trop ouvert), et un test les détecte. Un droit se vérifie avec
`Gate::allows('area', [$site, 'bereich', Level::Edit])`. Détails sous [Modules et plugins](../entwickler/erweiterungen.md).

## Pour continuer

- [Rôles](../verwaltung/rollen.md)
- [Utilisateurs](../verwaltung/benutzer.md)
