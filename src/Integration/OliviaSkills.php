<?php namespace ProcessWire;

/**
 * OliviaSkills — Olivia's skills library
 *
 * Olivia keeps a `skills/` folder inside the module. The most important skills
 * are MODULE skills: how and when to use a given ProcessWire module. They are
 * recorded from each installed module's own AGENTS.md (the emerging AI-manifest
 * standard), so Olivia learns the ecosystem and can assemble sites that reuse
 * the right modules — e.g. "SEO → Ichiban", "reviews → Vox".
 *
 * Layout (inside the module):
 *   skills/
 *     modules/<ClassName>.md   ← recorded per-module skill
 *
 * Recorded files are plain markdown with a small frontmatter header, so they're
 * readable, editable, and can be fed into Olivia's planning prompt later.
 */
class OliviaSkills extends Wire {

	/** Files (in a module dir) Olivia treats as a skill source, best first. */
	protected $sourceFiles = ['AGENTS.md', 'API.md', 'EXAMPLES.md', 'README.md'];

	const MAX_CHARS = 6000; // keep recorded skills compact
	protected const TMP_TTL = 3600;
	protected static $tempsCleaned = false;

	public function wired() {
		if(!self::$tempsCleaned) {
			$this->cleanupStaleTemps();
			self::$tempsCleaned = true;
		}
		parent::wired();
	}

	protected function dir(): string {
		// this file is in <module>/src/Integration/, skills live in <module>/skills/modules/
		$dir = dirname(__DIR__, 2) . '/skills/modules/';
		if(!is_dir($dir)) $this->wire->files->mkdir($dir, true);
		return $dir;
	}

	/**
	 * Scan installed site modules and record a skill for each that ships an
	 * AGENTS.md (or other source doc). Returns the class names recorded.
	 */
	public function collectInstalled(): array {
		$recorded = [];
		$base = $this->wire->config->paths->siteModules;
		foreach(new \DirectoryIterator($base) as $entry) {
			if($entry->isDot() || !$entry->isDir()) continue;
			$class = $entry->getFilename();
			if($class === 'Olivia' || $class === 'ProcessOlivia') continue; // don't record ourselves
			if(!$this->wire->modules->isInstalled($class)) continue;
			if($this->record($class)) $recorded[] = $class;
		}
		// prune skills only for modules whose folder is gone — keep skills for any
		// module still present in site/modules/ (installed or not), and keep
		// remotely-learned skills (fetched from a repo's AGENTS.md, no local folder)
		foreach(glob($this->dir() . '*.md') as $file) {
			$class = basename($file, '.md');
			if(in_array($class, $recorded, true) || is_dir($base . $class)) continue;
			$raw = @file_get_contents($file);
			$head = $this->frontmatter(is_string($raw) ? $raw : '');
			if(($head['remote'] ?? '') === 'true') continue;
			@unlink($file);
		}
		return $recorded;
	}

	/**
	 * Record (or refresh) the skill for one module from its source docs.
	 * @return bool true if a skill was written
	 */
	public function record(string $class): bool {
		$class = $this->normalizeClassName($class);
		if($class === '') return false;
		$modDir = $this->wire->config->paths->siteModules . $class . '/';
		if(!is_dir($modDir)) return false;

		$srcFile = '';
		foreach($this->sourceFiles as $f) {
			if(is_file($modDir . $f)) { $srcFile = $modDir . $f; break; }
		}
		// module title/summary: prefer PW's known info, else parse the .module file
		// directly (works for modules present on disk but not installed).
		$info = $this->wire->modules->getModuleInfoVerbose($class) ?: [];
		$summary = trim((string)($info['summary'] ?? ''));
		$title   = trim((string)($info['title'] ?? ''));
		if($summary === '' || $title === '') {
			list($pt, $ps) = $this->parseModuleInfoFile($modDir, $class);
			if($title === '')   $title = $pt;
			if($summary === '') $summary = $ps;
		}
		if($title === '') $title = $class;

		$raw = $srcFile ? @file_get_contents($srcFile) : '';
		$body = is_string($raw) ? trim($raw) : '';
		// enrich with a richer docs file when the module ships one
		foreach(['docs/FUNCTIONALITY.md', 'docs/USAGE.md', 'docs/API.md', 'docs/INTEGRATION.md'] as $d) {
			if(is_file($modDir . $d)) {
				$extra = @file_get_contents($modDir . $d);
				if(is_string($extra)) $body .= "\n\n## " . basename($d) . "\n\n" . trim($extra);
				break;
			}
		}
		if($body === '' && $summary === '') return false;
		if(mb_strlen($body) > self::MAX_CHARS) $body = mb_substr($body, 0, self::MAX_CHARS) . "\n\n…(truncated)";

		$source = $srcFile ? basename($srcFile) : 'module info';

		$md = "---\n"
			. "module: {$class}\n"
			. "title: " . str_replace("\n", ' ', $title) . "\n"
			. "summary: " . str_replace("\n", ' ', $summary) . "\n"
			. "source: {$source}\n"
			. "recorded: " . date('c') . "\n"
			. "---\n\n"
			. ($body !== '' ? $body : "_No AGENTS.md found. Summary only._");

		$this->writeTextFile($this->dir() . $class . '.md', $md);
		return true;
	}

