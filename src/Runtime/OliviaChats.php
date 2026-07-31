<?php namespace ProcessWire;

/**
 * OliviaChats
 *
 * File-backed chat threads for the Olivia admin UI. Threads keep the latest
 * prompt/plan plus a compact message timeline so work can be resumed later.
 */
class OliviaChats extends Wire {

	protected const TMP_TTL = 3600;
	public const MAX_FILE_BYTES = 4 * 1024 * 1024;

	protected $dir;
	protected static $tempsCleaned = false;

	public function wired() {
		$this->dir = $this->wire->config->paths->assets . 'Olivia/chats/';
		if(!is_dir($this->dir)) $this->wire->files->mkdir($this->dir, true);
		if(!self::$tempsCleaned) {
			$this->cleanupStaleTemps();
			self::$tempsCleaned = true;
		}
		parent::wired();
	}

	public function create(string $prompt = '', string $mode = 'direct'): array {
		$ts = microtime(true);
		$id = $this->newId($ts);
		$thread = [
			'id' => $id,
			'title' => $this->titleFromPrompt($prompt),
			'mode' => $mode ?: 'direct',
			'prompt' => $prompt,
			'planJson' => '',
			'messages' => [],
			'created' => date('c'),
			'updated' => date('c'),
			'ts' => $ts,
		];
		$this->save($thread);
		return $thread;
	}

	public function ensure(?string $id, string $prompt = '', string $mode = 'direct'): array {
		$thread = $id ? $this->load($id) : null;
		return $thread ?: $this->create($prompt, $mode);
	}

	public function load(string $id): ?array {
		$id = $this->wire->sanitizer->filename($id);
		if(!$this->isValidId($id)) return null;
		$file = $this->dir . $id . '.json';
		if(!$this->isReadableJsonFile($file)) return null;
		$raw = @file_get_contents($file);
		if(!is_string($raw)) return null;
		$data = json_decode($raw, true);
		if(is_array($data)) $data['id'] = $id;
		return is_array($data) ? $this->normalize($data) : null;
	}

	public function save(array $thread): void {
		$thread = $this->normalize($thread);
		$thread['updated'] = date('c');
		$thread['updatedTs'] = microtime(true);
		$id = $this->wire->sanitizer->filename((string) $thread['id']);
		if(!$this->isValidId($id)) throw new \RuntimeException('Invalid Olivia chat id');
		$this->writeJsonFile(
			$this->dir . $id . '.json',
			$thread,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
		);
	}

	public function updateState(string $id, array $state): ?array {
		$thread = $this->load($id);
		if(!$thread) return null;
		foreach(['prompt', 'mode', 'planJson'] as $key) {
			if(array_key_exists($key, $state)) $thread[$key] = (string) $state[$key];
		}
		if(!empty($state['title'])) $thread['title'] = (string) $state['title'];
		if(($thread['title'] ?? '') === 'New chat' && trim((string)($thread['prompt'] ?? '')) !== '') {
			$thread['title'] = $this->titleFromPrompt((string) $thread['prompt']);
		}
		$this->save($thread);
		return $thread;
	}

	public function append(string $id, string $role, string $type, string $text, array $meta = []): ?array {
		$thread = $this->load($id);
		if(!$thread) return null;
		$thread['messages'][] = [
			'role' => $role,
			'type' => $type,
			'text' => $this->trimText($text, 12000),
			'meta' => $meta,
			'created' => date('c'),
		];
		// Only actual prompt events seed resumable composer state. Workflow events
		// such as build_request and answers must not become the chat title/draft.
		if($role === 'user'
			&& in_array($type, ['prompt', 'audit_request'], true)
			&& trim((string)($thread['prompt'] ?? '')) === '') {
			$thread['prompt'] = $text;
			$thread['title'] = $this->titleFromPrompt($text);
		}
		$this->save($thread);
		return $thread;
	}

	public function delete(string $id): bool {
		$id = $this->wire->sanitizer->filename($id);
		if(!$this->isValidId($id)) return false;
		$file = $this->dir . $id . '.json';
		$deleted = is_file($file) ? $this->wire->files->unlink($file) : false;
		$this->deleteTempsForId($id);
		return $deleted;
	}

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
			if(!is_array($data)) continue;
			$data['id'] = $id;
			$data = $this->normalize($data);
			$data['message_count'] = count($data['messages']);
			unset($data['messages']);
			$out[] = $data;
		}
		usort($out, fn($a, $b) => ($b['updatedTs'] ?? $b['ts'] ?? 0) <=> ($a['updatedTs'] ?? $a['ts'] ?? 0));
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

	protected function normalize(array $thread): array {
		$thread['id'] = $this->wire->sanitizer->filename((string)($thread['id'] ?? ''));
		if($thread['id'] === '') $thread['id'] = $this->newId();
		$thread['title'] = (string)($thread['title'] ?? 'New chat');
		$thread['mode'] = (string)($thread['mode'] ?? 'direct');
		$thread['prompt'] = (string)($thread['prompt'] ?? '');
		$thread['planJson'] = (string)($thread['planJson'] ?? '');
		$thread['messages'] = is_array($thread['messages'] ?? null) ? $thread['messages'] : [];
		$thread['created'] = (string)($thread['created'] ?? date('c'));
		$thread['updated'] = (string)($thread['updated'] ?? $thread['created']);
		if(!isset($thread['ts'])) $thread['ts'] = strtotime($thread['created']) ?: microtime(true);
		if(!isset($thread['updatedTs'])) $thread['updatedTs'] = strtotime($thread['updated']) ?: ($thread['ts'] ?? microtime(true));
		return $thread;
	}

	protected function newId(?float $ts = null): string {
		$ts = $ts ?? microtime(true);
		$millis = min(999, max(0, (int) floor(($ts - floor($ts)) * 1000)));
		return date('Ymd-His', (int) $ts) . sprintf('-%03d', $millis) . '-' . substr(md5(uniqid('', true)), 0, 4);
	}

	protected function titleFromPrompt(string $prompt): string {
		$prompt = trim(preg_replace('/\s+/', ' ', $prompt));
		if($prompt === '') return 'New chat';
		if(function_exists('mb_substr')) return mb_substr($prompt, 0, 56) . (mb_strlen($prompt) > 56 ? '...' : '');
		return substr($prompt, 0, 56) . (strlen($prompt) > 56 ? '...' : '');
	}

	protected function trimText(string $text, int $limit): string {
		if(function_exists('mb_strlen') && mb_strlen($text) > $limit) return mb_substr($text, 0, $limit) . "\n...";
		if(strlen($text) > $limit) return substr($text, 0, $limit) . "\n...";
		return $text;
	}

	protected function writeJsonFile(string $file, array $data, int $flags): void {
		$json = json_encode($data, $flags);
		if(!is_string($json)) throw new \RuntimeException('Could not encode Olivia chat JSON');
		if(strlen($json) > self::MAX_FILE_BYTES) throw new \RuntimeException('Olivia chat JSON exceeds the 4 MB limit');
		$tmp = $file . '.' . getmypid() . '.' . str_replace('.', '', uniqid('', true)) . '.tmp';
		try {
			$this->wire->files->filePutContents($tmp, $json);
			if(!@rename($tmp, $file)) throw new \RuntimeException('Could not replace Olivia chat JSON');
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
