<?php

declare(strict_types=1);

$gravRoot = $argv[1] ?? '';
$autoload = $gravRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

if ($gravRoot === '' || !is_dir($gravRoot) || !is_file($autoload)) {
    fwrite(STDERR, "Usage: php tests/clean-grav.php /path/to/grav\n");
    exit(2);
}

require_once $autoload;
require_once dirname(__DIR__) . '/virtual-collections.php';

$class = \Grav\Plugin\VirtualCollectionsPlugin::class;
if (!class_exists($class)) {
    fwrite(STDERR, "FAIL: plugin class was not loadable with the Grav autoloader.\n");
    exit(1);
}

$reflection = new ReflectionClass($class);
if ($reflection->getParentClass()?->getName() !== \Grav\Common\Plugin::class) {
    fwrite(STDERR, "FAIL: plugin does not extend Grav\\Common\\Plugin.\n");
    exit(1);
}

$events = $class::getSubscribedEvents();
if (($events['onPagesInitialized'][1] ?? null) !== 1100 || !isset($events['onTwigInitialized'])) {
    fwrite(STDERR, "FAIL: plugin event subscription is not compatible with the documented contract.\n");
    exit(1);
}

echo "OK: plugin loaded against clean Grav installation.\n";
