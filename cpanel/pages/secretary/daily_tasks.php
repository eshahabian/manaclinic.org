<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';

require_login(['SECRETARY']);
redirect('/secretary/messages');
