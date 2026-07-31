<?php namespace ProcessWire;

/**
 * OliviaEditor — the "editor" role. Proofreads and tightens a piece of copy
 * (grammar, filler, repetition) while keeping meaning, tone and length. Routed
 * to the editor role's model. Only invoked when an editor model is configured.
 */
class OliviaEditor extends Wire {

	const SYSTEM_PROMPT = 'You are a meticulous web editor. Tighten and proofread the text: fix grammar, '
		. 'cut filler and repetition, keep the meaning, voice and approximate length. Return ONLY the edited '
		. 'text — no commentary, no quotes, no markdown fences.';

	/** Return an edited version of $text, or '' on failure. */
	public function refine(string $text, int $maxTokens = 2000): string {
		$text = trim($text);
		if($text === '') return '';
		if(!$this->wire->modules->isInstalled('Squad')) return '';
		/** @var Squad $ai */
		$ai = $this->wire->modules->get('Squad');

		$res = $ai->ask($text, array_merge([
			'systemPrompt' => self::SYSTEM_PROMPT,
			'temperature'  => 0.3,
			'maxTokens'    => max(2000, $maxTokens),
			'timeout'      => 60,
		], $this->wire(new OliviaRoles())->options('editor')));
		if(empty($res['success'])) return '';

		$out = trim((string)($res['content'] ?? ''));
		return trim($out, "\"'");
	}
}
