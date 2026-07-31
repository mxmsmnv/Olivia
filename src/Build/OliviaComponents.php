<?php namespace ProcessWire;

/**
 * OliviaComponents — the component catalog.
 *
 * Olivia builds sites from a fixed vocabulary of section components (hero, card
 * grid, team, pricing, FAQ, testimonials, …). The catalog lives in
 * components/catalog.json; reference Tailwind snippets live in
 * components/references/<id>.html. Related reference variants may share an
 * archetype through their `reference` key. "rendered" components are already
 * produced by OliviaViewGenerator; "reference" ones are ready-to-wire markup.
 *
 * This gives Olivia (and the planner) a known palette instead of ad-hoc layout,
 * and a place to grow by importing components from any design system.
 */
class OliviaComponents extends Wire {

	protected function dir(): string {
		return dirname(__DIR__, 2) . '/components/';
	}

	/** All catalog entries (each: id,name,category,purpose,needs,status), or []. */
	public function all(): array {
		$f = $this->dir() . 'catalog.json';
		if(!is_file($f)) return [];
		$raw = @file_get_contents($f);
		if(!is_string($raw)) return [];
		$data = json_decode($raw, true);
		return is_array($data) && isset($data['components']) ? $data['components'] : [];
	}

	/** One component entry by id, or null. */
	public function get(string $id): ?array {
		foreach($this->all() as $c) if(($c['id'] ?? '') === $id) return $c;
		return null;
	}

	/** Reference Tailwind markup for a component id, or null. */
	public function reference(string $id): ?string {
		$id = preg_replace('/[^a-z0-9\-]/', '', strtolower($id));
		$component = $this->get($id);
		$reference = (string)($component['reference'] ?? $id);
		$reference = preg_replace('/[^a-z0-9\-]/', '', strtolower($reference));
		if($reference === '') return null;
		$f = $this->dir() . 'references/' . $reference . '.html';
		if(!is_file($f)) return null;
		$raw = @file_get_contents($f);
		return is_string($raw) ? $raw : null;
	}

	/** Catalog integrity errors. An empty array means the catalog is safe to use. */
	public function validate(): array {
		$errors = [];
		$seen = [];
		foreach($this->all() as $index => $component) {
			$id = (string)($component['id'] ?? '');
			$at = "components[$index]";
			if($id === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id)) {
				$errors[] = "$at has an invalid id";
				continue;
			}
			if(isset($seen[$id])) $errors[] = "$at duplicates component id $id";
			$seen[$id] = true;
			foreach(['name', 'category', 'purpose'] as $key) {
				if(trim((string)($component[$key] ?? '')) === '') $errors[] = "$id is missing $key";
			}
			$status = (string)($component['status'] ?? '');
			if(!in_array($status, ['rendered', 'reference'], true)) $errors[] = "$id has invalid status $status";
			if(!isset($component['needs']) || !is_array($component['needs'])) $errors[] = "$id needs must be an array";
			if($status === 'reference' && $this->reference($id) === null) $errors[] = "$id has no reference snippet";
		}
		return $errors;
	}

	/** Entries grouped by category, preserving catalog order. */
	public function categories(): array {
		$groups = [];
		foreach($this->all() as $component) {
			$category = (string)($component['category'] ?? 'other');
			$groups[$category][] = $component;
		}
		return $groups;
	}

	/**
	 * Compact palette for the planner prompt: one line per component so plans are
	 * composed around components Olivia knows how to build.
	 */
	public function vocabulary(bool $includeReferences = false): string {
		$lines = [];
		foreach($this->all() as $c) {
			$id = $c['id'] ?? ''; if($id === '') continue;
			if(!$includeReferences && ($c['status'] ?? '') !== 'rendered') continue;
			$lines[] = "- {$id} ({$c['name']}): " . ($c['purpose'] ?? '');
		}
		return implode("\n", $lines);
	}

	/** Compact reference-only taxonomy for structural inspiration in model prompts. */
	public function referenceVocabulary(): string {
		$groups = [];
		foreach($this->all() as $component) {
			if(($component['status'] ?? '') !== 'reference') continue;
			$category = (string)($component['category'] ?? 'other');
			$id = (string)($component['id'] ?? '');
			if($id !== '') $groups[$category][] = $id;
		}
		$lines = [];
		foreach($groups as $category => $ids) $lines[] = "- $category: " . implode(', ', $ids);
		return implode("\n", $lines);
	}
}
