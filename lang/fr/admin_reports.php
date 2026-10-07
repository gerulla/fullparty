<?php

return [
    'fflogs_auth_title' => 'Échec de l’authentification auprès de FF Logs',
    'fflogs_auth_message' => 'FullParty n’a pas pu s’authentifier auprès de FF Logs après le renouvellement automatique du jeton. Vérifiez les identifiants FF Logs configurés et le panneau d’administration → FF Logs Playground.',
    'fflogs_connection_title' => 'Échec de la connexion à FF Logs',
    'fflogs_connection_message' => 'FullParty n’a pas pu se connecter à FF Logs. Les vérifications de progression peuvent être indisponibles. Vérifiez la connexion réseau et l’état du service FF Logs.',
    'fflogs_unavailable_title' => 'API FF Logs indisponible',
    'fflogs_unavailable_message' => 'FF Logs a renvoyé le code HTTP :status. Les vérifications de progression peuvent être indisponibles. Consultez le panneau d’administration → FF Logs Playground.',
    'fflogs_quota_title' => 'Quota de l’API FF Logs presque épuisé',
    'fflogs_quota_message' => 'FullParty a utilisé :spent points d’API sur :limit cette heure-ci. Le quota sera réinitialisé dans :seconds secondes. Vérifiez l’utilisation de l’API avant que les requêtes soient limitées.',
    'fflogs_health_title' => 'Échec du contrôle de disponibilité de FF Logs',
    'fflogs_health_message' => 'Le contrôle planifié de FF Logs n’a pas renvoyé de données valides sur l’utilisation de l’API. Consultez le panneau d’administration → FF Logs Playground.',
    'integration_delivery_title' => 'Échec de l’envoi d’un événement d’intégration',
    'integration_delivery_message' => 'L’intégration :client n’a pas pu recevoir :event. Consultez le panneau d’administration → Intégrations pour plus de détails.',
    'integration_health_title' => 'Une intégration nécessite votre attention',
    'integration_health_message' => 'L’intégration :client a signalé l’état :status. Consultez le panneau d’administration → Intégrations pour plus de détails.',
    'test_title' => 'Test de rapport administrateur FullParty',
    'test_message' => 'Ceci est un test de l’événement discord.admin.report envoyé par FullParty. Aucun incident réel n’a déclenché ce message.',
    'test_disabled' => 'Les rapports administrateur sont désactivés. Définissez DISCORD_ADMIN_REPORTS_ENABLED=true pour activer ce test.',
    'test_failed' => 'Le rapport de test n’a pas pu être envoyé. Vérifiez le bot actif, sa capacité discord.admin.report et les diagnostics d’envoi de l’intégration.',
    'test_sent' => 'Le bot a accepté le rapport de test. Vérifiez les messages privés Discord des administrateurs du site.',
    'test_queued' => 'Rapport de test mis en file d’attente. Un worker de file d’attente doit fonctionner pour l’envoyer.',
];
