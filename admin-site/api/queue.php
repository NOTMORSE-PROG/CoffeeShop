<?php
/**
 * The live order queue as JSON, polled by the dashboard every fifteen
 * seconds so a new order appears without anyone pressing refresh.
 */

declare(strict_types=1);

require_once __DIR__ . '/../partials/queue.php';

start_session();
send_security_headers();
require_login('../login.php');

json_response(queue_payload());
