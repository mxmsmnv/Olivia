<?php namespace ProcessWire;

/**
 * OliviaModules — module discovery & trust
 *
 * Turns the plan's "modules" recommendations into useful, explained suggestions:
 * is it already installed? does it exist in the ProcessWire directory? when was
 * it last updated? what's its trust level? Plus a directory link.
 *
 * It deliberately does NOT install anything — installing third-party code is a
 * user decision. Olivia recommends + explains; the user installs via the normal
 * ProcessWire Modules screen.
 */
class OliviaModules extends Wire {

	const INDEX_URL = 'https://modules.processwire.com/export-json/?apikey=pw223';
	const CACHE_HOURS = 24;
	public const MAX_ARCHIVE_BYTES = 25 * 1024 * 1024;
	public const MAX_ARCHIVE_FILES = 5000;
	public const MAX_EXTRACTED_BYTES = 100 * 1024 * 1024;
	protected const TMP_TTL = 3600;
	protected static $tempsCleaned = false;

	public function wired() {
		if(!self::$tempsCleaned) {
			$this->cleanupStaleTemps();
			self::$tempsCleaned = true;
		}
		parent::wired();
	}

	/**
	 * Explain a plan's recommended modules. Accepts either plain class-name strings
	 * or {class, purpose} objects (the planner now attaches a plain-language purpose);
	 * unknown purposes fall back to the directory summary.
	 *
	 * @param array $modules class names or {class,purpose} from a plan's "modules"
	 * @return array list of ['name','purpose','installed','available','title','summary','updated','trust','trustLabel','url']
	 */
	public function recommend(array $modules): array {
		$purposeOf = [];
		foreach($modules as $m) {
			if(is_array($m)) { $cls = trim((string)($m['class'] ?? $m['name'] ?? '')); $p = trim((string)($m['purpose'] ?? '')); }
			else { $cls = trim((string) $m); $p = ''; }
			if($cls !== '' && !isset($purposeOf[$cls])) $purposeOf[$cls] = $p;
		}
		if(!$purposeOf) return [];

		$index = $this->index();
		$out = [];

		foreach($purposeOf as $name => $purpose) {
			$installed = $this->wire->modules->isInstalled($name);
			$entry = $index[strtolower($name)] ?? null;
			$updated = $entry['modified'] ?? 0;

			list($trust, $trustLabel) = $this->trust($installed, $entry, $updated);

			$downloadUrl = $entry['download_url'] ?? '';
			$onDisk = is_dir($this->wire->config->paths->siteModules . $name);
			$out[] = [
				'name'        => $name,
				'purpose'     => $purpose !== '' ? $purpose : trim((string)($entry['summary'] ?? '')),
				'installed'   => $installed,
				'available'   => (bool) $entry,
				// installable unless we KNOW it can't be (in the directory but with no
				// download = commercial). Modules absent from the cache are treated as
				// installable — install() resolves the download URL on-demand from the feed.
				'installable' => !$installed && ($onDisk || $downloadUrl !== '' || !$entry),
				'title'       => ($entry && $entry['title'] !== '') ? $entry['title'] : $name,
				'summary'     => $entry['summary'] ?? '',
				'updated'     => $updated ? date('Y-m-d', $updated) : '',
				'trust'       => $trust,
				'trustLabel'  => $trustLabel,
				'agents'      => !empty($entry['agents']),
				'url'         => ($entry && !empty($entry['url'])) ? $entry['url'] : ('https://modules.processwire.com/modules/' . $this->wire->sanitizer->pageName($name, true) . '/'),
			];
		}
		return $out;
	}

	/** Trust level from install state, "Olivia Ready" (ships AGENTS.md), release state, recency. */
	protected function trust(bool $installed, ?array $entry, int $updated): array {
		if($installed) return ['installed', 'Installed'];
		if(!$entry) return ['unknown', 'Not in directory'];

		// a module that ships an AGENTS.md is built to be driven by Olivia — top trust
		if(!empty($entry['agents'])) return ['olivia_ready', '✦ Olivia Ready'];

		$state = strtolower((string)($entry['release_state'] ?? ''));
		$ageMonths = $updated ? (time() - $updated) / 2592000 : 999;

		if(in_array($state, ['alpha', 'beta', 'development'], true)) return ['caution', 'Pre-release'];
		if($ageMonths <= 12) return ['recommended', 'Recommended'];
		if($ageMonths <= 36) return ['stable_quiet', 'Stable but quiet'];
		return ['legacy', 'Legacy (old)'];
	}

