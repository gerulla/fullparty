<?php

return [
    'fflogs_auth_title' => 'FF Logs authentication failed',
    'fflogs_auth_message' => 'FullParty could not authenticate with FF Logs after automatic token recovery. Check the configured FF Logs credentials and Admin Panel → FF Logs Playground.',
    'fflogs_connection_title' => 'FF Logs connection failed',
    'fflogs_connection_message' => 'FullParty could not connect to FF Logs. Progress checks may be unavailable. Check network connectivity and the FF Logs service status.',
    'fflogs_unavailable_title' => 'FF Logs API unavailable',
    'fflogs_unavailable_message' => 'FF Logs returned HTTP :status. Progress checks may be unavailable. Check Admin Panel → FF Logs Playground for details.',
    'fflogs_quota_title' => 'FF Logs API budget almost exhausted',
    'fflogs_quota_message' => 'FullParty has used :spent of :limit API points this hour. The allowance resets in :seconds seconds. Check API usage before requests are rate limited.',
    'fflogs_health_title' => 'FF Logs health check failed',
    'fflogs_health_message' => 'The scheduled FF Logs check did not return valid API usage data. Check Admin Panel → FF Logs Playground.',
    'integration_delivery_title' => 'Integration event delivery failed',
    'integration_delivery_message' => 'The :client integration could not receive :event. Check Admin Panel → Integrations for details.',
    'integration_health_title' => 'Integration health check needs attention',
    'integration_health_message' => 'The :client integration reported status :status. Check Admin Panel → Integrations for details.',
    'test_title' => 'FullParty admin report test',
    'test_message' => 'This is a test of the discord.admin.report event from FullParty. No real incident triggered this message.',
    'test_disabled' => 'Admin reports are disabled. Set DISCORD_ADMIN_REPORTS_ENABLED=true to enable this test.',
    'test_failed' => 'The test report could not be delivered. Check the active bot, its discord.admin.report capability, and integration delivery diagnostics.',
    'test_sent' => 'The bot accepted the test admin report. Check the website admins\' Discord DMs.',
    'test_queued' => 'Test admin report queued. A queue worker must be running to deliver it.',
];
