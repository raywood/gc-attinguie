# Tests backend

Depuis `backend/`, avec les dépendances de développement installées :

```bash
vendor/bin/phpunit --testsuite unit
```

PHPUnit 12 nécessite PHP >= 8.3 ; le projet utilise PHP >= 8.4.
La version exacte et les dépendances sont verrouillées dans composer.lock.

La suite unitaire charge uniquement l'autoloader Composer : aucun noyau Symfony,
aucun fichier .env, aucune connexion PostgreSQL ou Redis. Le bootstrap force
APP_ENV=test, un fuseau Africa/Abidjan et des paramètres de connexion isolés,
même si des variables sont héritées du shell. DATABASE_URL utilise un schéma
volontairement non pris en charge ; Messenger reçoit un DSN en mémoire.
Cela ne constitue pas une sandbox réseau : les tests doivent rester sans I/O.

Ne pas démarrer le noyau depuis cette suite. De futurs tests d'intégration
nécessiteront une configuration et une infrastructure isolées distinctes.
BrowserKit, le bridge PHPUnit Symfony et test-pack ne sont pas nécessaires
aux tests unitaires actuels et ne sont pas ajoutés.

Les scripts autonomes existants sont conservés. Depuis la racine du dépôt :

```bash
php backend/tests/standalone/billing-period-catalog.php
php backend/tests/standalone/app-branding.php
node --test frontend/tests/*.test.mjs
```
