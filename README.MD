=== Gestion CRM pour eecie.ca ===
Contributors: timothee
Author: Timothée de Almeida
Author URI: https://alpai.eu
Plugin URI: https://alpai.eu/plugins/gestion-crm-eecie
Tags: crm, baserow, gestion, eecie, automatisation
Requires at least: 5.9
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Plugin de gestion CRM personnalisé pour eecie.ca, connecté directement à Baserow via son API REST.

== Description ==

Ce plugin permet au site WordPress de eecie.ca d'interagir directement avec les données de Baserow pour la gestion des opportunités, tâches, contacts, et interactions clients.

Fonctionnalités prévues :
- Visualisation des opportunités dans l'admin WordPress
- Création et édition de tâches liées
- Lecture directe via l’API Baserow (token requis)
- Appels sécurisés côté serveur, sans exposer la clé API

Le plugin est conçu pour s'intégrer de manière transparente dans l'interface WordPress (admin ou frontend).

== Installation ==

1. Téléversez le dossier `gestion-crm-eecie` dans `/wp-content/plugins/`
2. Activez le plugin via le menu "Extensions" de WordPress
3. Configurez la clé API Baserow et les IDs de table via la page de réglages

== Screenshots ==

1. Liste des opportunités depuis Baserow
2. Détail d'une tâche à effectuer
3. Formulaire d’ajout de contact

== Changelog ==

= 0.1.0 =
* Version initiale : structure du plugin, configuration de base, début du module d’opportunités.

== Upgrade Notice ==

= 0.1.0 =
Première version stable. Non compatible avec des versions de WordPress < 5.9.

# Gestion CRM EECIE - v0.1-beta

## Introduction

**Gestion CRM EECIE** est une extension WordPress conçue sur mesure pour les employés de **eecie.ca**. Elle transforme WordPress en une interface de travail connectée en temps réel à une base de données Baserow, permettant une gestion centralisée des clients, projets, tâches et fournisseurs.

Ce plugin a deux objectifs principaux :
1.  **Pour les employés (vendeurs, chargés de projet)** : Fournir un espace de travail simple et intégré directement sur le site web (`/mon-espace`) pour gérer leurs tâches, opportunités, devis, etc., sans avoir à se connecter à Baserow.
2.  **Pour les administrateurs** : Offrir des outils de configuration et de gestion des utilisateurs Baserow directement depuis l'administration de WordPress.

---

## Fonctionnalités Clés

### Espace Employé (Accessible via une page avec le shortcode `[gce_user_dashboard]`)

L'espace de travail principal pour les utilisateurs non-administrateurs.

*   **Tableau de bord unifié** : Une interface unique avec un menu latéral pour naviguer entre les différentes sections.
*   **Mes Opportunités** : Affiche uniquement les opportunités (`Task_input`) assignées à l'utilisateur connecté. Un bouton "Accepter" permet de changer le statut de l'opportunité de "Assigner" à "Traitement".
*   **Mes Tâches** : Affiche les tâches (`Taches`) actives assignées à l'utilisateur. Un bouton "Accepter" déclenche un processus externe (via n8n) pour passer la tâche "En cours".
*   **Gestion des Devis** :
    *   Visualisation des devis (`Devis`) avec les articles (`Articles_devis`) imbriqués.
    *   Ajout, modification et suppression des articles directement depuis la vue du devis.
    *   Bouton "Calculer Devis" qui déclenche un workflow n8n pour générer le document PDF, en enrichissant les données avec les informations du client lié.
*   **Gestion des Appels & Interactions** :
    *   Visualisation des appels (`Appels`) avec les interactions (`Interactions`) associées.
    *   Ajout, modification et suppression des interactions pour un appel donné.
*   **Gestion des Fournisseurs** :
    *   Tableau complet pour visualiser et modifier les fournisseurs (`Fournisseur`).
    *   Un bouton "Ajouter un Fournisseur" ouvre un popup spécial qui permet de créer simultanément le fournisseur et son contact principal, en les liant automatiquement.
*   **Mes Contacts** : Affiche une liste des contacts (`Contacts`) qui sont liés aux opportunités de l'utilisateur.
*   **Interface Interactive** :
    *   Toutes les listes sont des tableaux dynamiques (via Tabulator.js) avec tri et recherche.
    *   **Popups intelligents** : Cliquez sur un élément lié (un contact, une opportunité, etc.) pour ouvrir un popup affichant ses détails. Une icône "crayon" ✏️ à côté de ces liens permet de les modifier directement dans un popup.

### Administration WordPress (Pour les administrateurs)

*   **Page de Configuration** (`Gestion CRM EECIE > Configuration`) :
    *   Configuration de l'URL de l'instance Baserow et de la clé d'API.
    *   Détection automatique des tables Baserow nécessaires (`Contacts`, `Taches`, etc.).
    *   Possibilité de forcer manuellement les IDs des tables pour plus de stabilité.
    *   Configuration des taux de taxes (TPS et TVQ) utilisés dans le calcul des devis.
*   **Gestion des Utilisateurs Baserow** (`Gestion CRM EECIE > Gestion Utilisateurs`) :
    *   Un tableau complet pour visualiser, ajouter, modifier et supprimer des utilisateurs dans la table Baserow `T1_user` sans quitter WordPress.

---

## Installation

1.  Téléversez le dossier `gestion-crm-eecie` dans le répertoire `/wp-content/plugins/` de votre site WordPress.
2.  Activez l'extension depuis le menu "Extensions" dans l'administration WordPress.

---

## Configuration Initiale (Étape obligatoire)

Une fois le plugin activé, une configuration est nécessaire pour le connecter à Baserow.

1.  Rendez-vous dans le menu **Gestion CRM EECIE > Configuration**.
2.  **Remplissez les champs suivants :**
    *   **URL de l’instance Baserow** : L'adresse de votre serveur Baserow (ex: `https://baserow.eecie.ca`).
    *   **Clé API Baserow** : Votre clé d'API personnelle Baserow.
3.  **Vérifiez l'association des tables** :
    *   Le plugin essaie de trouver automatiquement les tables par leur nom (`Contacts`, `Taches`...).
    *   Vérifiez que les IDs détectés sont corrects. Si un ID est manquant ou incorrect, copiez-collez l'ID numérique de la table depuis Baserow dans le champ correspondant. Il est recommandé de remplir manuellement ces champs pour garantir le bon fonctionnement du plugin à long terme.
4.  **Vérifiez les taux de taxes** (TPS et TVQ) et ajustez-les si nécessaire.
5.  Cliquez sur **Enregistrer les modifications**.

---

## Utilisation

*   **Administrateurs** : Utilisez les menus sous "Gestion CRM EECIE" dans l'interface d'administration de WordPress.
*   **Employés** :
    1.  Connectez-vous à WordPress avec votre compte utilisateur.
    2.  Accédez à la page contenant le shortcode `[gce_user_dashboard]` (par exemple, `https://eecie.ca/mon-espace/`).
    3.  Utilisez le menu latéral pour naviguer entre les différentes sections de votre espace de travail.

---

## Dépendances Techniques

*   WordPress 5.9+
*   PHP 7.4+
*   Une instance Baserow fonctionnelle.
*   Bibliothèque JavaScript [Tabulator.js](https://tabulator.info/) pour les tableaux interactifs.
*   Bibliothèque JavaScript [Luxon.js](https://moment.github.io/luxon/) pour la gestion des dates.
