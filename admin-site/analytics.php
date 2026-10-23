<?php
/** Compatibility link: analytics now lives on the owner dashboard. */
declare(strict_types=1);
require_once __DIR__ . '/partials/layout.php';
start_session();
send_security_headers();
require_owner();
redirect(admin_url('index.php') . '?' . http_build_query(array_merge($_GET, ['view' => 'sales'])) . '#dashboard-analytics');
