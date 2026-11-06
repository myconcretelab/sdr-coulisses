# Documentation intégrée aux Coulisses

L’entrée **Documentation**, au-dessus de la navigation habituelle, charge les guides
depuis `GET /sdr/v1/documentation` avec les mêmes permissions que les Coulisses.
La recherche ignore la casse et les accents. Chaque guide a un domaine, des étapes,
des remarques, un niveau d’accès et un lien direct partageable entre membres.

## Une source de contenu

Les procédures sont dans `guides.json`, versionnées et déployées avec le plugin.
Les guides ayant `reference: sdr_event` ou `sdr_venue` reçoivent la liste des champs
directement depuis le registre utilisé par les fiches. L’ajout ou le renommage d’un
champ est donc visible automatiquement, sans recopier cette liste.

Les explications d’une nouvelle fonctionnalité doivent être rédigées ou relues.
Le code seul ne peut pas garantir qu’une procédure en français est correcte.
Les consignes du plugin imposent cette relecture à chaque modification du code.

## Contrôle des évolutions

`php tools/check-documentation.php --check` compare les empreintes des sources PHP,
JavaScript, CSS et des déclarations de blocs à `review.json`. Les nouveaux fichiers,
les suppressions et les modifications sont détectés. La structure des guides et
l’unicité de leurs identifiants sont aussi vérifiées. Tests et dépendances externes
sont exclus des empreintes.

Après modification et vérification des guides concernés, lancer :

```sh
php tools/check-documentation.php --review
php tools/check-documentation.php --check
```

La première commande enregistre la relecture et sa date. Elle ne réécrit aucune
procédure. Commiter les fichiers de documentation avec le code.

Le contrôle GitHub s’exécute à chaque push et pull request. Le hook local vérifie
les commits réellement poussés, dans une copie temporaire, et refuse un push dont
le code n’a pas été relu avec les guides. Pour activer ce hook sur un nouveau clone :

```sh
git config --local core.hooksPath .githooks
```

Les modifications du thème qui changent un parcours utilisateur doivent aussi
être reportées dans les guides. Le contrôle d’empreintes couvre le dépôt du plugin,
pas les autres dépôts. Une règle de protection GitHub peut rendre le contrôle
obligatoire pour les contributeurs qui n’utilisent pas le hook local.
