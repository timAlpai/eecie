# Changelog

Toutes les modifications notables de ce projet seront documentées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), et ce projet adhère à la [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [0.1-beta] - 2024-05-24

### Added

*   **Structure Initiale du Plugin** : Création de l'architecture de base du plugin WordPress avec les constantes, les hooks principaux et la séparation des logiques admin/public/api.
*   **Proxy API Baserow** : Mise en place d'un proxy sécurisé en PHP pour toutes les communications avec l'API Baserow, évitant d'exposer la clé API côté client.
*   **Endpoints REST personnalisés** : Création d'une API REST interne (`/wp-json/eecie-crm/v1/`) pour chaque table Baserow (`/opportunites`, `/taches`, `/contacts`, `/devis`, `/articles_devis`, `/appels`, `/interactions`, `/fournisseurs`, `/utilisateurs`, `/zone_geo`), incluant les routes `/schema` pour récupérer la structure des champs.
*   **Module de Configuration Admin** :
    *   Page pour configurer l'URL Baserow, la clé API et les taux de taxes (TPS/TVQ).
    *   Système de détection automatique des IDs de table avec possibilité de surcharge manuelle.
    *   Outil "Explorer la structure" pour visualiser le JSON brut de la base de données Baserow.
*   **Module de Gestion des Utilisateurs (Admin)** :
    *   Interface CRUD (Créer, Lire, Mettre à jour, Supprimer) complète pour la table des utilisateurs (`T1_user`) de Baserow, utilisant Tabulator.js.
*   **Dashboard Frontend pour Employés** :
    *   Création du shortcode `[gce_user_dashboard]` pour afficher l'espace de travail.
    *   Interface à onglets avec menu latéral pour la navigation.
    *   **Page Opportunités** : Affiche les opportunités assignées à l'utilisateur, avec un bouton "Accepter" pour changer le statut.
    *   **Page Tâches** : Affiche les tâches actives assignées, avec un bouton "Accepter" qui déclenche un webhook N8N (`/proxy/start-task`).
    *   **Page Devis** : Affiche les devis avec les articles en sous-tableau. Permet l'ajout/modification/suppression d'articles. Inclut un bouton "Calculer Devis" qui envoie les données enrichies à un webhook N8N pour la génération de PDF.
    *   **Page Appels** : Affiche les appels avec les interactions en sous-tableau, avec des options CRUD complètes sur les interactions.
    *   **Page Fournisseurs** : Interface CRUD complète pour les fournisseurs.
    *   **Page Contacts** : Affiche les contacts liés aux opportunités de l'utilisateur.
*   **Composants Partagés (Shared)** :
    *   `tabulator-columns.js` : Génère dynamiquement les colonnes Tabulator à partir du schéma Baserow, gérant les types de champs (select, date, booléen, lien, etc.).
    *   `tabulator-editors.js` : Fournit les éditeurs en ligne personnalisés pour Tabulator.
    *   `popup-handler.js` : Système de popup générique pour visualiser et éditer n'importe quelle ligne de n'importe quelle table.
    *   `popup-handler-fournisseur.js` : Popup spécialisé pour la création simultanée d'un fournisseur et de son contact principal.
*   **Personnalisation de l'interface** :
    *   Style personnalisé pour la page de connexion WordPress.
    *   Redirection des utilisateurs non-administrateurs vers le dashboard après connexion.

### Fixed

*   **Précision des Taxes** : Les taux de taxes sont maintenant sauvegardés en tant que chaînes de caractères dans la configuration pour préserver la précision décimale (ex: `0.09975`).
*   **Sauvegarde des champs `link_row` et `multiple_select`** : Correction de la logique dans `popup-handler-fournisseur.js` pour correctement récupérer les valeurs multiples des `select` avant de les envoyer à l'API.
*   **Fiabilité des popups** : Le `popup-handler.js` principal a été rendu plus robuste en utilisant une map de traduction (`rawNameToSlugMap`) pour faire correspondre de manière fiable le nom du champ de liaison au slug de l'API REST.
*   **Éditeur de texte riche** : Correction du chargement de l'éditeur WP Editor dans les popups en s'assurant que l'ID unique et le `textarea_name` sont correctement passés à l'API et à TinyMCE.