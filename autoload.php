<?php

declare(strict_types=1);

namespace GhezibdeAncestorsChart;

use Composer\Autoload\ClassLoader;

//Autoload this webtrees custom module
$loader = new ClassLoader(__DIR__);
$loader->addPsr4('GhezibdeAncestorsChart\\', __DIR__);
$loader->register();

return true;
