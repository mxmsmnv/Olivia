<?php namespace ProcessWire;

/**
 * OliviaContentFiller
 *
 * Fills EMPTY text/textarea fields on generated (and reused) pages with copy
 * written by Squad, using the page's existing content as context. This is the
 * text counterpart of OliviaImageGenerator and the thing the Operate audit asks
 * for ("…then use Squad to populate it for all pages"): when Change-mode adds a
 * field (e.g. meta_description) it lands empty — a fill pass writes it for every
 * page that has it.
 *
 * Non-destructive: only ever writes to fields that are currently empty. Bounded
 * by MAX_FILLS per build so it can't run up unbounded paid calls.
 */
class OliviaContentFiller extends Wire {

	const MAX_FILLS = 12; // per build, hard cap on AI calls

	const SYSTEM_PROMPT = 'You are a professional website copywriter. Write ONLY the requested field value — '
		. 'no field name, no label, no surrounding quotes, no markdown. Match a natural, specific brand voice '
		. 'and never invent contact details, prices or facts that are not implied by the context.';

	/** Enabled when Squad is installed and has a usable provider. */
	public function isEnabled(): bool {
		try {
			return (bool) $this->wire->modules->isInstalled('Squad');
		} catch(\Throwable $e) {
			return false;
		}
	}

	/**
	 * Fill empty text fields for the pages created/reused in this build.
	 * @return int number of fields filled
	 */
	public function fillForBuild(array $plan, array &$manifest): int {
		if(!$this->isEnabled()) {
			$manifest['errors'][] = 'content fill: Squad is not installed — skipped.';
			return 0;
		}
		/** @var Squad $ai */
		try {
			$ai = $this->wire->modules->get('Squad');
		} catch(\Throwable $e) {
			$manifest['errors'][] = 'content fill: Squad could not load — ' . mb_substr($e->getMessage(), 0, 300);
			return 0;
		}
		if(!$ai || !method_exists($ai, 'ask')) {
			$manifest['errors'][] = 'content fill: incompatible Squad API — skipped.';
			return 0;
		}

		$siteTitle = trim((string)($plan['site']['title'] ?? $this->wire->pages->get('/')->title));
		$siteType  = trim((string)($plan['site']['type'] ?? ''));
		$count = 0;

		$atlas = $this->wire(new OliviaAtlas()); // RAG context (no-op unless Atlas is ready + has content)

		$pageIds = array_merge($manifest['pages'] ?? [], $manifest['reused']['pages'] ?? []);
		foreach(array_unique($pageIds) as $pid) {
			if($count >= self::MAX_FILLS) { $manifest['errors'][] = 'content fill: hit per-build cap (' . self::MAX_FILLS . ').'; break; }
			$page = $this->wire->pages->get((int) $pid);
			if(!$page->id) continue;

			foreach($page->template->fieldgroup as $f) {
				if($count >= self::MAX_FILLS) break;
				if(!$this->isTextField($f)) continue;
				$existing = trim((string) $page->get($f->name));
				if($existing !== '') continue; // only fill empty fields

				try {
					$value = $this->writeField($ai, $page, $f, $siteTitle, $siteType, $atlas);
				} catch(\Throwable $e) {
					$manifest['errors'][] = "content fill: provider failed for '{$page->title}'.{$f->name}: " . mb_substr($e->getMessage(), 0, 300);
					continue;
				}
				if($value === '') continue;

				try {
					$page->of(false);
					$page->set($f->name, $value);
					$page->save($f->name);
					$manifest['filled'][] = [
						'page' => $page->id,
						'field' => $f->name,
						'written' => $page->get($f->name),
					];
					$count++;
				} catch(\Throwable $e) {
					$manifest['errors'][] = "content fill: save failed for '{$page->title}'.{$f->name}: " . $e->getMessage();
				}
			}
		}

		$manifest['filled_count'] = $count;
		return $count;
	}

	/** Only plain Text / Textarea fields (never title, images, urls, etc.). */
	protected function isTextField(Field $f): bool {
		if($f->name === 'title') return false;
		$t = (string) $f->type;
		return strpos($t, 'Textarea') !== false || preg_match('/FieldtypeText(?!area)/', $t) === 1;
	}

