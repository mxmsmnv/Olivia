<?php namespace ProcessWire;

/**
 * OliviaAtlas — bridge between Olivia and the Atlas RAG store.
 *
 * After a build, indexes the created/reused pages' text into a per-site Atlas
 * collection so that copy generation (and later Q&A / Operate) can retrieve
 * relevant on-site context. Every method is GUARDED: it is a no-op (or returns
 * []) unless the Atlas module is installed AND ready (i.e. Squad has an
 * embedding-capable provider configured). So the build flow is completely
 * unaffected when RAG isn't set up.
 */
class OliviaAtlas extends Wire {

	const COLLECTION = 'olivia_site';

	/** Load the optional module without allowing a broken install to escape. */
	protected function atlasModule() {
		try {
			if(!$this->wire->modules->isInstalled('Atlas')) return null;
			return $this->wire->modules->get('Atlas') ?: null;
		} catch(\Throwable $e) {
			return null;
		}
	}

	/** The Atlas module if installed and ready, else null. */
	protected function atlas() {
		$r = $this->atlasModule();
		if(!$r || !method_exists($r, 'isReady')) return null;
		try {
			return $r->isReady() ? $r : null;
		} catch(\Throwable $e) {
			return null;
		}
	}

	/** True when Atlas is installed and has a working embedding provider via Squad. */
	public function isReady(): bool {
		$r = $this->atlas();
		if(!$r) return false;
		foreach(['deleteRef', 'addChunked', 'count', 'search'] as $method) {
			if(!method_exists($r, $method)) return false;
		}
		return true;
	}

	/**
	 * Index the pages from a build manifest into the RAG store.
	 * @return int number of pages indexed (0 when RAG isn't ready)
	 */
	public function indexBuild(array &$manifest, string $collection = self::COLLECTION): int {
		$r = $this->atlas();
		if (!$r) return 0;
		foreach(['addChunked'] as $method) {
			if(!method_exists($r, $method)) {
				$manifest['warnings'][] = "Atlas indexing skipped: incompatible module API (missing {$method}).";
				return 0;
			}
		}

		try {
			$pageIds = array_unique(array_merge($manifest['pages'] ?? [], $manifest['reused']['pages'] ?? []));
			$n = 0;
			foreach ($pageIds as $pid) {
				$p = $this->wire->pages->get((int) $pid);
				if (!$p->id) continue;
				$base = 'page-' . $p->id;
				$text = $this->pageText($p);
				if ($text === '') continue;
				$stored = (int) $r->addChunked($collection, $base, $text, [
					'id' => $p->id,
					'title' => (string) $p->title,
					'url' => $p->url,
				]);
				if($stored <= 0) {
					$error = method_exists($r, 'lastError') ? trim((string) $r->lastError()) : '';
					if($error !== '') $manifest['warnings'][] = 'Atlas indexing skipped for page ' . $p->id . ': ' . mb_substr($error, 0, 240);
					continue;
				}
				$n += $stored;
			}

			$manifest['atlas'] = $n; // number of chunks indexed
			return $n;
		} catch(\Throwable $e) {
			$manifest['warnings'][] = 'Atlas indexing skipped: ' . mb_substr($e->getMessage(), 0, 300);
			return 0;
		}
	}

	/**
	 * Retrieve the top-$topK most relevant on-site snippets for a query.
	 * @return array [['ref','text','meta','model','score'], ...] ([] when not ready)
	 */
	public function context(string $query, int $topK = 4, string $collection = self::COLLECTION, string $keyword = ''): array {
		$r = $this->atlas();
		if (!$r) return [];
		if(!method_exists($r, 'count') || !method_exists($r, 'search')) return [];
		try {
			if ($r->count($collection) <= 0) return []; // nothing indexed yet — skip the (paid) embed call
			// hybrid + MMR: keyword boosts literal matches; MMR keeps near-duplicate chunks out of the top
			$result = $r->search($collection, $query, $topK, ['keyword' => $keyword, 'mmr' => true]);
			return is_array($result) ? $result : [];
		} catch(\Throwable $e) {
			return [];
		}
	}

	/**
	 * Remove a build's created pages from the RAG store (called on rollback).
	 * Reused pages survive rollback, so keep their existing index entries.
	 * Only needs the module installed — deletion doesn't touch embeddings.
	 * @return int number of refs deleted
	 */
	public function removeBuild(array $manifest, string $collection = self::COLLECTION): int {
		$r = $this->atlasModule();
		if(!$r || !method_exists($r, 'deleteRef')) return 0;
		$ids = $manifest['pages'] ?? [];
		$n = 0;
		foreach (array_unique($ids) as $pid) {
			try {
				$r->deleteRef($collection, 'page-' . (int) $pid);
				$n++;
			} catch(\Throwable $e) {}
		}
		return $n;
	}

	/** Page title + plain text of common content fields, capped for embedding input. */
	protected function pageText(Page $p): string {
		$parts = [trim((string) $p->title)];
		foreach (['summary', 'headline', 'tagline', 'intro', 'body', 'content', 'description_field'] as $fn) {
			if ($p->template->fieldgroup->hasField($fn)) {
				$v = trim(strip_tags((string) $p->get($fn)));
				if ($v !== '') $parts[] = $v;
			}
		}
		$text = trim(implode("\n", array_filter($parts)));
		return mb_substr($text, 0, 20000); // chunked downstream, so allow long pages
	}
}
