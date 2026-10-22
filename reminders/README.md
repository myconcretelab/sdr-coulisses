# Inviter les joueurs et relancer les non-inscrits

## À la création d’un spectacle

La fenêtre **Nouveau spectacle** propose trois actions :

- **Enregistrer le spectacle** : publie la date sans envoyer d’email.
- **Enregistrer et prévenir les joueurs** : publie la date puis ouvre la même fenêtre
  de sélection que les relances. Les personnes admissibles sont cochées par défaut ;
  décocher les personnes à ne pas prévenir puis confirmer **Envoyer à N personnes**.
- **Enregistrer et ouvrir Gutenberg** : publie la date sans email puis ouvre l’éditeur.

Fermer la sélection sans confirmer ne déclenche aucun email : le spectacle reste
bien enregistré. Une date passée ou fermée aux réponses ne peut pas être annoncée.
L’email d’annonce utilise le même modèle, les mêmes boutons et les mêmes liens
personnels que la relance, avec le titre **Une nouvelle date. Et si tu en étais ?**.

La publication directe d’une nouvelle fiche depuis WordPress conserve son annonce
automatique existante (lors de la publication REST ou par l’événement planifié),
désormais limitée aux profils **OK pour la scène**, avec ce même email HTML.
Ce déclenchement ne concerne pas les trois actions de création des Coulisses :
elles passent uniquement par les choix décrits ci-dessus.

## Pour une date existante

Dans le planning des Coulisses, chaque date propose **Composer l’équipe** aux
organisateurs et administrateurs. La fenêtre conserve le tableau des sélections,
les candidatures, les besoins et les deux actions de sauvegarde avec ou sans email.
**Relancer les non-inscrits** se trouve à droite du titre **Composer l’équipe** ;
il réutilise la sélection des destinataires et le service d’envoi existants.

Chaque carte affiche **Équipe à composer**, **Équipe incomplète** ou **Équipe complète**,
selon les personnes réellement retenues dans chaque rôle. Aucun besoin défini ne
signifie pas une équipe complète : la carte indique alors **Besoins à préciser**.
Les changements de sélection ou de besoins actualisent cet état dans le planning.
La rubrique séparée **Composer les équipes** est retirée ; les anciens liens
`?vue=equipes&spectacle=ID` ouvrent cette même fenêtre au-dessus du planning.

Le bouton de relance reste aussi disponible dans **Modifier**, à droite de
**Qui monte sur scène ?**. Enregistrer les changements du spectacle ou des besoins
avant de relancer. La relance est désactivée pendant ces changements, sur une
nouvelle date non enregistrée ou lorsque les réponses sont fermées.

La fenêtre affiche les personnes sans aucune réponse, ayant coché **OK pour la scène**
et au moins un rôle artistique. Les cases sont toutes cochées au départ ; décocher
les personnes à ne pas contacter puis confirmer avec **Envoyer à N personnes**.
Les rôles visibles correspondent au profil, sans restriction aux besoins du spectacle.
Une indisponibilité ou une réponse « si besoin » est déjà une réponse : pas de relance.
Les comptes de démonstration, les adresses `.invalid` / `.test` et les adresses de
remplacement `sdr@sdr.fr` / `sdr@sdr.com` sont exclus.

**OK pour la scène** est un choix distinct des rôles. Il est décoché par défaut et
modifiable dans **Mon profil** ou dans le profil WordPress, juste après les rôles.
Aucun compte existant n’est inscrit automatiquement. Le même attribut est disponible
comme filtre dans les relances utilisateurs générales ; leur interface WordPress
et leur fenêtre dans les Coulisses restent inchangées.

Pour modifier plusieurs comptes à la fois, ouvrir **Comptes → Tous les comptes**
(ou **Utilisateurs**), cocher les personnes puis choisir **OK pour la scène : activer**
ou **OK pour la scène : désactiver** dans **Actions groupées**, et cliquer sur
**Appliquer**. La colonne **OK pour la scène** affiche ensuite **Oui** ou **Non**.
Le bilan distingue les changements, les comptes déjà dans cet état et les éventuels
échecs. Cette action est réservée aux administrateurs autorisés à modifier chaque
compte sélectionné ; elle ne modifie que ce choix de contact et n’envoie aucun email.