	/**
	 * Parse title + summary from a module file's static getModuleInfo() WITHOUT
	 * loading/executing the module — a plain regex over the source. Returns
	 * [title, summary] (either may be '').
	 */
	protected function parseModuleInfoFile(string $modDir, string $class): array {
		$file = '';
		foreach([$class . '.module.php', $class . '.module'] as $f) {
			if(is_file($modDir . $f)) { $file = $modDir . $f; break; }
		}
		if($file === '') return ['', ''];
		$src = (string) @file_get_contents($file);
		$grab = function($key) use ($src) {
			return preg_match('/[\'"]' . $key . '[\'"]\s*=>\s*[\'"](.*?)[\'"]/s', $src, $m) ? trim($m[1]) : '';
		};
		return [$grab('title'), $grab('summary')];
	}

	/**
	 * Record a skill from an AGENTS.md fetched remotely (a directory module's repo),
	 * so Olivia can learn an "Olivia Ready" module without installing it. Marked
	 * remote:true so it survives skill refreshes (no local folder to anchor it).
	 */
	public function recordRemote(string $class, string $body, array $meta = []): bool {
		$class = $this->normalizeClassName($class);
		$body = trim($body);
		if($class === '' || $body === '') return false;
		if(mb_strlen($body) > self::MAX_CHARS) $body = mb_substr($body, 0, self::MAX_CHARS) . "\n\n…(truncated)";
		$title = trim((string)($meta['title'] ?? '')) ?: $class;
		$summary = trim((string)($meta['summary'] ?? ''));
		$md = "---\n"
			. "module: {$class}\n"
			. "title: " . str_replace("\n", ' ', $title) . "\n"
			. "summary: " . str_replace("\n", ' ', $summary) . "\n"
			. "source: github:AGENTS.md\n"
			. "remote: true\n"
			. "recorded: " . date('c') . "\n"
			. "---\n\n" . $body;
		$this->writeTextFile($this->dir() . $class . '.md', $md);
		return true;
	}

	/** List recorded module skills: class => ['title','summary','source','chars']. */
	public function all(): array {
		$out = [];
		foreach(glob($this->dir() . '*.md') as $file) {
			$class = basename($file, '.md');
			$raw = @file_get_contents($file);
			$head = $this->frontmatter(is_string($raw) ? $raw : '');
			$out[$class] = [
				'title'   => $head['title'] ?? $class,
				'summary' => $head['summary'] ?? '',
				'source'  => $head['source'] ?? '',
				'chars'   => filesize($file),
			];
		}
		ksort($out);
		return $out;
	}

	/**
	 * Compact one-line-per-module index for injecting into the planner prompt,
	 * so generation prefers modules that are actually available. Auto-collects
	 * from installed modules if nothing is recorded yet.
	 *
	 * @return string '' if no skills, else "- Class: summary" lines
	 */
	public function promptIndex(): string {
		$all = $this->all();
		if(!$all) { $this->collectInstalled(); $all = $this->all(); }
		if(!$all) return '';
		$lines = [];
		foreach($all as $class => $info) {
			$s = $info['summary'] !== '' ? ' — ' . $info['summary'] : '';
			$lines[] = "- {$class}{$s}";
		}
		return implode("\n", $lines);
	}