	/**
	 * Top relevant snippets from elsewhere on the site (via Atlas RAG), as one
	 * truncated block. '' when Atlas isn't ready or nothing is indexed yet — so
	 * this is a free no-op on a fresh build (the page corpus is indexed after the
	 * fill pass) and only kicks in for change-mode / re-fills over existing content.
	 * $keyword (the field label) is a hybrid boost: chunks literally mentioning it
	 * rank higher among the semantically-relevant ones. Results are also MMR-
	 * diversified so several chunks of one page don't crowd out others.
	 */
	protected function relatedContext(OliviaAtlas $atlas, Page $page, string $siteType, string $keyword = ''): string {
		$q = trim($page->title . ($siteType !== '' ? " {$siteType}" : ''));
		if($q === '') return '';
		$self = 'page-' . $page->id;
		$parts = [];
		foreach($atlas->context($q, 4, OliviaAtlas::COLLECTION, $keyword) as $h) {
			if(($h['meta']['parent'] ?? $h['ref'] ?? '') === $self) continue; // never feed the page its own chunks
			$t = trim((string)($h['text'] ?? ''));
			if($t !== '') $parts[] = $t;
			if(count($parts) >= 3) break;
		}
		return $parts ? mb_substr(implode("\n---\n", $parts), 0, 800) : '';
	}

	/** Ask Squad to write one field's value, or '' on failure. */
	protected function writeField(Squad $ai, Page $page, Field $f, string $siteTitle, string $siteType, ?OliviaAtlas $atlas = null): string {
		$label = $f->getLabel() ?: $f->name;
		list($lengthHint, $maxTokens) = $this->shape($f);
		$related = $atlas ? $this->relatedContext($atlas, $page, $siteType, $label) : '';

		// gather light context from the page's already-populated fields
		$ctx = [];
		foreach(['summary', 'tagline', 'headline', 'body', 'excerpt'] as $cn) {
			if($cn === $f->name) continue;
			if($page->template->fieldgroup->hasField($cn)) {
				$v = trim((string) $page->get($cn));
				if($v !== '') { $ctx[] = $v; if(count($ctx) >= 2) break; }
			}
		}
		$context = $ctx ? mb_substr(implode("\n", $ctx), 0, 600) : '(no other content yet)';

		$message = "Site: {$siteTitle}" . ($siteType !== '' ? " ({$siteType})" : '') . ".\n"
			. "Page: \"{$page->title}\".\n"
			. "Existing page content for context:\n{$context}\n";
		if($related !== '') {
			$message .= "\nRelated copy elsewhere on this site (match tone and terminology; do NOT copy verbatim or invent new facts):\n{$related}\n";
		}
		$message .= "\nWrite the \"{$label}\" for this page. {$lengthHint}";

		// SEO copy (meta/description) is its own role; everything else is content.
		$roles = $this->wire(new OliviaRoles());
		$role = preg_match('/(meta|seo|description)/', strtolower($f->name . ' ' . $f->getLabel())) ? 'seo' : 'content';

		$res = $ai->ask($message, array_merge([
			'systemPrompt' => self::SYSTEM_PROMPT,
			'temperature'  => 0.5,
			'maxTokens'    => $maxTokens,
			'timeout'      => 60,
		], $roles->options($role)));
		if(empty($res['success'])) return '';

		$out = trim((string)($res['content'] ?? ''));
		$out = trim($out, "\"'");                          // strip wrapping quotes some models add
		$out = preg_replace('/^\s*#{1,6}\s*/m', '', $out); // strip stray markdown headings
		$out = preg_replace('/\*{1,2}(?=\S)(.+?)\*{1,2}/u', '$1', $out); // unwrap **bold**/*italic*
		$out = trim($out);

		// optional editor pass — only when an editor model is configured (opt-in)
		if($out !== '' && $roles->enabled('editor')) {
			$out = $this->wire(new OliviaEditor())->refine($out, $maxTokens) ?: $out;
		}
		return $out;
	}

	/**
	 * Length guidance + token budget based on the field's name/label.
	 * NOTE: budgets are deliberately generous — reasoning models (DeepSeek/MiniMax)
	 * spend most of the budget on hidden reasoning and return EMPTY content if it's
	 * too low, so even a one-line field needs a few thousand tokens of headroom.
	 */
	protected function shape(Field $f): array {
		$n = strtolower($f->name . ' ' . $f->getLabel());
		if(preg_match('/(meta|seo|description)/', $n)) return ['Write one compelling sentence, max 155 characters.', 2000];
		if(preg_match('/(summary|tagline|excerpt|intro|lead)/', $n)) return ['Write one vivid sentence.', 2000];
		if(preg_match('/(body|content|about|story|details|text)/', $n)) return ['Write 2-3 short paragraphs, separated by a blank line.', 3500];
		return ['Write one concise, specific sentence.', 2000];
	}
}
