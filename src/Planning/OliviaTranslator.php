<?php namespace ProcessWire;

/**
 * OliviaTranslator — the "translator" role. Translates / localizes a piece of
 * copy into a target language, routed to the translator role's model. Ready for
 * the multilingual (i18n) build flow; callable now via translate().
 */
class OliviaTranslator extends Wire {

	/** Translate $text into $targetLanguage, preserving markup/tone. '' on failure. */
	public function translate(string $text, string $targetLanguage): string {
		$text = trim($text);
		$targetLanguage = trim($targetLanguage);
		if($text === '' || $targetLanguage === '') return '';
		if(!$this->wire->modules->isInstalled('Squad')) return '';
		/** @var Squad $ai */
		$ai = $this->wire->modules->get('Squad');

		$system = 'You are a professional translator and localizer. Translate the text into ' . $targetLanguage
			. ', preserving meaning, tone and any HTML/markup. Localize naturally, do not translate brand names. '
			. 'Return ONLY the translation — no notes, no quotes.';

		$res = $ai->ask($text, array_merge([
			'systemPrompt' => $system,
			'temperature'  => 0.2,
			'maxTokens'    => 3000,
			'timeout'      => 60,
		], $this->wire(new OliviaRoles())->options('translator')));
		if(empty($res['success'])) return '';

		return trim((string)($res['content'] ?? ''));
	}
}
