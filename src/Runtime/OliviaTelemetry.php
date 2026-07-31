<?php namespace ProcessWire;

/**
 * OliviaTelemetry — opt-in, privacy-first usage signals
 *
 * Records STRUCTURE and OUTCOMES only — never field values, page content, URLs
 * or secrets. Off unless the user enables it (Olivia config "telemetry").
 *
 * Telemetry is LOCAL only: events go to the PW log 'olivia-telemetry'. A future version
 * could upload aggregated anonymous events to an Olivia service. The strongest
 * signals are the Undo-rate (build then immediate undo) and edit-diffs.
 */
class OliviaTelemetry extends Wire {

	/** Enabled only when the user opted in (Olivia module config). */
	public function isEnabled(): bool {
		$cfg = $this->wire->modules->getModuleConfigData('Olivia');
		return !empty($cfg['telemetry']);
	}

	/** Record a structural event if opted in. Data must be shapes/counts only. */
	public function event(string $name, array $data = []): void {
		if(!$this->isEnabled()) return;
		$payload = array_merge(['event' => $name], $this->scrub($data));
		try { $this->wire->log->save('olivia-telemetry', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)); }
		catch(\Throwable $e) { /* never let telemetry break a build */ }
	}

	/** 👍/👎 feedback on the last build/plan. */
	public function feedback(string $rating, string $note = ''): void {
		$this->event('feedback', ['rating' => $rating === 'up' ? 'up' : 'down', 'note_len' => mb_strlen(trim($note))]);
	}

	/** Compute a content-free shape of a plan for telemetry. */
	public function planShape(array $plan): array {
		$fieldTypes = [];
		foreach((array)($plan['fields'] ?? []) as $f) {
			$t = is_array($f) ? ($f['type'] ?? 'text') : 'text';
			$fieldTypes[$t] = ($fieldTypes[$t] ?? 0) + 1;
		}
		$pageCount = 0; $this->countPages((array)($plan['pages'] ?? []), $pageCount);
		return [
			'type'        => trim((string)($plan['site']['type'] ?? '')), // a category word, not content
			'fields'      => count((array)($plan['fields'] ?? [])),
			'templates'   => count((array)($plan['templates'] ?? [])),
			'pages'       => $pageCount,
			'field_types' => $fieldTypes,
			'modules'     => array_values(array_filter(array_map(fn($m) => is_array($m) ? trim((string)($m['class'] ?? $m['name'] ?? '')) : trim((string)$m), (array)($plan['modules'] ?? [])))),
		];
	}

	protected function countPages(array $pages, int &$n): void {
		foreach($pages as $p) {
			if(!is_array($p)) continue;
			$n++;
			if(!empty($p['children']) && is_array($p['children'])) $this->countPages($p['children'], $n);
		}
	}

	/** Drop anything that isn't a scalar/number/array-of-scalars (defensive). */
	protected function scrub(array $data): array {
		$out = [];
		foreach($data as $k => $v) {
			if(is_array($v)) $out[$k] = $this->scrub($v);
			elseif(is_scalar($v) || $v === null) $out[$k] = $v;
			// objects/resources are never recorded
		}
		return $out;
	}
}
