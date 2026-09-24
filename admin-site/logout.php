<?php
/**
 * Sign out.
 *
 * POST only, with a CSRF token, so a stray link or an image tag on another
 * site cannot sign the admin out.
 */

declare(strict_types=1);

require_once __DIR__ . '/partials/layout.php';

start_session();
send_security_headers();
require_post_with_csrf();

logout('You have been signed out.');
