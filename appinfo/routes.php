<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * NachweisCheck routes. All JSON endpoints live under /api/v1/* so the
 * exception middleware can recognize them by path.
 */

return [
	'routes' => [
		// App shell
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// Projects
		['name' => 'projectApi#index', 'url' => '/api/v1/projects', 'verb' => 'GET'],
		['name' => 'projectApi#create', 'url' => '/api/v1/projects', 'verb' => 'POST'],
		['name' => 'projectApi#show', 'url' => '/api/v1/projects/{projectId}', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'projectApi#activate', 'url' => '/api/v1/projects/{projectId}/activate', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+']],
		['name' => 'projectApi#transition', 'url' => '/api/v1/projects/{projectId}/state/{target}', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+', 'target' => '[a-z]+']],
		['name' => 'projectApi#delete', 'url' => '/api/v1/projects/{projectId}', 'verb' => 'DELETE', 'requirements' => ['projectId' => '\d+']],

		// Dashboard / Soll-Ist
		['name' => 'dashboardApi#project', 'url' => '/api/v1/projects/{projectId}/dashboard', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],

		// Finanzierungsplan
		['name' => 'finplanApi#versions', 'url' => '/api/v1/projects/{projectId}/finplan/versions', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'finplanApi#positions', 'url' => '/api/v1/projects/{projectId}/finplan/versions/{versionId}/positions', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+', 'versionId' => '\d+']],
		['name' => 'finplanApi#newDraft', 'url' => '/api/v1/projects/{projectId}/finplan/draft', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+']],
		['name' => 'finplanApi#replacePositions', 'url' => '/api/v1/projects/{projectId}/finplan/versions/{versionId}/positions', 'verb' => 'PUT', 'requirements' => ['projectId' => '\d+', 'versionId' => '\d+']],
		['name' => 'finplanApi#activate', 'url' => '/api/v1/projects/{projectId}/finplan/versions/{versionId}/activate', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+', 'versionId' => '\d+']],

		// Arbeitspakete
		['name' => 'workPackageApi#index', 'url' => '/api/v1/projects/{projectId}/workpackages', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'workPackageApi#create', 'url' => '/api/v1/projects/{projectId}/workpackages', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+']],
		['name' => 'workPackageApi#setPositions', 'url' => '/api/v1/projects/{projectId}/workpackages/{apId}/positions', 'verb' => 'PUT', 'requirements' => ['projectId' => '\d+', 'apId' => '\d+']],
		['name' => 'workPackageApi#close', 'url' => '/api/v1/projects/{projectId}/workpackages/{apId}/close', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+', 'apId' => '\d+']],

		// Time entries
		['name' => 'timeEntryApi#index', 'url' => '/api/v1/projects/{projectId}/time-entries', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'timeEntryApi#create', 'url' => '/api/v1/projects/{projectId}/time-entries', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+']],
		['name' => 'timeEntryApi#update', 'url' => '/api/v1/projects/{projectId}/time-entries/{id}', 'verb' => 'PUT', 'requirements' => ['projectId' => '\d+', 'id' => '\d+']],
		['name' => 'timeEntryApi#delete', 'url' => '/api/v1/projects/{projectId}/time-entries/{id}', 'verb' => 'DELETE', 'requirements' => ['projectId' => '\d+', 'id' => '\d+']],

		// Receipts
		['name' => 'receiptApi#index', 'url' => '/api/v1/projects/{projectId}/receipts', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'receiptApi#create', 'url' => '/api/v1/projects/{projectId}/receipts', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+']],
		['name' => 'receiptApi#update', 'url' => '/api/v1/projects/{projectId}/receipts/{id}', 'verb' => 'PUT', 'requirements' => ['projectId' => '\\d+', 'id' => '\\d+']],
		['name' => 'receiptApi#replaceSplits', 'url' => '/api/v1/projects/{projectId}/receipts/{id}/splits', 'verb' => 'PUT', 'requirements' => ['projectId' => '\d+', 'id' => '\d+']],
		['name' => 'receiptApi#delete', 'url' => '/api/v1/projects/{projectId}/receipts/{id}', 'verb' => 'DELETE', 'requirements' => ['projectId' => '\d+', 'id' => '\d+']],
		['name' => 'receiptApi#parseEinvoice', 'url' => '/api/v1/projects/{projectId}/receipts/parse-einvoice', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+']],
		['name' => 'receiptApi#resolveFile', 'url' => '/api/v1/projects/{projectId}/files/resolve', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],

		// Deviations
		['name' => 'deviationApi#index', 'url' => '/api/v1/projects/{projectId}/deviations', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'deviationApi#create', 'url' => '/api/v1/projects/{projectId}/deviations', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+']],

		// Finance: rates, incoming payments, reallocations, carryovers
		['name' => 'financeApi#rates', 'url' => '/api/v1/projects/{projectId}/rates/{uid}', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'financeApi#addRate', 'url' => '/api/v1/projects/{projectId}/rates', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+']],
		['name' => 'financeApi#payments', 'url' => '/api/v1/projects/{projectId}/payments', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'financeApi#addPayment', 'url' => '/api/v1/projects/{projectId}/payments', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+']],
		['name' => 'financeApi#deletePayment', 'url' => '/api/v1/projects/{projectId}/payments/{id}', 'verb' => 'DELETE', 'requirements' => ['projectId' => '\d+', 'id' => '\d+']],
		['name' => 'financeApi#reallocations', 'url' => '/api/v1/projects/{projectId}/reallocations', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'financeApi#requestReallocation', 'url' => '/api/v1/projects/{projectId}/reallocations', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+']],
		['name' => 'financeApi#decideReallocation', 'url' => '/api/v1/projects/{projectId}/reallocations/{id}/decide', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+', 'id' => '\d+']],
		['name' => 'financeApi#carryovers', 'url' => '/api/v1/projects/{projectId}/carryovers', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'financeApi#addCarryover', 'url' => '/api/v1/projects/{projectId}/carryovers', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+']],
		['name' => 'financeApi#cofunders', 'url' => '/api/v1/projects/{projectId}/cofunders', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'financeApi#addCofunder', 'url' => '/api/v1/projects/{projectId}/cofunders', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+']],
		['name' => 'financeApi#deleteCofunder', 'url' => '/api/v1/projects/{projectId}/cofunders/{id}', 'verb' => 'DELETE', 'requirements' => ['projectId' => '\d+', 'id' => '\d+']],
		['name' => 'financeApi#drawdowns', 'url' => '/api/v1/projects/{projectId}/drawdowns', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'financeApi#addDrawdown', 'url' => '/api/v1/projects/{projectId}/drawdowns', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+']],
		['name' => 'financeApi#decideDrawdown', 'url' => '/api/v1/projects/{projectId}/drawdowns/{id}/decide', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+', 'id' => '\d+']],
		['name' => 'financeApi#forecast', 'url' => '/api/v1/projects/{projectId}/forecast', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'financeApi#claims', 'url' => '/api/v1/projects/{projectId}/claims', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'financeApi#addClaim', 'url' => '/api/v1/projects/{projectId}/claims', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+']],
		['name' => 'financeApi#settleClaim', 'url' => '/api/v1/projects/{projectId}/claims/{id}/settle', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+', 'id' => '\d+']],

		// Reports + Stundenzettel
		['name' => 'reportApi#reports', 'url' => '/api/v1/projects/{projectId}/workpackages/{apId}/reports', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+', 'apId' => '\d+']],
		['name' => 'reportApi#createReport', 'url' => '/api/v1/projects/{projectId}/reports', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+']],
		['name' => 'reportApi#transitionReport', 'url' => '/api/v1/projects/{projectId}/reports/{id}/state/{target}', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+', 'id' => '\d+', 'target' => '[a-z]+']],
		['name' => 'reportApi#timesheets', 'url' => '/api/v1/projects/{projectId}/timesheets', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'reportApi#generateTimesheet', 'url' => '/api/v1/projects/{projectId}/timesheets', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+']],
		['name' => 'reportApi#submitTimesheet', 'url' => '/api/v1/projects/{projectId}/timesheets/{id}/submit', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+', 'id' => '\d+']],
		['name' => 'reportApi#signTimesheet', 'url' => '/api/v1/projects/{projectId}/timesheets/{id}/sign', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+', 'id' => '\d+']],
		['name' => 'reportApi#rejectTimesheet', 'url' => '/api/v1/projects/{projectId}/timesheets/{id}/reject', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+', 'id' => '\d+']],

		// Nachweis package
		['name' => 'nachweisApi#index', 'url' => '/api/v1/projects/{projectId}/nachweise', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'nachweisApi#show', 'url' => '/api/v1/projects/{projectId}/nachweise/{id}', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+', 'id' => '\d+']],
		['name' => 'nachweisApi#generate', 'url' => '/api/v1/projects/{projectId}/nachweise', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+']],
		['name' => 'nachweisApi#preview', 'url' => '/api/v1/projects/{projectId}/nachweise/preview', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+']],
		['name' => 'nachweisApi#download', 'url' => '/api/v1/projects/{projectId}/nachweise/{id}/download/{section}', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+', 'id' => '\d+', 'section' => '[a-zA-Z0-9_.-]+']],
		['name' => 'nachweisApi#readiness', 'url' => '/api/v1/projects/{projectId}/readiness', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'nachweisApi#datev', 'url' => '/api/v1/projects/{projectId}/export/datev', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'nachweisApi#sachberichtSuggestions', 'url' => '/api/v1/projects/{projectId}/sachbericht-suggestions', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'projectApi#antrag', 'url' => '/api/v1/projects/{projectId}/antrag', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],

		// Admin: members, auditor grants, templates, access door, seats
		['name' => 'adminApi#members', 'url' => '/api/v1/projects/{projectId}/members', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'adminApi#addMember', 'url' => '/api/v1/projects/{projectId}/members', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+']],
		['name' => 'adminApi#updateMemberRole', 'url' => '/api/v1/projects/{projectId}/members/{uid}', 'verb' => 'PUT', 'requirements' => ['projectId' => '\d+']],
		['name' => 'adminApi#removeMember', 'url' => '/api/v1/projects/{projectId}/members/{uid}', 'verb' => 'DELETE', 'requirements' => ['projectId' => '\d+']],
		['name' => 'adminApi#grants', 'url' => '/api/v1/projects/{projectId}/grants', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'adminApi#createGrant', 'url' => '/api/v1/projects/{projectId}/grants', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+']],
		['name' => 'adminApi#revokeGrant', 'url' => '/api/v1/projects/{projectId}/grants/{grantId}/revoke', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+', 'grantId' => '\d+']],
		['name' => 'adminApi#grantReads', 'url' => '/api/v1/projects/{projectId}/grants/{grantId}/reads', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+', 'grantId' => '\d+']],
		['name' => 'adminApi#templates', 'url' => '/api/v1/templates', 'verb' => 'GET'],
		['name' => 'adminApi#accessConfig', 'url' => '/api/v1/settings/access', 'verb' => 'GET'],
		['name' => 'adminApi#saveAccessConfig', 'url' => '/api/v1/settings/access', 'verb' => 'PUT'],
		['name' => 'licenseApi#show', 'url' => '/api/v1/license', 'verb' => 'GET'],
		['name' => 'licenseApi#apply', 'url' => '/api/v1/license', 'verb' => 'POST'],
		['name' => 'licenseApi#remove', 'url' => '/api/v1/license', 'verb' => 'DELETE'],
		['name' => 'adminApi#seats', 'url' => '/api/v1/settings/seats', 'verb' => 'GET'],
		['name' => 'adminApi#assignSeat', 'url' => '/api/v1/settings/seats', 'verb' => 'POST'],
		['name' => 'adminApi#directoryUsers', 'url' => '/api/v1/directory/users', 'verb' => 'GET'],
		['name' => 'adminApi#directoryGroups', 'url' => '/api/v1/directory/groups', 'verb' => 'GET'],
		['name' => 'adminApi#notifyPref', 'url' => '/api/v1/settings/notify', 'verb' => 'GET'],
		['name' => 'adminApi#saveNotifyPref', 'url' => '/api/v1/settings/notify', 'verb' => 'PUT'],
		['name' => 'adminApi#installTemplate', 'url' => '/api/v1/templates/install', 'verb' => 'POST'],
		['name' => 'adminApi#freeSeat', 'url' => '/api/v1/settings/seats/{uid}', 'verb' => 'DELETE'],

		// ICS deadline feed (I-08): public token-bound route + admin mgmt
		['name' => 'calendarApi#ics', 'url' => '/projects/{projectId}/calendar.ics', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'calendarApi#feed', 'url' => '/api/v1/projects/{projectId}/ics-feed', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'calendarApi#rotateFeed', 'url' => '/api/v1/projects/{projectId}/ics-feed/rotate', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+']],

		// Settings pages (one page per topic — family settings standard)
		['name' => 'page#settings', 'url' => '/settings', 'verb' => 'GET'],
		['name' => 'page#settingsSection', 'url' => '/settings/{section}', 'verb' => 'GET', 'requirements' => ['section' => '[a-z-]+']],

		// CSV import
		['name' => 'importApi#times', 'url' => '/api/v1/projects/{projectId}/import/times', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+']],
		['name' => 'importApi#receipts', 'url' => '/api/v1/projects/{projectId}/import/receipts', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+']],

		// Auditor read surface (grant-scoped)
		['name' => 'auditorApi#myProjects', 'url' => '/api/v1/auditor/projects', 'verb' => 'GET'],
		['name' => 'auditorApi#project', 'url' => '/api/v1/auditor/projects/{projectId}', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'auditorApi#sollIst', 'url' => '/api/v1/auditor/projects/{projectId}/soll-ist', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'auditorApi#belege', 'url' => '/api/v1/auditor/projects/{projectId}/belege', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'auditorApi#nachweise', 'url' => '/api/v1/auditor/projects/{projectId}/nachweise', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],

		// Mobile companion contract (seat-gated)
		['name' => 'mobileApi#status', 'url' => '/api/v1/mobile/status', 'verb' => 'GET'],
		['name' => 'mobileApi#myEntries', 'url' => '/api/v1/mobile/projects/{projectId}/entries', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'mobileApi#book', 'url' => '/api/v1/mobile/projects/{projectId}/entries', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+']],
		['name' => 'mobileApi#positions', 'url' => '/api/v1/mobile/projects/{projectId}/positions', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'mobileApi#receipts', 'url' => '/api/v1/mobile/projects/{projectId}/receipts', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
		['name' => 'mobileApi#bookReceipt', 'url' => '/api/v1/mobile/projects/{projectId}/receipts', 'verb' => 'POST', 'requirements' => ['projectId' => '\d+']],
		['name' => 'mobileApi#timesheets', 'url' => '/api/v1/mobile/projects/{projectId}/timesheets', 'verb' => 'GET', 'requirements' => ['projectId' => '\d+']],
	],
];