	/**
	 * Full usage docs for the recorded (installed) modules, for injecting into
	 * the planner when Olivia should actually integrate them. Each module's body
	 * is trimmed; total is capped to keep the prompt reasonable.
	 *
	 * @param int $perModuleChars max chars of each module's body
	 * @param int $maxModules max number of modules
	 * @return string '' if nothing recorded
	 */
	public function fullContext(int $perModuleChars = 2500, int $maxModules = 6): string {
		$all = $this->all();
		if(!$all) { $this->collectInstalled(); $all = $this->all(); }
		if(!$all) return '';
		$blocks = [];
		$n = 0;
		foreach($all as $class => $info) {
			if($n++ >= $maxModules) break;
			$body = $this->bodyOf($class);
			if($body === '') $body = $info['summary'] ?? '';
			if(mb_strlen($body) > $perModuleChars) $body = mb_substr($body, 0, $perModuleChars) . "\n…";
			$blocks[] = "### " . $class . "\n" . $body;
		}
		return implode("\n\n", $blocks);
	}

	/**
	 * Best-effort: the render method a view should call to output this module,
	 * parsed from the skill's "Public API" code block. Prefers a method that takes
	 * $page. Returns the method name (e.g. "renderReviews") or null.
	 */
	public function renderMethod(string $class): ?string {
		$body = $this->bodyOf($class);
		if($body === '') return null;
		// look inside fenced code blocks first
		$code = '';
		if(preg_match_all('/```[a-z]*\s*(.*?)```/s', $body, $m)) $code = implode("\n", $m[1]);
		$hay = $code !== '' ? $code : $body;
		// prefer a call that passes $page
		if(preg_match('/->\s*([a-zA-Z_]\w*)\s*\(\s*\$page/', $hay, $mm)) return $mm[1];
		// otherwise the first render*/get* style method call
		if(preg_match('/->\s*(render[A-Za-z]\w*|output[A-Za-z]\w*)\s*\(/', $hay, $mm)) return $mm[1];
		return null;
	}

	/** A recorded skill's markdown body (without frontmatter). */
	protected function bodyOf(string $class): string {
		$md = $this->read($class);
		if($md === null) return '';
		return trim(preg_replace('/^---\s*\n.*?\n---\s*\n/s', '', $md));
	}

	/** Read a recorded skill's full markdown, or null. */
	public function read(string $class): ?string {
		$class = $this->normalizeClassName($class);
		if($class === '') return null;
		$file = $this->dir() . $class . '.md';
		if(!is_file($file)) return null;
		$raw = @file_get_contents($file);
		return is_string($raw) ? $raw : null;
	}

	protected function writeTextFile(string $file, string $text): void {
		$tmp = $file . '.' . getmypid() . '.' . str_replace('.', '', uniqid('', true)) . '.tmp';
		try {
			$this->wire->files->filePutContents($tmp, $text);
			if(!@rename($tmp, $file)) throw new \RuntimeException('Could not replace Olivia skill markdown');
		} finally {
			if(is_file($tmp)) @unlink($tmp);
		}
	}

	public function cleanupStaleTemps(): void {
		$files = glob($this->dir() . '*.md.*.tmp');
		if(!is_array($files)) return;
		$cutoff = time() - self::TMP_TTL;
		foreach($files as $file) {
			$name = basename((string) $file);
			if(!$this->isTempName($name)) continue;
			$mtime = @filemtime($file);
			if($mtime !== false && $mtime < $cutoff) @unlink($file);
		}
	}

	protected function isTempName(string $name): bool {
		return (bool) preg_match('/^[A-Za-z0-9_]+\.md\.\d+\.[a-f0-9]+\.tmp$/', $name);
	}

	protected function normalizeClassName(string $class): string {
		$class = trim($class);
		return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class) ? $class : '';
	}

	protected function frontmatter(string $md): array {
		if(!preg_match('/^---\s*\n(.*?)\n---/s', $md, $m)) return [];
		$out = [];
		foreach(explode("\n", $m[1]) as $line) {
			if(strpos($line, ':') === false) continue;
			list($k, $v) = explode(':', $line, 2);
			$out[trim($k)] = trim($v);
		}
		return $out;
	}
}
