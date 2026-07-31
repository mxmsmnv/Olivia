<?php namespace ProcessWire;

/** Register Olivia's existing ProcessWire namespace across its domain folders. */
if(!function_exists(__NAMESPACE__ . '\\oliviaRegisterSourceLoader')) {
	function oliviaRegisterSourceLoader($classLoader, string $srcRoot): void {
		foreach(['Admin', 'Planning', 'Build', 'Reference', 'Runtime', 'Integration'] as $directory) {
			$classLoader->addNamespace('ProcessWire', rtrim($srcRoot, '/\\') . '/' . $directory);
		}
	}
}