	/** Derive the raw AGENTS.md URL from a GitHub archive download_url, or ''. */
	protected function agentsRawUrl(string $downloadUrl): string {
		if(!preg_match('#github\.com/([^/]+)/([^/]+)/archive/(?:refs/heads/|refs/tags/)?(.+)\.zip$#i', $downloadUrl, $m)) return '';
		return 'https://raw.githubusercontent.com/' . $m[1] . '/' . $m[2] . '/' . $m[3] . '/AGENTS.md';
	}

	/**
	 * Fetch a module's AGENTS.md body from its GitHub repo (via the directory feed
	 * → download_url → raw URL). Returns the markdown, or null. Lets Olivia LEARN an
	 * Olivia-Ready module from the ecosystem without installing it.
	 */
	public function fetchAgents(string $class): ?string {
		$entry = $this->fetchEntry($class);
		if(!$entry) return null;
		$url = $this->agentsRawUrl((string)($entry['download_url'] ?? ''));
		if($url === '') return null;
		try {
			$http = $this->wire(new WireHttp());
			$http->setTimeout(10);
			$body = $http->get($url);
			if($http->getHttpCode() !== 200) return null;
			$body = trim((string) $body);
			return $body !== '' ? $body : null;
		} catch(\Throwable $e) {
			return null;
		}
	}

	/** True if the module's GitHub repo ships a root AGENTS.md ("Olivia Ready"). */
	public function checkAgents(string $downloadUrl): bool {
		$url = $this->agentsRawUrl($downloadUrl);
		if($url === '') return false;
		try {
			$http = $this->wire(new WireHttp());
			$http->setTimeout(6);
			$body = $http->get($url);
			return $http->getHttpCode() === 200 && trim((string) $body) !== '';
		} catch(\Throwable $e) {
			return false;
		}
	}

	protected function indexFile(): string {
		return $this->wire->config->paths->cache . 'Olivia/modules-index.json';
	}

	/** True if the cached index is missing or older than CACHE_HOURS. */
	public function indexStale(): bool {
		$f = $this->indexFile();
		return !is_file($f) || (time() - filemtime($f)) >= self::CACHE_HOURS * 3600;
	}

	/**
	 * Build/refresh the directory index. SLOW (~80s, paginated) — call from CLI
	 * or a background worker, never inside a web request.
	 */
	public function refresh(): int {
		$map = $this->refreshIndex($this->indexFile());
		return count($map);
	}

	/**
	 * Module directory index keyed by lowercased class_name. Web-safe: returns
	 * the cache as-is (even if stale) and NEVER fetches synchronously, so the
	 * admin screen can't hang on a cold/expired cache. Refresh runs out-of-band.
	 */
	protected function index(): array {
		$file = $this->indexFile();
		if(is_file($file)) {
			$raw = @file_get_contents($file);
			if(!is_string($raw)) return [];
			$data = json_decode($raw, true);
			if(is_array($data)) return $data;
		}
		return [];
	}

	/**
	 * On-demand lookup of ONE module from the directory feed (fast, single request)
	 * so install can resolve a download URL without building the whole ~80s index.
	 * Returns a compact entry or null (commercial/not-tracked/network error).
	 */
	public function fetchEntry(string $class): ?array {
		$class = $this->wire->sanitizer->name($class);
		if($class === '') return null;
		try {
			$http = $this->wire(new WireHttp());
			$json = $http->get('https://modules.processwire.com/export-json/' . rawurlencode($class) . '/?apikey=pw223');
			$d = $json ? json_decode($json, true) : null;
			if(!is_array($d) || ($d['status'] ?? '') !== 'success' || empty($d['class_name'])) return null;
			return [
				'class'         => (string) $d['class_name'],
				'title'         => trim((string)($d['title'] ?? '')),
				'summary'       => trim((string)($d['summary'] ?? '')),
				'modified'      => (int)($d['modified'] ?? 0),
				'release_state' => is_array($d['release_state'] ?? null) ? ($d['release_state']['name'] ?? '') : ($d['release_state'] ?? ''),
				'url'           => (string)($d['url'] ?? ''),
				'download_url'  => (string)($d['download_url'] ?? ''),
				'agents'        => $this->checkAgents((string)($d['download_url'] ?? '')),
			];
		} catch(\Throwable $e) {
			return null;
		}
	}

