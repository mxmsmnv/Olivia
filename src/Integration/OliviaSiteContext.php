<?php namespace ProcessWire;

/**
 * OliviaSiteContext — compact snapshot of the CURRENT site for Change mode.
 *
 * Gives the planner the real templates (with their fields) and the page tree so
 * a change plan references existing names exactly and extends rather than recreates.
 * Structure only — no field values / content. (Later this can defer to the
 * Context module if installed.)
 */
class OliviaSiteContext extends Wire {

	const MAX_PAGES = 60;

	/** Human/AI-readable summary of the current site structure. */
	public function summary(): string {
		$lines = [];

		// Templates with their fields (skip system templates)
		$lines[] = 'TEMPLATES (name: fields):';
		foreach($this->wire->templates as $t) {
			if($t->flags & Template::flagSystem) continue;
			if(in_array($t->name, ['admin', 'user', 'role', 'permission', 'language'], true)) continue;
			$fields = [];
			foreach($t->fieldgroup as $f) {
				if($f->name === 'title') continue;
				$fields[] = $f->name . ':' . $this->shortType((string) $f->type);
			}
			$lines[] = '- ' . $t->name . ($fields ? ' [' . implode(', ', $fields) . ']' : ' [title only]');
		}

		// Page tree (top levels, capped)
		$lines[] = '';
		$lines[] = 'PAGES (title [template] /path/):';
		$count = 0;
		$skip = [$this->wire->config->trashPageID, $this->wire->config->http404PageID];
		foreach($this->wire->pages->get('/')->children('include=hidden') as $p) {
			if($p->template->name === 'admin' || in_array($p->id, $skip, true)) continue;
			$count += $this->renderPage($p, $lines, 0, $count);
			if($count >= self::MAX_PAGES) { $lines[] = '  …(more)'; break; }
		}
		// include home itself
		$home = $this->wire->pages->get('/');
		array_splice($lines, array_search('PAGES (title [template] /path/):', $lines) + 1, 0,
			['- ' . $home->title . ' [' . $home->template->name . '] / (home)']);

		return implode("\n", $lines);
	}

	protected function renderPage(Page $p, array &$lines, int $depth, int $count): int {
		$lines[] = str_repeat('  ', $depth + 1) . '- ' . $p->title . ' [' . $p->template->name . '] ' . $p->path;
		$n = 1;
		if($depth < 2) {
			foreach($p->children('include=hidden') as $c) {
				if($count + $n >= self::MAX_PAGES) break;
				$n += $this->renderPage($c, $lines, $depth + 1, $count + $n);
			}
		}
		return $n;
	}

	protected function shortType(string $fieldtype): string {
		$t = str_replace('Fieldtype', '', $fieldtype);
		return strtolower($t);
	}

	/** True if the site has real (non-system) content worth "changing". */
	public function hasContent(): bool {
		foreach($this->wire->templates as $t) {
			if($t->flags & Template::flagSystem) continue;
			if(in_array($t->name, ['admin', 'user', 'role', 'permission', 'language', 'home', 'basic-page'], true)) continue;
			return true;
		}
		return false;
	}
}