## Email et réponse

Chaque destinataire reçoit un email HTML personnel au style du site, accompagné
d’une version texte. Il rappelle le titre, la date, le lieu, les horaires et les rôles.

- **OK, je me lance !** ouvre une petite fiche sans connexion, avec uniquement les
  rôles actuels du profil. Plusieurs rôles peuvent être proposés avant validation.
- **Je ne suis pas dispo** ouvre une confirmation d’indisponibilité sans connexion.

La fiche affiche **Confirme ta réponse** et un encadré indiquant dès l’arrivée
que la réponse n’est pas encore enregistrée. Les boutons de confirmation et de
changement de choix occupent toute la largeur, avec une taille adaptée au mobile.

Une simple consultation du lien, même répétée ou par un outil de sécurité des emails,
ne modifie aucune réponse. Seule une validation explicite par POST l’enregistre.
Le même service de sauvegarde est utilisé par cette fiche et les Coulisses.
Aucune session WordPress n’est créée. Pour modifier une réponse déjà enregistrée,
le membre retrouve le spectacle dans les Coulisses avec sa connexion habituelle.

Le secret aléatoire de 256 bits est lié à une personne et à un spectacle ; seule son
empreinte SHA-256 est conservée avec l’adresse et l’échéance. Il expire au plus tard
après 14 jours ou à la fin du jour du spectacle. Une nouvelle invitation remplace
le secret précédent. Le changement d’adresse, le retrait des rôles ou de l’accès
membre, la fermeture des réponses ou le passage de la date empêchent son utilisation.
Après validation, le lien est marqué utilisé. Il ne peut écraser ni une réponse ni
une sélection existante, même après le retrait ultérieur d’une réponse dans les Coulisses.
Le lien est personnel et peut permettre à son détenteur de répondre : ne pas le transférer.

La fiche ne charge aucun outil de suivi ou script. Elle interdit son intégration dans
un autre site et n’envoie pas le lien dans l’en-tête de provenance des requêtes.

## Fiabilité des envois

L’envoi est réservé aux organisateurs et administrateurs des Coulisses. Un identifiant
de demande rend les répétitions de création idempotentes. Les destinataires confirmés
sont conservés et revérifiés avant chaque email : une nouvelle réponse, un retrait
« OK pour la scène » ou un changement de profil/adresse évite une relance devenue inutile.
Un verrou partagé avec les réponses protège les opérations d’un même spectacle.
Les envois confirmés dans la fenêtre sont traités par lots de cinq ; garder la page ouverte. Une interruption
permet de reprendre les destinataires encore en attente, avec le même auteur.
Une transmission interrompue à l’intérieur d’un email est signalée comme incertaine
et n’est pas renvoyée automatiquement. Le bilan distingue acceptés par WordPress,
échecs, ignorés, incertains et annulés. L’acceptation ne garantit pas la réception.
Les dix dernières campagnes par spectacle et auteur sont conservées. Les envois
confirmés dans la fenêtre ne sont pas planifiés en arrière-plan. La publication
WordPress garde son déclenchement automatique distinct ; un état sauvegardé avant
transmission évite aussi de renvoyer automatiquement un email interrompu. Les réponses publiques sont limitées à leur lien ;
elles n’autorisent aucune autre opération sur le compte ou sur le spectacle.

## Vérifications locales

Sur une installation `.local`, avec tous les emails interceptés et les données
temporaires nettoyées par les tests :

```sh
wp eval-file wp-content/plugins/sdr-coulisses/tests/event-announcements.php
wp eval-file wp-content/plugins/sdr-coulisses/tests/event-reminders.php
wp eval-file wp-content/plugins/sdr-coulisses/tests/relances.php
wp eval-file wp-content/plugins/sdr-coulisses/tests/stage-bulk.php
```

Les contrôles couvrent consentement, permissions, sélection, lots et reprises,
réponses avant envoi, liens falsifiés ou expirés, rôles, indisponibilités, rejeux,
fermetures, réponses et sélections existantes, et maintien des réponses habituelles.
