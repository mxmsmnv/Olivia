<?php namespace ProcessWire;

/** Bootstrap ProcessWire for Olivia CLI entrypoints, including symlink installs. */
function oliviaCliBootstrap(): void {
	$root = rtrim((string) getenv('OLIVIA_SITE_ROOT'), '/\\');
	$candidates = [];
	if($root !== '') $candidates[] = $root . '/index.php';
	$candidates[] = dirname(__DIR__, 4) . '/index.php';

	$index = '';
	foreach(array_unique($candidates) as $candidate) {
		if(is_file($candidate)) { $index = $candidate; break; }
	}
	if($index === '') {
		fwrite(STDERR, "ProcessWire index.php not found; set OLIVIA_SITE_ROOT\n");
		exit(1);
	}

	require_once $index;
	require_once dirname(__DIR__) . '/src/bootstrap.php';
	oliviaRegisterSourceLoader(wire('classLoader'), dirname(__DIR__) . '/src');
}
