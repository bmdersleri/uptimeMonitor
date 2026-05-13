<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

auth()->logout();
redirect_to('/login.php');
