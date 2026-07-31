<?php namespace ProcessWire;

/**
 * OliviaInterviewer
 *
 * Interview mode step 1: turn a vague prompt into a few high-impact clarifying
 * questions. Step 2 (planning) reuses OliviaPlanner with an augmented prompt
 * built from the user's answers.
 *
 * Keeps the "conversation before automation" invariant: nothing is built until
 * requirements are gathered and the resulting plan is reviewed.
 */
class OliviaInterviewer extends Wire {

	public const MAX_TOKENS = 4000;
	public const TIMEOUT = 120;

	const SYSTEM_PROMPT = <<<'TXT'
You are Olivia, an AI Solution Architect for ProcessWire.
The user wants to build a website. Ask a SHORT set of clarifying questions whose
answers would materially change the site's architecture: content types, key
features, pages, languages, integrations, or which modules are needed.

Return ONLY valid JSON, no prose, no markdown fences:

{ "questions": [
  { "q": "Clear, specific question?", "options": ["Option A", "Option B", "..."] }
] }

Rules:
- 3 to 6 questions maximum. Prefer the highest-impact ones.
- "options" is optional: include 2-5 concrete choices when the answer is a
  clear pick; omit or use [] for free-text answers.
- Do not ask about visual styling minutiae (colors, fonts). Focus on structure
  and functionality.
- Keep each question to one sentence.
TXT;

	/**
	 * Generate clarifying questions for a prompt.
	 *
	 * @param string $prompt
	 * @param array $options Squad overrides
	 * @return array list of ['q'=>string, 'options'=>string[]]
	 * @throws WireException
	 */
	public function questions(string $prompt, array $options = []): array {
		$prompt = trim($prompt);
		if($prompt === '') throw new WireException('Empty prompt.');

		/** @var Squad $ai */
		$ai = $this->wire->modules->get('Squad');
		if(!$ai) throw new WireException('Squad module is not installed.');

		$result = $ai->ask($prompt, array_merge([
			'systemPrompt' => self::SYSTEM_PROMPT,
			'temperature'  => 0.3,
			'maxTokens'    => self::MAX_TOKENS, // reasoning models (e.g. DeepSeek Pro) spend tokens thinking; too low => empty output
			'timeout'      => self::TIMEOUT,
		], $this->wire(new OliviaRoles())->options('developer'), $options));

		if(empty($result['success'])) {
			$msg = $result['message'] ?? 'unknown error';
			throw new WireException("Squad could not generate questions: $msg. Check the Squad provider/model configuration and try again.");
		}

		$content = (string)($result['content'] ?? '');
		if(trim($content) === '') {
			throw new WireException('The model returned an empty response (it may have hit the token limit while reasoning). Try again or pick a faster model.');
		}

		$data = $this->extractJson($content);
		if($data === null || !isset($data['questions']) || !is_array($data['questions'])) {
			throw new WireException('Model did not return usable questions. Raw: ' . substr($content, 0, 200));
		}

		$out = [];
		foreach($data['questions'] as $item) {
			if(is_string($item)) { $out[] = ['q' => $item, 'options' => []]; continue; }
			if(!is_array($item) || empty($item['q'])) continue;
			$opts = [];
			foreach((array)($item['options'] ?? []) as $o) {
				$o = trim((string)$o);
				if($o !== '') $opts[] = $o;
			}
			$out[] = ['q' => trim((string)$item['q']), 'options' => $opts];
		}
		if(!$out) throw new WireException('No valid questions returned.');
		return $out;
	}

	/**
	 * Build an augmented prompt from the original prompt + answered questions,
	 * suitable for OliviaPlanner::plan().
	 *
	 * @param string $prompt original prompt
	 * @param array $qa list of ['q'=>string,'a'=>string]
	 * @return string
	 */
	public function augmentPrompt(string $prompt, array $qa): string {
		$lines = [trim($prompt), '', 'Clarifications gathered in interview:'];
		foreach($qa as $pair) {
			$q = trim((string)($pair['q'] ?? ''));
			$a = trim((string)($pair['a'] ?? ''));
			if($q === '') continue;
			$lines[] = '- ' . $q . ' => ' . ($a !== '' ? $a : '(no preference)');
		}
		return implode("\n", $lines);
	}

	/** Extract a JSON object from model output, tolerating fences/prose. */
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