	/** Fetch the directory (paginated), build a compact class_name => meta map, cache it. */
	protected function refreshIndex(string $file): array {
		$map = [];
		try {
			$http = $this->wire(new WireHttp());
			$url = self::INDEX_URL;
			$guard = 0;
			while($url && $guard++ < 120) {
				// pagination URLs drop the apikey; re-add it or the page returns nothing
				if(strpos($url, 'apikey=') === false) $url .= (strpos($url, '?') === false ? '?' : '&') . 'apikey=pw223';
				$json = $http->get($url);
				$data = $json ? json_decode($json, true) : null;
				if(!is_array($data) || empty($data['items'])) break;
				foreach($data['items'] as $it) {
					$cn = $it['class_name'] ?? '';
					if(!$cn) continue;
					$map[strtolower($cn)] = [
						'class'         => $cn,
						'title'         => trim((string)($it['title'] ?? '')),
						'summary'       => trim((string)($it['summary'] ?? '')),
						'modified'      => (int)($it['modified'] ?? 0),
						'release_state' => is_array($it['release_state'] ?? null) ? ($it['release_state']['name'] ?? '') : ($it['release_state'] ?? ''),
						'url'           => (string)($it['url'] ?? ''),
						'download_url'  => (string)($it['download_url'] ?? ''),
					];
				}
				$url = $data['next_pagination_url'] ?? '';
			}
			// trust enrichment: follow each module's GitHub repo and flag the ones
			// that ship an AGENTS.md ("Olivia Ready"). Slow (one request per repo) —
			// fine here because refreshIndex only runs out-of-band (CLI/background).
			foreach($map as $k => $entry) {
				$map[$k]['agents'] = $this->checkAgents((string)($entry['download_url'] ?? ''));
			}
		} catch(\Throwable $e) {
			// network/dir unavailable — return whatever we have
		}
		if($map) {
			$dir = dirname($file);
			if(!is_dir($dir)) $this->wire->files->mkdir($dir, true);
			$this->writeJsonFile($file, $map);
		}
		return $map;
	}

	protected function writeJsonFile(string $file, array $data): void {
		$json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
		if(!is_string($json)) throw new \RuntimeException('Could not encode Olivia module index JSON');
		$tmp = $file . '.' . getmypid() . '.' . str_replace('.', '', uniqid('', true)) . '.tmp';
		try {
			$this->wire->files->filePutContents($tmp, $json);
			if(!@rename($tmp, $file)) throw new \RuntimeException('Could not replace Olivia module index JSON');
		} finally {
			if(is_file($tmp)) @unlink($tmp);
		}
	}

	public function cleanupStaleTemps(): void {
		$files = glob($this->indexFile() . '.*.tmp');
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
		return (bool) preg_match('/^modules-index\.json\.\d+\.[a-f0-9]+\.tmp$/', $name);
	}

