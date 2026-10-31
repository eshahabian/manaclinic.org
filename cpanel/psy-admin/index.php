<?php
declare(strict_types=1);
require_once __DIR__.'/entry.php';
\ManaCms\dispatch();
http_response_code(404);
