=== Gestion CRM EECIE ===
Contributors: timothee
Author: Timothée de Almeida
Author URI: https://alpai.eu
Plugin URI: https://alpai.eu/plugins/gestion-crm-eecie
Tags: crm, baserow, n8n, gestion, eecie, automatisation
Requires at least: 5.9
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Plugin de gestion CRM sur mesure pour eecie.ca, agissant comme un portail de travail intelligent connecté en temps réel à une base de données Baserow et à des processus d'automatisation n8n.

== Description ==

Gestion CRM EECIE transforme le site WordPress de eecie.ca en une interface de travail centralisée pour les employés. Il se connecte directement à une base de données Baserow (la source de vérité pour les données CRM) et à un moteur de workflows n8n (pour l'automatisation des processus métier complexes).

Le plugin fournit deux environnements distincts :

    Un Espace Employé en front-end, accessible via une page sécurisée, pour la gestion quotidienne des opportunités, tâches, devis, appels, contacts et fournisseurs.

    Une Zone d'Administration en back-end pour configurer le plugin et gérer les utilisateurs Baserow sans quitter WordPress.

L'architecture moderne du système permet de séparer l'interface (WordPress), les données (Baserow) et la logique métier (n8n), offrant une solution flexible, robuste et facile à maintenir.

Fonctionnalités Clés :

    Espace Employé (Front-End) :

        Tableau de bord unifié : Interface unique avec menu latéral pour naviguer entre les modules.

        Gestion des Opportunités, Tâches, Appels, Devis, Fournisseurs et Contacts assignés à l'utilisateur.

        Interface riche et interactive : Tableaux dynamiques (tri, filtre, édition en ligne), vues par cartes et popups intelligents pour visualiser et modifier les données sans changer de page.

        Intégration n8n : Des actions clés (accepter une tâche, calculer un devis) déclenchent des processus d'automatisation complexes en arrière-plan.

    Espace Administrateur (Back-End) :

        Configuration centralisée des accès à l'API Baserow et des mappages de tables.

        Gestion complète des utilisateurs Baserow (CRUD) directement depuis WordPress, avec un système de changement de mot de passe sécurisé et chiffré.

== Installation ==

    Téléversez le dossier timalpai-eecie dans le répertoire /wp-content/plugins/ de votre site.

    Activez l'extension depuis le menu "Extensions" dans l'administration WordPress.

    Configurez le plugin (étape obligatoire) :

        Rendez-vous dans le menu Gestion CRM EECIE > Configuration.

        Remplissez les champs d'accès à Baserow : URL de l’instance, Clé API, ID du Workspace, et ID de la Base de Données.

        Vérifiez l'association des tables. Pour une fiabilité maximale, il est fortement recommandé de copier-coller manuellement les IDs numériques de chaque table depuis Baserow dans les champs correspondants.

        Configurez les identifiants du compte de service Baserow et les taux de taxes.

        Cliquez sur Enregistrer les modifications.

== Screenshots ==

    Espace de travail de l'employé avec le menu de navigation.

    Vue "Flux de travail" avec les cartes de dossiers d'appels et leurs interactions.

    Modal d'édition d'un devis avec la sous-table des articles.

    Page d'administration pour la gestion des utilisateurs Baserow.

== Changelog ==

= 0.2.0 =

    Ajout : Module complet de gestion des Devis avec articles imbriqués et intégration n8n pour le calcul et la génération de PDF.

    Ajout : Module "Flux de Travail" (Appels) avec une vue moderne par cartes et gestion des Interactions associées.

    Ajout : Module de gestion des Fournisseurs avec une fonctionnalité de création simultanée du fournisseur et de son contact principal.

    Ajout : Page d'administration pour la gestion des utilisateurs Baserow (CRUD + changement de mot de passe chiffré).

    Amélioration : Le module "Tâches" utilise désormais une pagination et un chargement des données côté serveur pour de meilleures performances.

    Amélioration : Refonte du système de popups pour une navigation et une édition plus fluides entre les enregistrements liés.

    Sécurité : Implémentation du chiffrement pour les données sensibles (mots de passe) échangées avec Baserow et n8n.

= 0.1.0 =

    Version initiale : structure du plugin, configuration de base, modules d'opportunités et de tâches avec filtrage côté client.

== Upgrade Notice ==

= 0.2.0 =
Cette mise à jour majeure introduit de nombreux modules (Devis, Appels, Fournisseurs) et une nouvelle interface par cartes. Elle nécessite de vérifier la configuration de tous les IDs de table dans la page d'administration du plugin pour garantir le bon fonctionnement.

== License ==

Ce plugin est distribué sous la licence GPL v2 ou supérieure.