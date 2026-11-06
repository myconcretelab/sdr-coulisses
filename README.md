# Coulisse — Gestion de troupe

Extension WordPress pour gérer les spectacles, les disponibilités des membres,
les équipes, les lieux et les invitations ou relances par email.

Le thème du site public reste libre. L’espace membres dispose d’une interface dédiée,
avec le nom, le logo et la couleur d’accent de la troupe. Les données restent dans
le WordPress de chaque troupe. Aucun service central ni compte externe n’est requis.

## Installation

1. Installer le ZIP de distribution depuis Extensions → Ajouter une extension.
2. Activer **Coulisse — Gestion de troupe**.
3. Ouvrir **Réglages → Coulisse** et enregistrer le nom et l’identité de la troupe.
4. Cliquer sur **Créer la page des Coulisses**, ou choisir une page publiée contenant
   le bloc **Coulisses de la troupe**. L’accueil et le menu du site ne sont pas modifiés.
5. Configurer les comptes **Membre de la troupe** et **Organisation**, puis ouvrir
   les Coulisses. Les administrateurs ont aussi accès à l’espace.
6. Dans **Modèles d’emails**, tester l’envoi vers son propre compte avant de contacter
   les membres. La réception dépend du service d’email configuré sur le WordPress.

La documentation détaillée se trouve en haut de la navigation des Coulisses.
L’agenda, le trombinoscope, les informations pratiques et les spectacles liés
s’insèrent dans les pages publiques avec les blocs Gutenberg du plugin.

## Version de lancement

Cette version prépare la diffusion auprès de troupes pilotes. Installation via ZIP
et mises à jour manuelles : aucune connexion à un service de mise à jour externe
n’est encore configurée. Le code source est sur
https://github.com/myconcretelab/sdr-coulisses.

La désactivation conserve les spectacles, profils et réponses. Il n’y a pas de
suppression automatique des données. Une sauvegarde WordPress reste nécessaire
avant une mise à jour ou une migration.

## Construire un ZIP

PHP 8.1+, Python 3 et une documentation relue sont nécessaires :

```sh
python3 tools/build-release.py --output /chemin/vers/les-versions
```

Chaque push vérifié sur GitHub construit aussi le ZIP et son empreinte dans l’artefact « coulisse-plugin ».

L’archive possède une seule entrée de plugin. Le point d’entrée historique
`sdr-relances.php` est exclu du ZIP ; les relances sont intégrées dans Coulisse.
Sur les installations existantes où il est activé, ce point d’entrée reste compatible
et son démarrage est protégé contre les doublons.

Les identifiants historiques `sdr_` et les anciens noms de blocs sont conservés pour
préserver les contenus et les données existantes. Les photos, logos, illustrations
et styles propres aux Sorties de Route ne font pas partie du ZIP.

## Licence et ressources

Le code de Coulisse est sous GNU GPL version 2 ou ultérieure, voir `LICENSE.txt`.
Les polices DM Sans et Space Grotesk conservent leur licence SIL OFL 1.1 et leurs
mentions d’origine dans `assets/fonts/`. Les ressources graphiques du thème SDR
restent dans le dépôt du thème.

## Maintenance

Voir `documentation/README.md` et `AGENTS.md` pour la relecture obligatoire des guides.
Les tests WordPress dans `tests/` sont réservés aux installations `.local` et
interceptent les emails. Ils ne doivent pas être exécutés en production.
