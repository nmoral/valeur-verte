<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

$kernel = new Kernel('test', (bool) ($_SERVER['APP_DEBUG'] ?? true));
$kernel->boot();

$doctrine = $kernel->getContainer()->get('doctrine');
assert($doctrine instanceof Doctrine\Persistence\ManagerRegistry);

return $doctrine->getManager();