	/**
	 * Download (from the official PW directory) and install a module by class name.
	 * Only modules with a directory download_url can be auto-installed; commercial
	 * or directory-less modules return an error telling the user to install manually.
	 * This runs third-party code (install hooks) — call it only on explicit user action.
	 *
	 * @return array ['ok'=>bool, 'message'=>string]
	 */
	public function install(string $class): array {
		$class = $this->wire->sanitizer->name($class);
		if($class === '') return ['ok' => false, 'message' => 'No module specified.'];
		$modules = $this->wire->modules;

		if($modules->isInstalled($class)) return ['ok' => true, 'message' => "$class is already installed."];

		// already present on disk but not installed?
		$modules->refresh();
		if($modules->isInstallable($class)) {
			$m = $modules->install($class);
			return $m ? ['ok' => true, 'message' => "Installed $class."] : ['ok' => false, 'message' => "Could not install $class."];
		}
		$target = $this->wire->config->paths->siteModules . $class . '/';
		if(is_dir($target)) {
			return ['ok' => false, 'message' => "$class already has files on disk but is not installable; inspect or replace them manually."];
		}

		$entry = $this->index()[strtolower($class)] ?? null;
		$dl = $entry['download_url'] ?? '';
		// not in the cached index (or no URL there): ask the directory feed directly
		if($dl === '') {
			$fetched = $this->fetchEntry($class);
			if($fetched && $fetched['download_url'] !== '') $dl = $fetched['download_url'];
		}
		if($dl === '') return ['ok' => false, 'message' => "$class has no directory download (commercial or not listed) — install it manually."];

		$tmp = $this->wire->config->paths->cache . 'Olivia/dl/';
		if(!is_dir($tmp)) $this->wire->files->mkdir($tmp, true);
		$zip = $tmp . $class . '.zip';
		$extract = $tmp . $class . '_x/';

		try {
			$http = $this->wire(new WireHttp());
			if($http->download($dl, $zip) === false) return ['ok' => false, 'message' => "Download failed for $class."];
			$archiveError = $this->archiveError($zip);
			if($archiveError !== '') return ['ok' => false, 'message' => "Unsafe archive for $class: $archiveError"];
			if(is_dir($extract)) $this->wire->files->rmdir($extract, true);
			$this->wire->files->unzip($zip, $extract);

			$src = $this->locateModuleDir($extract, $class);
			if(!$src) return ['ok' => false, 'message' => "Could not find {$class}.module in the downloaded archive."];

			if(!is_dir($target)) $this->wire->files->mkdir($target, true);
			$this->wire->files->copy($src, $target);
		} catch(\Throwable $e) {
			return ['ok' => false, 'message' => "Install failed for $class: " . $e->getMessage()];
		} finally {
			if(is_dir($extract)) $this->wire->files->rmdir($extract, true);
			if(is_file($zip)) @unlink($zip);
		}

		$modules->refresh();
		if(!$modules->isInstallable($class)) return ['ok' => false, 'message' => "$class downloaded but not installable (check requirements)."];
		$m = $modules->install($class);
		return $m ? ['ok' => true, 'message' => "Downloaded & installed $class."] : ['ok' => false, 'message' => "Files placed but install() failed for $class."];
	}

	/** Return an archive rejection reason, or an empty string when safe to extract. */
	protected function archiveError(string $zipFile): string {
		$size = @filesize($zipFile);
		if($size === false || $size <= 0) return 'download is empty';
		if($size > self::MAX_ARCHIVE_BYTES) return 'download exceeds 25 MB';
		if(!class_exists('ZipArchive')) return 'PHP ZipArchive is unavailable';

		$zip = new \ZipArchive();
		if($zip->open($zipFile) !== true) return 'download is not a valid ZIP file';
		try {
			if($zip->numFiles > self::MAX_ARCHIVE_FILES) return 'archive contains too many files';
			$total = 0;
			for($i = 0; $i < $zip->numFiles; $i++) {
				$stat = $zip->statIndex($i);
				if(!is_array($stat)) return 'archive entry metadata is invalid';
				$name = str_replace('\\', '/', (string)($stat['name'] ?? ''));
				if($name === '' || strpos($name, "\0") !== false) return 'archive contains an invalid path';
				if($name[0] === '/' || preg_match('/^[A-Za-z]:\//', $name)) return 'archive contains an absolute path';
				foreach(explode('/', $name) as $part) {
					if($part === '..') return 'archive path escapes its extraction directory';
				}
				$opsys = 0;
				$attributes = 0;
				if($zip->getExternalAttributesIndex($i, $opsys, $attributes)) {
					$mode = ($attributes >> 16) & 0170000;
					if($mode === 0120000) return 'archive contains a symbolic link';
				}
				$entrySize = (int)($stat['size'] ?? 0);
				if($entrySize < 0) return 'archive contains an invalid file size';
				$total += $entrySize;
				if($total > self::MAX_EXTRACTED_BYTES) return 'expanded archive exceeds 100 MB';
			}
		} finally {
			$zip->close();
		}
		return '';
	}

	/** Find the dir inside an extracted archive that contains <class>.module(.php). */
	protected function locateModuleDir(string $base, string $class): ?string {
		foreach([$class . '.module.php', $class . '.module'] as $fn) {
			if(is_file($base . $fn)) return $base;
		}
		foreach(glob($base . '*', GLOB_ONLYDIR) as $dir) {
			foreach([$class . '.module.php', $class . '.module'] as $fn) {
				if(is_file($dir . '/' . $fn)) return $dir . '/';
			}
		}
		return null;
	}
}
