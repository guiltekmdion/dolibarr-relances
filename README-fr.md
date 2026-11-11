# Relances pour Dolibarr : factures clients impayées, par niveaux

Module gratuit fait par [Guiltek](https://www.guiltek.fr), sous licence GPL v3 ou plus. English version: [README.md](README.md).

Le module envoie les emails de relance des factures clients impayées, niveau après niveau (par exemple 3 jours, 15 jours et 30 jours après l'échéance), depuis une liste des clients à relancer ou par une tâche planifiée quotidienne. Les événements d'agenda des factures sont sa mémoire : chaque relance envoyée et chaque promesse de paiement est un événement, visible sur la facture.

## Ce qu'il fait

- **Niveaux** : jours après l'échéance (négatif = avant), libellé, destinataire (un type de contact de la facture, sinon l'email du client), modèle d'email, actif ou non.
  - **Un email par client** : les factures d'un client dues à ce niveau partent dans un seul email, avec le tableau des factures et leurs PDF.
  - **Envoi manuel** : la tâche n'envoie jamais ce niveau (une mise en demeure par exemple) : il attend dans la liste qu'un utilisateur l'envoie.
- **Liste Facturation > Relances** : une ligne par client (factures, reste à payer, plus de 30 jours de retard, niveau atteint, promesse, prochaine relance), filtres sur le niveau, le commercial, le montant et les clients à relancer. Actions de masse : envoyer le niveau dû, envoyer un email par client, promesse de paiement, ne pas relancer.
- **Onglet Relances de la facture** : niveau atteint, prochaine relance, destinataires, historique, boutons pour envoyer le niveau dû, noter une promesse de paiement ou ouvrir un litige (une facture en litige n'est jamais relancée).
- **Promesse de paiement** : aucune relance jusqu'au jour promis, puis le niveau suivant.
- **Clients jamais relancés** : un tag/catégorie choisi dans la configuration (module Tags/Catégories).
- **Page d'accueil** : une ligne « Clients à relancer » dans le bloc Factures, qui ouvre la liste filtrée.

## Règles

- Le niveau suivant est le premier niveau actif après le niveau le plus avancé déjà envoyé : jamais de retour en arrière, jamais deux niveaux le même jour, et l'écart entre deux niveaux est respecté.
- Sont relancées les factures validées impayées (standard, de remplacement, d'acompte, de situation), sauf les avoirs et les factures en litige. Une retenue de garantie est relancée à partir de sa propre date.
- **Premier passage de la tâche** : il n'envoie rien pour les niveaux atteints avant lui. Ils attendent dans la liste qu'un utilisateur les envoie.
- L'email part d'abord, puis un événement est créé sur chaque facture. Chaque email relit les événements juste avant de partir : un niveau n'est jamais envoyé deux fois.
- La tâche ne tourne pas tant que la tâche de relance de Dolibarr (`sendEmailsRemindersOnInvoiceDueDate`) est activée : les clients ne sont pas relancés deux fois.

## Prérequis

Dolibarr 24.0 ou plus, avec les modules Factures clients, Travaux planifiés et Agenda. PHP 7.2 ou plus. MySQL/MariaDB ou PostgreSQL.

## Installation

1. Télécharger `module_dunning-X.Y.Z.zip` dans les [versions publiées](https://github.com/guiltekmdion/dolibarr-relances/releases).
2. Dans Dolibarr : Accueil > Configuration > Modules > Déployer/Installer un module externe, puis envoyer le zip. Ou le décompresser dans `htdocs/custom` (le dossier `dunning` doit être en `htdocs/custom/dunning`).
3. Activer le module **Relances** (dans Modules financiers), puis donner les droits aux utilisateurs.

## Configuration

1. Créer les modèles d'email des niveaux (Accueil > Configuration > Emails > Modèles des courriels, type **Factures clients**). Un modèle de même libellé dans une autre langue sert aux clients de cette langue. Clés des modèles :
   - `__DUNNING_LEVEL__` : nom du niveau
   - `__DUNNING_DAYS_LATE__` : jours de retard de la facture la plus ancienne
   - `__DUNNING_INVOICES__` : tableau des factures de l'email, avec les liens de paiement
   - `__DUNNING_TOTAL_REMAIN__` : total restant à payer
   - les clés habituelles (`__REF__`, `__ONLINE_PAYMENT_URL__`...) marchent aussi, mais dans un email de plusieurs factures, seules les clés `__DUNNING_*__` les décrivent toutes.
2. Configuration du module : une ligne par niveau, et le tag des clients jamais relancés.
3. Pour envoyer automatiquement : activer la tâche « Envoyer les relances des factures clients impayées » dans Accueil > Outils d'administration > Travaux planifiés, et laisser désactivée la tâche de relance de Dolibarr.

Droits : « Lire les relances » (il faut aussi le droit de lire les factures), « Envoyer les relances, enregistrer les promesses de paiement » (avec les droits avancés, il faut aussi le droit d'envoyer les factures par email). Les niveaux sont réglés par un administrateur.

Avant le premier envoi, vérifier la liste : une action de masse envoie de vrais emails à vos clients.

## Support

Questions et bogues : [tickets](https://github.com/guiltekmdion/dolibarr-relances/issues). Développements sur mesure : [Guiltek](https://www.guiltek.fr).

## Licence

Copyright (C) 2026 Maxime DION, Guiltek.
Ce programme est un logiciel libre : vous pouvez le redistribuer et/ou le modifier selon les termes de la GNU General Public License publiée par la Free Software Foundation, version 3 ou (à votre choix) toute version ultérieure. Voir [COPYING](COPYING).
