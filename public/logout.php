<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Auth.php';

use App\Auth;

Auth::logout();
header('Location: login.php');
exit;
