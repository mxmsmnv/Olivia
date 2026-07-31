<?php namespace ProcessWire;

/**
 * OliviaContribute — invite module authors + share blueprints
 *
 * Two ecosystem-growth helpers:
 *  - the "make Olivia use your module" invitation + a copy-paste AGENTS.md template
 *    (so authors can make their module Olivia-aware in minutes),
 *  - shareableBlueprint(): strips a plan down to STRUCTURE only (no content/field
 *    values), so a user can share a blueprint without leaking any site content.
 */
class OliviaContribute extends Wire {

	/** Short invitation shown on the Olivia module page. */
	public function inviteText(): string {
		return 'Module authors: make Olivia recommend and use your module. Add an '
			. 'AGENTS.md file to your module folder describing when and how to use it. '
			. 'Olivia reads it automatically (Refresh module skills) and will then assemble '
			. 'sites with your module — “reviews → your module”, “SEO → your module”. '
			. 'It is also the emerging cross-tool standard (Claude Code, Codex, Cursor read AGENTS.md too).';
	}

	/** Copy-paste AGENTS.md skeleton for a module author. */
	public function agentsTemplate(): string {
		return <<<'MD'
# <ModuleName> — Agent Instructions

## What it does
One or two sentences on the capability this module provides.

## When to use
- Use this module when the user asks for: <e.g. reviews, ratings, Q&A>
- Do NOT build a custom solution for that if this module is installed.

## Public API
```php
$m = $modules->get('<ModuleName>');
echo $m->renderSomething($page);   // key methods an integrator needs
```

## Fields / templates it provides or expects
- <field/template names, if any>

## Safe extension points
- /site/templates/, /site/classes/, hooks — never edit this module's own files.

## Notes / gotchas
- <licensing, requirements, anything an AI assembler must know>
MD;
	}

	/**
	 * Return a share-safe version of a plan: structure only, no content/field values.
	 *
	 * @param array $plan
	 * @return array
	 */
	public function shareableBlueprint(array $plan): array {
		$out = [
			'site'      => ['type' => trim((string)($plan['site']['type'] ?? ''))], // keep type, drop the specific title
			'fields'    => [],
			'templates' => [],
			'pages'     => [],
			'modules'   => array_values(array_filter(array_map(fn($m) => is_array($m) ? trim((string)($m['class'] ?? $m['name'] ?? '')) : trim((string)$m), (array)($plan['modules'] ?? [])))),
		];
		foreach((array)($plan['fields'] ?? []) as $f) {
			if(!is_array($f)) continue;
			$out['fields'][] = ['name' => $f['name'] ?? '', 'type' => $f['type'] ?? 'text', 'label' => $f['label'] ?? ''];
		}
		foreach((array)($plan['templates'] ?? []) as $t) {
			if(!is_array($t)) continue;
			$out['templates'][] = ['name' => $t['name'] ?? '', 'label' => $t['label'] ?? '', 'fields' => array_values((array)($t['fields'] ?? []))];
		}
		$out['pages'] = $this->stripPages((array)($plan['pages'] ?? []));
		return $out;
	}

	/** Recursively keep page title/template/structure, drop all content values. */
	protected function stripPages(array $pages): array {
		$clean = [];
		foreach($pages as $p) {
			if(!is_array($p)) continue;
			$entry = [
				'title'    => $p['title'] ?? '',   // titles describe structure (e.g. "Menu"), keep
				'template' => $p['template'] ?? 'section',
			];
			if(isset($p['parent'])) $entry['parent'] = $p['parent'];
			if(!empty($p['children']) && is_array($p['children'])) $entry['children'] = $this->stripPages($p['children']);
			$clean[] = $entry; // note: NO 'content' — field values are never shared
		}
		return $clean;
	}
}
