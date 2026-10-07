<?php

return [
    'fflogs_auth_title' => 'FF-Logs-Authentifizierung fehlgeschlagen',
    'fflogs_auth_message' => 'FullParty konnte sich auch nach der automatischen Token-Erneuerung nicht bei FF Logs authentifizieren. Prüfe die konfigurierten FF-Logs-Zugangsdaten und Admin-Panel → FF-Logs-Playground.',
    'fflogs_connection_title' => 'Verbindung zu FF Logs fehlgeschlagen',
    'fflogs_connection_message' => 'FullParty konnte keine Verbindung zu FF Logs herstellen. Fortschrittsabfragen sind möglicherweise nicht verfügbar. Prüfe die Netzwerkverbindung und den Dienststatus von FF Logs.',
    'fflogs_unavailable_title' => 'FF-Logs-API nicht verfügbar',
    'fflogs_unavailable_message' => 'FF Logs hat HTTP :status zurückgegeben. Fortschrittsabfragen sind möglicherweise nicht verfügbar. Details findest du unter Admin-Panel → FF-Logs-Playground.',
    'fflogs_quota_title' => 'FF-Logs-API-Kontingent fast aufgebraucht',
    'fflogs_quota_message' => 'FullParty hat in dieser Stunde :spent von :limit API-Punkten verbraucht. Das Kontingent wird in :seconds Sekunden zurückgesetzt. Prüfe die API-Nutzung, bevor Anfragen begrenzt werden.',
    'fflogs_health_title' => 'FF-Logs-Zustandsprüfung fehlgeschlagen',
    'fflogs_health_message' => 'Die geplante FF-Logs-Prüfung hat keine gültigen API-Nutzungsdaten zurückgegeben. Prüfe Admin-Panel → FF-Logs-Playground.',
    'integration_delivery_title' => 'Integrationsereignis konnte nicht zugestellt werden',
    'integration_delivery_message' => 'Die Integration :client konnte :event nicht empfangen. Details findest du unter Admin-Panel → Integrationen.',
    'integration_health_title' => 'Integrationszustand erfordert Aufmerksamkeit',
    'integration_health_message' => 'Die Integration :client hat den Status :status gemeldet. Details findest du unter Admin-Panel → Integrationen.',
    'test_title' => 'FullParty-Testmeldung für Administratoren',
    'test_message' => 'Dies ist ein Test des Ereignisses discord.admin.report von FullParty. Diese Nachricht wurde durch keinen tatsächlichen Vorfall ausgelöst.',
    'test_disabled' => 'Administratormeldungen sind deaktiviert. Setze DISCORD_ADMIN_REPORTS_ENABLED=true, um diesen Test zu aktivieren.',
    'test_failed' => 'Die Testmeldung konnte nicht zugestellt werden. Prüfe den aktiven Bot, seine Berechtigung discord.admin.report und die Zustellungsdiagnose der Integration.',
    'test_sent' => 'Der Bot hat die Testmeldung angenommen. Prüfe die Discord-Direktnachrichten der Website-Administratoren.',
    'test_queued' => 'Die Testmeldung wurde eingereiht. Zur Zustellung muss ein Queue-Worker laufen.',
];
