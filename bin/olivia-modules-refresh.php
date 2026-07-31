<?php namespace ProcessWire;

/**
 * Refresh the ProcessWire module directory index used by OliviaModules for
 * trust/recommendation data. Run from CLI (it's slow, ~80s, paginated):
 *
 *   php bin/olivia-modules-refresh.php
 *
 * Cached for 24h; the web UI only ever reads the cache (never blocks).
 */

if(php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/olivia-bootstrap.php';
oliviaCliBootstrap();

$t0 = microtime(true);
$n = wire(new OliviaModules())->refresh();
printf("Indexed %d modules in %.1fs\n", $n, microtime(true) - $t0);
