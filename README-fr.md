# Relances pour Dolibarr : factures clients impayées, par niveaux

Module gratuit fait par [Guiltek](https://www.guiltek.fr), sous licence GPL v3 ou plus. English version: [README.md](README.md).

Le module envoie les emails de relance des factures clients impayées, niveau après niveau (par exemple 3 jours, 15 jours et 30 jours après l'échéance), depuis une liste des clients à relancer ou par une tâche planifiée quotidienne. Les événements d'agenda des factures sont sa mémoire : chaque relance envoyée et chaque promesse de paiement est un événement, visible sur la facture.

## Ce qu'il fait

- **Niveaux** : jours après l'échéance (négatif = avant), libellé, destinataire (un type de contact de la facture, sinon l'email du client), modèle d'email, actif ou non.
  - **Un email par client** : les factures d'un client dues à ce niveau partent dans un seul email, avec le tableau des factures et leurs PDF.
  - **Envoi manuel** : la tâche n'envoie jamais ce niveau (une mise en demeure par exemple) : il attend dans la liste qu'un utilisateur l'envoie.
- **Liste Facturation > Relances** : la liste de travail, une ligne par client qui a des factures dans le circuit de relance (factures, montant relancé, par jours de retard : 1 à 30, 31 à 60, 61 à 90 et plus de 90, avec une ligne de total ; niveau atteint, promesse, prochaine relance), filtres sur le niveau, le commercial, le montant et les clients à relancer. Ce n'est pas une balance âgée de toutes les factures à payer : les factures en litige, les clients exclus, les prélèvements en cours et les factures avant leur premier niveau n'y sont pas. Un client qui a des réductions ou des avoirs pas encore utilisés est signalé. Actions de masse : envoyer le niveau dû (jamais un niveau réglé en envoi manuel, comme la mise en demeure ; la confirmation donne le nombre de factures et leur total), envoyer un email par client, promesse de paiement (sur toutes les factures du client), exclure le client des relances (avec un événement sur le client).
- **Onglet Relances de la facture** : niveau atteint, prochaine relance (automatique, envoi manuel, atteinte avant le premier passage de la tâche, ou tâche désactivée), destinataires, historique, boutons pour envoyer le niveau dû (la confirmation cite les autres factures d'un email groupé), noter une promesse de paiement ou ouvrir un litige (une facture en litige n'est jamais relancée). Une facture non relancée donne la raison (payée, litige, client exclu, demande de prélèvement en attente depuis N jours, aucun niveau) et un lien pour la changer.
- **Promesse de paiement** : aucune relance jusqu'au jour promis, puis le niveau suivant. Avec un montant (onglet de la facture), la promesse est affichée tenue, en attente ou non tenue selon les paiements reçus depuis.
- **Mise en demeure (PDF)** : un modèle de document des tiers (onglet Documents du client, à imprimer ou envoyer en recommandé) : les factures en retard du client, les pénalités dues et les coordonnées bancaires. Un niveau peut la joindre à son email : elle réclame alors les factures et le total de l'email. « Malgré nos précédentes relances » seulement après une relance.
- **Relevé de compte (PDF)** : toutes les factures à payer du client en entier, échues ou non (en litige et prélevées comprises), et leur balance âgée (avec un échéancier, chaque échéance à son âge) ; depuis l'onglet Documents du client, ou joint par un niveau à la place de la mise en demeure. Une facture en prélèvement en cours est marquée, et le RIB n'est pas imprimé quand elles le sont toutes.
- **Crédits du client** : la mise en demeure et le relevé donnent les réductions et les avoirs pas encore utilisés.
- **Clients jamais relancés** : un tag/catégorie choisi dans la configuration (module Tags/Catégories).
- **Page d'accueil** : une ligne « Clients à relancer » dans le bloc Factures, qui ouvre la liste filtrée.
- **Prêt à l'emploi** : à la première activation, 3 modèles d'email en français et en anglais (courtoisie, relance, mise en demeure) et 3 niveaux (3 jours avant l'échéance, 15 jours après avec un email par client, 30 jours après à la main).
- **Pénalités de retard et indemnité de recouvrement** (France, entre professionnels) : taux annuel et indemnité forfaitaire dans la configuration, calculés pour chaque facture en retard, affichés dans les emails et dans l'onglet de la facture ; un bouton prépare une facture brouillon séparée.
- **Factures payées en plusieurs fois** : avec le module gratuit [Échéances](https://github.com/guiltekmdion/dolibarr-echeances), une facture à échéancier est relancée échéance par échéance (la plus ancienne impayée, pour le montant dû), les niveaux repartent du premier à chaque nouvelle échéance, les pénalités courent depuis la date de chaque échéance. Échéances 1.1.0 ou plus (nom technique `installments`).
- **Prélèvements** : aucune relance tant que la demande de prélèvement attend ou que son bon n'est pas crédité (avec un échéancier, la demande de l'échéance relancée) ; aucun niveau avant l'échéance pour une facture ou une échéance réglée par prélèvement (la banque prélève ce jour-là). Après un rejet, les relances reprennent.
- **Liens de paiement en ligne** : les références des factures dans les emails mènent à la page de paiement en ligne seulement quand un module de paiement en ligne est actif et que la clé de sécurité des paiements est réglée (`PAYMENT_SECURITY_TOKEN`) ; une phrase sous le tableau le dit alors.
- **Statistiques** (Facturation > Relances > Statistiques) : relances envoyées et factures relancées par mois et par niveau, montant encaissé dans les 30 jours après une relance, délai moyen de la première relance au paiement complet.
- **Devises étrangères** : les emails et la mise en demeure affichent les montants dans la devise de chaque facture (paiements dans cette devise), un email par client et par devise ; la liste additionne les montants dans la devise de la société.
- **Clés dans l'éditeur de modèles** : les clés `__DUNNING_*__` apparaissent dans la liste des clés de l'éditeur de modèles, avec leurs valeurs quand une facture est envoyée à la main depuis sa fiche.

## Règles

- Le niveau suivant est le premier niveau actif après le niveau le plus avancé déjà envoyé : jamais de retour en arrière, jamais deux niveaux le même jour, et l'écart entre deux niveaux est respecté.
- Sont relancées les factures validées impayées (standard, de remplacement, d'acompte, de situation), sauf les avoirs, les factures en litige et celles dont le prélèvement est en cours. Une retenue de garantie est relancée à partir de sa propre date.
- Une fois la facture de pénalités créée, les relances de la facture ne réclament plus les pénalités ; une facture de pénalités n'en doit aucune.
- **Premier passage de la tâche** : il n'envoie rien pour les niveaux atteints avant lui. Ils attendent dans la liste qu'un utilisateur les envoie.
- L'email part d'abord, puis un événement est créé sur chaque facture, chacun pour soi. Chaque email relit les événements juste avant de partir : un niveau n'est jamais envoyé deux fois.
- Un email par client et par niveau veut aussi dire un par groupe de destinataires : les contacts de deux factures reçoivent chacun leur facture.
- Rien ne part du module, ni par la tâche ni à la main, tant que la tâche de relance de Dolibarr (`sendEmailsRemindersOnInvoiceDueDate`) est activée : les clients ne sont pas relancés deux fois. Un bandeau le dit sur la liste et sur l'onglet.
- Avec un échéancier, une échéance ne réclame jamais plus que le reste à payer de la facture. Si les échéanciers ne peuvent pas être lus, rien n'est relancé et l'erreur est affichée (une facture n'est jamais relancée en entier par erreur).
- Un email parti sans le PDF d'une de ses factures : un avertissement sur la page, une erreur de la tâche. Un client sans email : un avertissement, pas une erreur de la tâche tous les jours.

## Modèles d'email

Les modèles du module ont leur propre type, **Relances (module Relances)** : les tâches de relance de Dolibarr, qui prennent les modèles du type Factures clients, ne les envoient jamais, et ils ne sont plus dans le formulaire d'envoi d'une facture. Le module n'envoie que ses propres modèles (colonne `module` = `dunning`) : ceux qu'il a créés, et ceux qu'un administrateur a adoptés dans la configuration du module. Un modèle créé par un autre utilisateur, même avec le même libellé, ne part jamais : personne d'autre ne peut changer le texte envoyé aux clients. Pour ajouter une langue ou votre propre modèle : le créer dans Accueil > Configuration > Emails > Modèles des courriels avec le type Relances, puis cliquer sur **Adopter** dans la configuration du module ; la configuration liste les modèles, leur langue et les niveaux qui s'en servent.

## Limites connues

- La mémoire des relances est l'agenda : un utilisateur qui peut supprimer les événements de l'agenda peut faire repartir une relance. Gardez ce droit aux personnes qui en ont besoin.
- Pas de verrou contre deux lancements en même temps (la tâche et un utilisateur, à la même seconde) : le même niveau pourrait partir deux fois.
- La mise en demeure et le relevé sont rangés dans l'onglet Documents du client : qui peut lire les tiers peut les lire.
- Le lien de paiement en ligne porte sur toute la facture, pas sur l'échéance relancée.
- Avec un échéancier, la facture de pénalités est faite une fois par facture : une fois faite, les pénalités des échéances suivantes ne sont plus réclamées.

## Prérequis

Dolibarr 24.0 ou plus, avec les modules Factures clients, Travaux planifiés et Agenda. PHP 8.1 ou plus. MySQL/MariaDB ou PostgreSQL.

## Installation

1. Télécharger `module_dunning-X.Y.Z.zip` dans les [versions publiées](https://github.com/guiltekmdion/dolibarr-relances/releases).
2. Dans Dolibarr : Accueil > Configuration > Modules > Déployer/Installer un module externe, puis envoyer le zip. Ou le décompresser dans `htdocs/custom` (le dossier `dunning` doit être en `htdocs/custom/dunning`).
3. Activer le module **Relances** (dans Modules financiers), puis donner les droits aux utilisateurs.

Après une mise à jour du module, le désactiver puis le réactiver : **obligatoire**, les pages du module sont en erreur tant que ce n'est pas fait. Les nouvelles colonnes, les modèles et les réglages sont ajoutés ; vos niveaux, vos modèles et la date du premier passage de la tâche sont gardés.

De la 1.5 à la 1.6 : l'activation donne aux modèles du module leur propre type, et remplace les textes des modèles « 1 Courtoisie » et « 3 Mise en demeure » par les nouveaux seulement si vous ne les avez pas changés. Entre la mise à jour des fichiers et l'activation, les relances gardent leurs modèles. Un modèle de Relances choisi dans une tâche de relance de Dolibarr n'est plus trouvé par cette tâche : donnez-lui un de vos modèles. Un niveau réglé sur un modèle qui n'est pas de Relances (un modèle de factures, par exemple) reçoit une copie de ce modèle, nommée « … (Relances N) », qui devient un modèle du module ; le modèle d'origine ne change pas.

## Configuration

1. Vérifier les modèles d'email « Relances - 1, 2, 3 » (Accueil > Configuration > Emails > Modèles des courriels, type **Relances**), ou créer les vôtres et les adopter (voir Modèles d'email). Un modèle du module de même libellé dans une autre langue sert aux clients de cette langue. Clés des modèles :
   - `__DUNNING_LEVEL__` : nom du niveau
   - `__DUNNING_DAYS_LATE__` : jours de retard de la facture la plus ancienne
   - `__DUNNING_INVOICES__` : tableau des factures de l'email, avec les liens de paiement quand le paiement en ligne est réglé
   - `__DUNNING_TOTAL_REMAIN__` : total restant à payer
   - `__DUNNING_PENALTIES__`, `__DUNNING_INDEMNITY__`, `__DUNNING_TOTAL_WITH_PENALTIES__` : pénalités de retard, indemnité de recouvrement et total avec elles
   - `__DUNNING_PENALTIES_TEXT__` : une phrase qui cite les pénalités et l'indemnité dues, vide si rien n'est dû
   - les clés habituelles (`__REF__`, `__ONLINE_PAYMENT_URL__`...) marchent aussi, mais dans un email de plusieurs factures, seules les clés `__DUNNING_*__` les décrivent toutes.
2. Configuration du module : une ligne par niveau (avec « Mise en demeure » pour joindre le PDF à son email), et le tag des clients jamais relancés.
3. Pénalités de retard (facultatif) : le taux annuel, l'indemnité forfaitaire de recouvrement (une fois par facture en retard encore impayée), et un tag des clients qui n'en doivent jamais. Seuls les clients reconnus comme entreprises par Dolibarr (numéro de TVA ou identifiant professionnel) doivent des pénalités. Elles sont affichées, jamais ajoutées à la facture : le bouton de l'onglet prépare une facture brouillon séparée, à vérifier et valider.
4. Pour envoyer automatiquement : activer la tâche « Envoyer les relances des factures clients impayées » dans Accueil > Outils d'administration > Travaux planifiés, et laisser désactivée la tâche de relance de Dolibarr.

Droits : « Lire les relances » (il faut aussi le droit de lire les factures), « Envoyer les relances, enregistrer les promesses de paiement » (avec les droits avancés, il faut aussi le droit d'envoyer les factures par email). Les niveaux sont réglés par un administrateur.

Avant le premier envoi, vérifier la liste : une action de masse envoie de vrais emails à vos clients.

## Support

Questions et bogues : [tickets](https://github.com/guiltekmdion/dolibarr-relances/issues). Développements sur mesure : [Guiltek](https://www.guiltek.fr).

## Licence

Copyright (C) 2026 Maxime DION, Guiltek.
Ce programme est un logiciel libre : vous pouvez le redistribuer et/ou le modifier selon les termes de la GNU General Public License publiée par la Free Software Foundation, version 3 ou (à votre choix) toute version ultérieure. Voir [COPYING](COPYING).
