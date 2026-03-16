<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

plc_logout_user();
plc_redirect('index.php');

