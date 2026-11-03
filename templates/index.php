<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\NCCare\AppInfo\Application::APP_ID, OCA\NCCare\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\NCCare\AppInfo\Application::APP_ID, OCA\NCCare\AppInfo\Application::APP_ID . '-main');

?>

<div id="nccare"></div>
