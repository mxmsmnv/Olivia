<?php
$module = (string)file_get_contents(dirname(__DIR__) . '/Olivia.module.php');
$checks = [str_contains($module, "'mcpProvider' => true"), str_contains($module, "'olivia_status'"), !str_contains($module, "'lqrs_olivia_status'"), str_contains($module, "'additionalProperties' => false"), str_contains($module, "'write_tools' => false")];
if(in_array(false, $checks, true)) { fwrite(STDERR, "Olivia MCP provider contract failed.\n"); exit(1); }
echo "Olivia MCP provider contract passed.\n";
