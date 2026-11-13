# GC-ATTINGUIÉ — Instructions aux agents

## Périmètre et autorisation

- Respecter strictement le périmètre demandé.
- Une analyse en lecture seule n'autorise aucune modification,
  installation, génération, migration ou écriture en base.
- Lire les instructions applicables et vérifier `git status --short`
  avant toute intervention. Préserver les changements existants.
- Ne jamais afficher de secrets ni de données personnelles.
- Demander un accord explicite avant une opération destructive,
  une migration sur une base partagée, un traitement financier réel
  ou un déploiement.

## Structure du projet

- `backend/` : API Symfony 8.1, PHP >= 8.4 déclaré, Doctrine ORM 3.7.
- `frontend/` : Vue 3, Vue Router 4, Vite 5 et composants Vue en JavaScript.
- `backend/migrations/` : migrations PostgreSQL.
- `docker/php/` : image PHP-FPM et extensions.
- `deploy/` : configurations Nginx et HTTPS local.
- `compose.yaml` à la racine : pile applicative principale.
- `backend-base/` : squelette distinct, hors pile principale.
  Ne pas y recopier les modifications de backend sans demande.
- Lire également les AGENTS.md des sous-dossiers concernés.

## Infrastructure

- Services principaux : php, worker, nginx, db et redis.
- PostgreSQL 16 stocke les données.
- Redis 7 transporte les messages Symfony Messenger.
- Le cache applicatif Symfony reste configuré sur fichiers.
- Nginx sert frontend/dist et transmet /api/ à PHP-FPM.
- Exécuter Compose depuis la racine en identifiant les surcharges utilisées.
- Ne pas confondre la pile racine avec les fichiers Compose de backend/.
- Le proxy Vite cible localhost:8000 ; vérifier le serveur utilisé
  avant de changer cette configuration.
- Ne jamais supprimer les volumes de données sans accord explicite.

## Backend

- Respecter les routes et mappings Doctrine par attributs,
  l'autowiring et l'autoconfiguration existants.
- Garder les contrôleurs centrés sur HTTP et les règles métiers
  dans les services.
- Utiliser les messages et handlers existants pour les traitements
  asynchrones concernés.
- Préserver les contrats JSON utilisés par le frontend.
- Vérifier composer.json et composer.lock avant de proposer
  une dépendance ou une commande.
- Ne pas installer automatiquement un outil absent.
- Ajouter une migration pour une évolution du schéma.
  Ne pas réécrire une migration déjà appliquée.
- Examiner le SQL, les contraintes et le schema_filter Doctrine.
- Ne pas utiliser doctrine:schema:update sur une base partagée.

## Frontend

- Réutiliser les composants Gc*, services, styles et tokens existants.
- Respecter les conventions des vues et les états réactifs partagés.
- Préserver les états de chargement, erreurs, listes vides
  et l'accessibilité.
- Conserver les libellés français et les utilitaires de formatage.
- L'authentification utilise une session et des cookies de même origine.
- Les gardes Vue contrôlent l'interface ; Symfony doit vérifier
  les autorisations pour chaque opération protégée.

## Conventions graphiques

- Respecter la charte ivoirienne : orange, blanc et vert.
- Utiliser l'orange pour les boutons d'action.
- Afficher les toasts sur fond vert, avec le texte et la barre blancs.
- Utiliser Vue.js et Bootstrap pour l'interface, en réutilisant les
  composants et styles existants. Vérifier l'intégration de Bootstrap
  avant son utilisation ; ne pas installer automatiquement de dépendance.
- Maintenir une identité visuelle cohérente entre l'application,
  les factures PDF et les e-mails : couleurs, logo, typographie et libellés.
- Respecter la personnalisation de la collectivité et les contraintes
  de rendu propres aux PDF et aux clients de messagerie.
- Préserver la lisibilité, les contrastes et les états de focus.

## Règles métiers sensibles

- Préserver les calculs monétaires décimaux et les contrôles de solde.
  Ne pas remplacer les calculs financiers par des flottants.
- Préserver les transactions, verrouillages, contraintes d'unicité
  et protections contre les doublons.
- Préserver les données figées des reçus, signatures,
  jetons de vérification et historiques d'audit.
- Respecter les rôles, permissions, accès des gérants
  et protections CSRF des opérations concernées.
- Utiliser le fuseau métier Africa/Abidjan.
- BillingPeriodCatalog définit les périodicités et leurs capacités :
  daily = daily_record ; monthly = monthly_invoice ;
  weekly et yearly = catalog_only.
- Ne pas activer implicitement une nouvelle périodicité de facturation.
- Les traitements Messenger doivent supporter les reprises
  sans créer de doublons.

## Vérification

Tests existants à exécuter depuis la racine :

    node --test frontend/tests/*.test.mjs
    php backend/tests/standalone/billing-period-catalog.php
    php backend/tests/standalone/app-branding.php

Le test PHP de personnalisation nécessite backend/vendor/autoload.php.

- Aucun script npm test ni configuration PHPUnit n'est actuellement déclaré.
- Plusieurs tests Node inspectent les sources ; signaler cette limite.
- Pour une modification autorisée, choisir les vérifications pertinentes :
  syntaxe PHP, tests concernés et build frontend si nécessaire.
- Le build frontend s'exécute avec npm run build depuis frontend/.
- Le build écrit dans dist ; les commandes Symfony peuvent écrire dans var.
  Ne pas les lancer pendant une analyse strictement en lecture seule.
- Ne jamais utiliser les données métier réelles pour les tests.
- Pour une correction métier, privilégier un test du comportement observable.

## Compte rendu

- Expliquer ce qui a changé, pourquoi et comment cela a été vérifié.
- Distinguer les constats du code des comportements vérifiés à l'exécution.
- Signaler les vérifications non exécutées et les limites restantes.
