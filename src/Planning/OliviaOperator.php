<?php namespace ProcessWire;

/**
 * OliviaOperator — the "Operate" leg of Create → Change → Operate.
 *
 * Audits the EXISTING site (grounded in its real templates/fields/pages and the
 * modules available in the project) and returns a prioritized list of concrete
 * improvements. It never changes anything; high-value findings carry a
 * "change_prompt" the user can feed straight into Change mode to apply them.
 */
class OliviaOperator extends Wire {

	const SYSTEM_PROMPT = <<<'TXT'
You are Olivia, an AI Solution Architect auditing an EXISTING ProcessWire site.
Review the site's structure and the modules available, then return a PRIORITIZED
list of concrete, high-leverage improvements.

Return ONLY valid JSON, no prose, no markdown fences. Exact shape:

{
  "summary": "one-sentence overall assessment of the site",
  "findings": [
    {
      "title": "short imperative title",
      "area": "SEO|Content|Structure|Performance|Accessibility|Conversion|Modules",
      "severity": "high|medium|low",
      "why": "one sentence: why this matters for THIS site",
      "suggestion": "concrete action the site owner can take",
      "change_prompt": "optional one-line prompt for Olivia's Change mode that would apply this"
    }
  ]
}

Rules:
- Return 4-8 findings, most impactful first.
- Ground EVERY finding in the ACTUAL site below — reference real page/template/field
  names. Do NOT invent pages, templates or fields that are not listed.
- Prefer high-leverage wins: missing key pages, thin or empty content, no SEO module
  or missing meta, no clear contact/conversion path, accessibility gaps, weak structure.
- When a finding maps to an AVAILABLE module, name that module in "suggestion" and set "area":"Modules".
- "change_prompt" must be safe to run in Change mode (it EXTENDS the existing site, using
  real names). Omit it for advisory-only findings (e.g. "write more detailed copy").
TXT;

	/**
	 * Audit the current site. Returns ['summary'=>string, 'findings'=>array].
	 * @throws WireException
	 */
	public function audit(array $options = [], string $focus = ''): array {
		/** @var Squad $ai */
		$ai = $this->wire->modules->get('Squad');
		if(!$ai) throw new WireException('Squad module is not installed.');

		$ctx = $this->wire(new OliviaSiteContext())->summary();
		$modules = $this->wire(new OliviaSkills())->promptIndex();

		$system = self::SYSTEM_PROMPT;
		if($modules !== '') $system .= "\n\nMODULES AVAILABLE IN THIS PROJECT (prefer these when a fix needs one):\n" . $modules;
		$system .= "\n\nCURRENT SITE:\n" . $ctx;

		// ground content-quality findings in REAL copy via the Atlas RAG store
		// (no-op unless the site has been indexed and an embedding provider exists)
		$samples = $this->contentSamples();
		if($samples !== '') $system .= "\n\nCONTENT SAMPLES (actual on-site copy — judge quality/consistency, don't invent):\n" . $samples;

		$focus = trim($focus);
		$instruction = $focus !== ''
			? "Audit this site with this user-requested focus:\n" . $focus
			: 'Audit this site and list the most valuable improvements.';
		$result = $ai->ask($instruction, array_merge([
			'systemPrompt' => $system,
			'temperature'  => 0.3,
			'maxTokens'    => 6000,
			'timeout'      => 120,
		], $this->wire(new OliviaRoles())->options('designer'), $options));

		if(empty($result['success'])) {
			throw new WireException('Squad could not generate an audit: ' . ($result['message'] ?? 'unknown error') . '.');
		}
		$data = $this->extractJson((string)($result['content'] ?? ''));
		if($data === null) {
			throw new WireException('Model did not return valid JSON. Raw output: ' . substr((string)$result['content'], 0, 300));
		}
		return [
			'summary'  => (string)($data['summary'] ?? ''),
			'findings' => $this->normalizeFindings((array)($data['findings'] ?? [])),
		];
	}

	/** Keep model findings bounded and never advertise destructive Change actions. */
	protected function normalizeFindings(array $findings): array {
		$out = [];
		foreach($findings as $finding) {
			if(!is_array($finding)) continue;
			$changePrompt = trim((string)($finding['change_prompt'] ?? ''));
			if($changePrompt !== '' && preg_match('/\b(delete|remove|drop|uninstall)\b/i', $changePrompt)) {
				unset($finding['change_prompt']);
			}
			$out[] = $finding;
			if(count($out) >= 8) break;
		}
		return $out;
	}

	/** A few representative on-site copy snippets from Atlas, or '' when unavailable. */
	protected function contentSamples(): string {
		$atlas = $this->wire(new OliviaAtlas());
		$home = $this->wire->pages->get('/');
		$q = trim((string) $home->title . ' ' . (string) $home->get('summary|headline|tagline'));
		if($q === '') $q = 'main content services about contact';
		$parts = [];
		foreach($atlas->context($q, 4) as $h) {
			$t = trim((string)($h['text'] ?? ''));
			if($t !== '') $parts[] = '- ' . mb_substr($t, 0, 300);
		}
		return implode("\n", $parts);
	}

	/** Extract a JSON object from model output, tolerating code fences/prose. */
	protected function extractJson(string $text): ?array {
		$text = trim($text);
		if($text === '') return null;
		if(preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $m)) $text = $m[1];
		if($text[0] !== '{') {
			$start = strpos($text, '{');
			$end = strrpos($text, '}');
			if($start === false || $end === false) return null;
			$text = substr($text, $start, $end - $start + 1);
		}
		$data = json_decode($text, true);
		return is_array($data) ? $data : null;
	}
}
