=== Gestion CRM EECIE ===
Contributors: timothee
Author: Timothée de Almeida
Author URI: https://alpai.eu
Plugin URI: https://alpai.eu/plugins/gestion-crm-eecie
Tags: crm, baserow, n8n, gestion, eecie, automatisation, pwa, portail
Requires at least: 5.9
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Plugin de gestion CRM sur mesure pour eecie.ca, agissant comme un portail de travail intelligent connecté en temps réel à une base de données Baserow et à des processus d'automatisation n8n.

== Description ==

Gestion CRM EECIE transforme le site WordPress de eecie.ca en une plateforme de travail complète pour les employés et les prestataires externes. Il se connecte directement à une base de données Baserow (la source de vérité pour les données CRM) et à un moteur de workflows n8n (pour l'automatisation des processus métier complexes), couvrant l'intégralité du cycle de vie d'un projet, de l'opportunité initiale à la livraison finale.

Le plugin fournit trois environnements distincts :

    Un Espace Employé en front-end, accessible via une page sécurisée, pour la gestion quotidienne des opportunités, tâches, devis, appels, contacts et fournisseurs.

    Un Portail Prestataire sous forme d'application web progressive (PWA), permettant aux techniciens sur le terrain de consulter leurs missions et de soumettre des rapports d'intervention dématérialisés, incluant des notes, des articles additionnels et une signature client numérisée.

    Une Zone d'Administration en back-end pour configurer le plugin, gérer les utilisateurs Baserow et effectuer des opérations de sauvegarde sans quitter WordPress.

L'architecture moderne du système permet de séparer l'interface (WordPress), les données (Baserow) et la logique métier (n8n), offrant une solution flexible, robuste et facile à maintenir.

Fonctionnalités Clés :

    Espace Employé (Front-End) :

        Tableau de bord unifié : Interface unique avec menu latéral pour naviguer entre les modules.

        Gestion des Opportunités, Tâches, Appels, Devis, Fournisseurs et Contacts assignés à l'utilisateur.

        Interface riche et interactive : Tableaux dynamiques, vues par cartes et popups intelligents pour visualiser et modifier les données.

        Intégration n8n : Des actions clés déclenchent des processus d'automatisation complexes en arrière-plan.

    Espace Administrateur (Back-End) :

        Configuration centralisée des accès à l'API Baserow et des mappages de tables.

        Gestion complète des utilisateurs Baserow (CRUD) directement depuis WordPress, avec un système de changement de mot de passe sécurisé et chiffré.

        Module de Sauvegarde et Restauration pour exporter les données de Baserow.

    Portail et Automatisation pour Prestataires Externes :

        Planification d'intervention : Les prestataires reçoivent un lien sécurisé unique par email pour soumettre la date et l'heure d'une intervention, déclenchant des notifications de confirmation automatiques pour le client et le prestataire.

        Rapport d'intervention dématérialisé (PWA) : Une application web mobile permet aux prestataires de créer des rapports de livraison, d'ajouter des services/matériaux supplémentaires, de rédiger des notes et de capturer la signature numérique du client directement sur site.

== Installation ==

    Téléversez le dossier timalpai-eecie dans le répertoire /wp-content/plugins/ de votre site.

    Activez l'extension depuis le menu "Extensions" dans l'administration WordPress.

    Configurez le plugin (étape obligatoire) :

        Rendez-vous dans le menu Gestion CRM EECIE > Configuration.

        Remplissez les champs d'accès à Baserow : URL de l’instance, Clé API, ID du Workspace, et ID de la Base de Données.

        Vérifiez l'association des tables. Pour une fiabilité maximale, il est fortement recommandé de copier-coller manuellement les IDs numériques de chaque table depuis Baserow dans les champs correspondants.

        Configurez les identifiants du compte de service Baserow et les taux de taxes.

        Cliquez sur Enregistrer les modifications.

    (Optionnel) Pour la communication sécurisée avec n8n : Créez un fichier gce-secrets.php à la racine du plugin en vous basant sur gce-secrets-example.php et remplissez les clés partagées.

== Screenshots ==

    Espace de travail de l'employé avec le menu de navigation.

    Vue "Flux de travail" avec les cartes de dossiers d'appels et leurs interactions.

    Modal d'édition d'un devis avec la sous-table des articles.

    Page d'administration pour la gestion des utilisateurs Baserow.

    Interface du portail prestataire (PWA) pour la soumission d'un rapport de livraison avec signature.

    Formulaire de planification d'intervention accessible via un lien sécurisé.

== Changelog ==

= 0.3.0 =

    Ajout : Portail Prestataire (PWA) pour les Rapports d'Intervention. Une application web progressive (PWA) mobile a été créée pour les prestataires. Elle permet de :

        Consulter les détails d'une mission de réalisation.

        Rédiger des notes d'intervention.

        Ajouter des articles ou services supplémentaires.

        Capturer la signature numérique du client sur un canevas.

        Soumettre le rapport de livraison complet, qui est automatiquement enregistré dans Baserow.

    Ajout : Système de Planification d'Intervention pour les Prestataires.

        Un workflow n8n envoie automatiquement un email au prestataire assigné avec un lien sécurisé et unique.

        Ce lien mène à un formulaire public sur le site WordPress ([gce_schedule_form]) où le prestataire peut entrer la date et l'heure du RDV.

        Une fois soumis, un autre workflow envoie des emails de confirmation au client et au prestataire.

    Ajout : Module de Sauvegarde et Restauration. Une nouvelle page dans l'administration WordPress permet de lancer des tâches d'exportation des données depuis Baserow, gérées via une table wp_gce_jobs personnalisée.

    Amélioration : Automatisation du Cycle de Vie et des Rappels.

        Nouveaux workflows n8n pour créer automatiquement des tâches à partir des rappels en attente.

        Un système de surveillance quotidien vérifie les opportunités en état d'erreur (ex: email manquant) et notifie les utilisateurs si une tâche est en retard.

        Le processus d'envoi des devis peut maintenant être déclenché par un workflow journalier pour les devis en attente de confirmation.

    Amélioration : Sécurité et Gestion des Utilisateurs. La page de gestion des utilisateurs Baserow permet désormais aux administrateurs de changer le mot de passe d'un utilisateur de manière sécurisée grâce à un chiffrement des données échangées avec n8n.

    Amélioration : Clôture de Dossier. Ajout d'une fonctionnalité dans la PWA pour permettre au prestataire de déclencher la clôture finale d'un dossier, changeant son statut à "Finaliser".

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

= 0.3.0 =
Cette mise à jour majeure introduit le portail prestataire (PWA) et le système de planification d'interventions. Elle ajoute de nouvelles tables Baserow (Rendez_vous_fournisseur, Rapports_Livraison, Articles_Livraison, Signatures_Livraison). Il est essentiel de vous rendre sur la page de Configuration du plugin pour vérifier que les IDs de ces nouvelles tables sont correctement mappés afin d'assurer le bon fonctionnement des nouvelles fonctionnalités.

== License ==

Ce plugin est distribué sous la licence GPL v2 ou supérieure.