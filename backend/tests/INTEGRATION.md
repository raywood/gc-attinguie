# Socle d'intégration isolé — préparation uniquement

Le profil migration ponctuel ajouté en mission 10B est documenté dans
[MIGRATIONS.md](MIGRATIONS.md). Le rôle dédié n'est pas provisionné
automatiquement ; les commandes prepare/reset restent désactivées.

## État et limites

Ce socle ne démarre aucun service, ne migre aucune base et ne contient pas encore
de suite HTTP. Le runner exécute par défaut les tests unitaires sans connexion.
PHPUnit et le bootstrap unitaires existants restent inchangés.
BrowserKit et les tests API feront l'objet d'une étape ultérieure autorisée.

La pile `compose.test.yaml` est autonome : toujours sélectionner ce seul fichier
et le projet `gc-attinguie-test`. Ne jamais le fusionner avec les Compose de
développement. Le réseau est interne, aucun port n'est publié, aucun volume
externe ni socket Docker n'est monté. Le volume PostgreSQL est distinct et porte
le préfixe du projet Compose. Les montages sélectifs excluent les fichiers .env
réels ; les identifiants littéraux sont exclusivement destinés à cette pile.
Ne jamais ajouter de secrets réels dans la configuration montée.

Le runner réutilise la recette d'image PHP 8.4 existante, sans la modifier.
L'image doit être construite lors d'une étape autorisée distincte. Les dépendances
de développement déjà présentes sont montées en lecture seule : aucune
installation n'est exécutée au démarrage. Cache, sessions, logs et fichiers
temporaires sont éphémères dans les tmpfs propres au runner.

## Configuration sans connexion

`tests/bootstrap.integration.php` exige les variables de processus explicitement
fournies par la pile. Il ne charge ni Dotenv ni le Runtime Symfony et ne démarre
pas le noyau. Il refuse les valeurs contradictoires dans les superglobales PHP.
Une future configuration PHPUnit d'intégration devra sélectionner ce bootstrap,
sans réutiliser `tests/bootstrap.php`, réservé aux tests unitaires.

`TestEnvironmentGuard::assertSafe()` ne fait aucun accès réseau : liste blanche
exacte pour APP_ENV, URL PostgreSQL, transport Messenger et TEST_TOKEN.
La comparaison intégrale de l'URL interdit les substitutions de nom, utilisateur,
hôte, port et les paramètres de connexion additionnels. Les erreurs ne révèlent
pas l'URL ni le mot de passe. Le parallélisme est volontairement refusé.

`config/packages/test/doctrine.yaml` fixe aussi l'URL dédiée, indépendamment
d'une DATABASE_URL héritée, et remplace dbname_suffix par une chaîne vide :
la base effective doit être exactement `gc_attinguie_test`, jamais `_test_test`.
Ce fichier est chargé après les configurations générales par le noyau Symfony.
Les transports async et failed sont explicitement en mémoire en environnement
test. Les options Redis héritées ne sont pas utilisées par la factory mémoire
installée ; aucun worker Redis n'est prévu dans cette première pile.

Un nom DNS autorisé n'est pas une preuve d'identité du serveur. Le garde-fou
n'est ni une sandbox réseau ni une autorisation de migration. Utiliser uniquement
le réseau interne de cette pile ; ne pas exécuter les futures intégrations sur
l'hôte ou avec une configuration Compose surchargée.

## Barrières obligatoires avant toute écriture future

1. Revoir la configuration Compose résolue : réseau interne unique, aucun réseau
   externe, aucune publication de ports, volumes distincts et aucun secret réel.
2. Vérifier la configuration avec le garde-fou avant de créer une connexion.
   Appliquer aussi ce contrôle dans le futur lanceur de migration/nettoyage :
   le bootstrap PHPUnit ne protège pas une commande console indépendante.
3. Depuis le runner isolé uniquement, vérifier en lecture seule la connexion
   Doctrine effective : hôte, port, utilisateur et base après traitement du DSN
   et du suffixe. Refuser toute différence avec les valeurs autorisées.
4. Vérifier `current_database() = 'gc_attinguie_test'`,
   `current_user = 'gc_test_runner'` et un `server_version_num` de PostgreSQL 16.
   Refuser tout rôle superutilisateur, CREATEDB, CREATEROLE ou REPLICATION.
5. Vérifier la ligne id=1 de `isolation_meta.identity` :
   `gc-attinguie-isolated-test-v1`. Elle est créée par le script d'initialisation,
   hors du schéma applicatif et n'est pas modifiable par le runner.
6. Arrêter si le marqueur manque, diffère, ou si un volume déjà initialisé ne
   correspond pas aux attentes. Les scripts init PostgreSQL ne sont exécutés
   qu'à l'initialisation d'un volume vide : ne pas contourner un échec par une
   suppression automatique du volume.
7. Seulement après ces contrôles et autorisation explicite, appliquer les
   migrations réelles. Ne pas utiliser schema:update, des migrations descendantes
   ou une purge générique comme mécanisme de remise à zéro.

Le compte runner est non superutilisateur, sans création de bases ou rôles.
Il dispose de droits DDL dans public pour les migrations futures ; un compte de
migration distinct et un compte API moins privilégié restent à prévoir avant
l'élargissement des tests. Ne pas injecter le compte admin dans le runner.

Les migrations initialisent des références, créent des triggers d'immutabilité
des reçus et une table d'audit exclue du schema_filter. Les futures fixtures et
remises à zéro doivent en tenir compte sans désactiver les protections métier.
Redis de test, les tests concurrents et la preuve d'isolation des nettoyages
restent hors de ce premier socle.

## Validations autorisées sans services

Depuis la racine, la variable COMPOSE_DISABLE_ENV_FILE évite le chargement
implicite du .env racine ; --env-file sélectionne explicitement un fichier vide.

```bash
COMPOSE_DISABLE_ENV_FILE=1 docker compose --env-file /dev/null -p gc-attinguie-test -f compose.test.yaml config --quiet
php -l backend/tests/Support/TestEnvironmentGuard.php
php -l backend/tests/bootstrap.integration.php
php -l backend/tests/Unit/Support/TestEnvironmentGuardTest.php
backend/vendor/bin/phpunit -c backend/phpunit.xml.dist
git diff --check
git status --short
```

Ne pas lancer up, run, exec, migrations, purges ou down --volumes dans le cadre
de cette préparation. La disponibilité réelle de PostgreSQL, ses permissions,
le marqueur d'identité et le démarrage du noyau ne sont pas encore validés.
