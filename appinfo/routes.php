<?php

declare(strict_types=1);

return [
	'ocs' => [
		// Admin settings
		['name' => 'AdminSettings#getSettings',       'url' => '/admin/settings',          'verb' => 'GET'],
		['name' => 'AdminSettings#getChecks',         'url' => '/admin/checks',            'verb' => 'GET'],
		['name' => 'AdminSettings#previewPayload',    'url' => '/admin/preview-payload',   'verb' => 'GET'],
		['name' => 'AdminSettings#runNow',            'url' => '/admin/run-now',           'verb' => 'POST'],
		['name' => 'AdminSettings#testConnection',    'url' => '/admin/test-connection',   'verb' => 'GET'],
		['name' => 'AdminSettings#retryRegistration', 'url' => '/admin/retry-registration','verb' => 'POST'],
	],
];
