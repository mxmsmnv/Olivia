<?php namespace ProcessWire;

/**
 * OliviaStore
 *
 * Persists build manifests as JSON files under site/assets/Olivia/builds/.
 * Each build gets one file named by its timestamp id, so builds can be listed
 * and rolled back later (Undo).
 */
class OliviaStore extends Wire {

	protected const TMP_TTL = 3600;
	public const MAX_FILE_BYTES = 16 * 1024 * 1024;

	protected $dir;
	protected static $tempsCleaned = false;

	public function __construct() {
		parent::__construct();
	}

	public function wired() {
		$this->dir = $this->wire->config->paths->assets . 'Olivia/builds/';
		if(!is_dir($this->dir)) $this->wire->files->mkdir($this->dir, true);
		if(!self::$tempsCleaned) {
			$this->cleanupStaleTemps();
			self::$tempsCleaned = true;
		}
		parent::wired();
	}

	/** Save a manifest, return its id. */
	public function save(array $manifest): string {
		$ts = microtime(true);
		$id = $this->newId($ts);
		$manifest['id'] = $id;
		$manifest['ts'] = $ts;
		$this->writeJsonFile(
			$this->dir . $id . '.json',
			$manifest,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
		);
		return $id;
	}

	/** Load one manifest by id, or null. */
	public function load(string $id): ?array {
		$id = $this->wire->sanitizer->filename($id);
		if(!$this->isValidId($id)) return null;
		$file = $this->dir . $id . '.json';
		if(!$this->isReadableJsonFile($file)) return null;
		$raw = @file_get_contents($file);
		if(!is_string($raw)) return null;
		$data = json_decode($raw, true);
		if(is_array($data)) $data['id'] = $id;
		return is_array($data) ? $data : null;
	}

	/** Delete a stored manifest (e.g. after Undo). */
	public function delete(string $id): bool {
		$id = $this->wire->sanitizer->filename($id);
		if(!$this->isValidId($id)) return false;
		$file = $this->dir . $id . '.json';
		$deleted = is_file($file) ? $this->wire->files->unlink($file) : false;
		$this->deleteTempsForId($id);
		return $deleted;
	}

	/** List manifests, newest first. */
	public function all(): array {
		$out = [];
		$files = glob($this->dir . '*.json');
		if(!is_array($files)) return $out;
		foreach($files as $file) {
			$id = basename((string) $file, '.json');
			if(!$this->isValidId($id)) continue;
			if(!$this->isReadableJsonFile($file)) continue;
			$raw = @file_get_contents($file);
			if(!is_string($raw)) continue;
			$data = json_decode($raw, true);
			if(is_array($data)) {
				$data['id'] = $id;
				if(isset($data['updated_files']) && is_array($data['updated_files'])) {
					$data['updated_files_count'] = count($data['updated_files']);
					unset($data['updated_files']); // history list does not need full file bodies
				}
				$out[] = $data;
			}
		}
		usort($out, fn($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));
		return $out;
	}

	public function cleanupStaleTemps(): void {
		$files = glob($this->dir . '*.json.*.tmp');
		if(!is_array($files)) return;
		$cutoff = time() - self::TMP_TTL;
		foreach($files as $file) {
			$name = basename((string) $file);
			if(!$this->isTempName($name)) continue;
			$mtime = @filemtime($file);
			if($mtime !== false && $mtime < $cutoff) @unlink($file);
		}
	}

	protected function newId(?float $ts = null): string {
		$ts = $ts ?? microtime(true);
		$millis = min(999, max(0, (int) floor(($ts - floor($ts)) * 1000)));
		return date('Ymd-His', (int) $ts) . sprintf('-%03d', $millis) . '-' . substr(md5(uniqid('', true)), 0, 4);
	}

	protected function writeJsonFile(string $file, array $data, int $flags): void {
		$json = json_encode($data, $flags);
		if(!is_string($json)) throw new \RuntimeException('Could not encode Olivia manifest JSON');
		if(strlen($json) > self::MAX_FILE_BYTES) throw new \RuntimeException('Olivia manifest JSON exceeds the 16 MB limit');
		$tmp = $file . '.' . getmypid() . '.' . str_replace('.', '', uniqid('', true)) . '.tmp';
		try {
			$this->wire->files->filePutContents($tmp, $json);
			if(!@rename($tmp, $file)) throw new \RuntimeException('Could not replace Olivia manifest JSON');
		} finally {
			if(is_file($tmp)) @unlink($tmp);
		}
	}

	protected function isReadableJsonFile(string $file): bool {
		if(!is_file($file)) return false;
		$size = @filesize($file);
		return $size !== false && $size >= 2 && $size <= self::MAX_FILE_BYTES;
	}

	protected function isTempName(string $name): bool {
		return (bool) preg_match('/^\d{8}-\d{6}-\d{3}-[a-f0-9]{4}\.json\.\d+\.[a-f0-9]+\.tmp$/', $name);
	}

	protected function isValidId(string $id): bool {
		return (bool) preg_match('/^\d{8}-\d{6}-\d{3}-[a-f0-9]{4}$/', $id);
	}

	protected function deleteTempsForId(string $id): void {
		$files = glob($this->dir . $id . '.json.*.tmp');
		if(!is_array($files)) return;
		foreach($files as $file) {
			$name = basename((string) $file);
			if($this->isTempName($name)) @unlink($file);
		}
	}
}
