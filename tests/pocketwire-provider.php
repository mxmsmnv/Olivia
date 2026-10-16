<?php
$module = (string)file_get_contents(dirname(__DIR__) . '/Olivia.module.php');
$read = strstr($module, 'public function pocketWireRead');
$read = $read === false ? '' : (strstr($read, 'private function pocketWireCanRead', true) ?: '');
$checks = [
	str_contains($module, "'pocketWireProvider' => true"),
	str_contains($module, 'pocketWireProviderInfo()'),
	str_contains($module, 'pocketWireManifest(User $actor)'),
	str_contains($module, 'pocketWireRead(string $resource, string $identifier, array $query, User $actor)'),
	str_contains($module, "hasPermission('olivia')") && str_contains($module, "wire('user')"),
	str_contains($module, "'id' => 'status'") && str_contains($module, "'actions' => []"),
	str_contains($module, "'layout' => 'dashboard'") && str_contains($module, "'layout' => 'metrics'"),
	str_contains($module, "'configuration_exposed' => false") && str_contains($module, "'content_exposed' => false"),
	$read !== '' && !preg_match('/settings\(|mcpOliviaStatus\(|ask\(|build\(|send\(/', $read),
];
if(in_array(false, $checks, true)) throw new RuntimeException('Olivia PocketWire provider contract failed.');
echo 'Olivia PocketWire provider contract passed: ' . count($checks) . " checks.\n";
