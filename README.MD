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

== License ==

Ce plugin est distribué sous la licence GPL v2 ou supérieure.
