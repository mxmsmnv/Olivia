<?php namespace ProcessWire;

/**
 * OliviaReferenceAnalyzer
 *
 * Turns a reference URL / uploaded screenshot into a compact planning addendum.
 * The AI model does not browse the web; Olivia fetches best-effort context first
 * and passes only a short, non-copying brief into the planner.
 */
class OliviaReferenceAnalyzer extends Wire {

	const MAX_PAGES = 5;
	const MAX_LINKS = 80;
	const MAX_URL_CHARS = 2048;
	const MAX_TEXT_CHARS = 9000;
	const MAX_NOTES_CHARS = 3000;
	const MAX_MESSAGE_CHARS = 240;
	const MAX_IMAGE_BYTES = 8388608; // 8 MB
	const MAX_IMAGES = 4;
	const MAX_IMAGE_DIMENSION = 12000;
	const MAX_IMAGE_PIXELS = 32000000;
	const REFERENCE_TTL = 7 * 86400;
	const MAX_CLEANUP_FILES = 100;
	const SKIP_LINK_EXTENSIONS = 'pdf|zip|jpg|jpeg|png|webp|gif|svg|avif|ico|css|js|mp4|mov|webm|mp3|wav';
	const FETCH_TIMEOUT = 12;
	const FETCH_CONNECT_TIMEOUT = 5;
	const FETCH_MAX_REDIRECTS = 3;
	const FETCH_HTML_BYTES = 800000;
	protected static bool $referencesCleaned = false;

	public function fromInput(WireInput $input): array {
		if(!self::$referencesCleaned) {
			$this->cleanupStaleReferences();
			self::$referencesCleaned = true;
		}
		$url = $this->limitText(trim((string) $input->post('olivia_reference_url')), self::MAX_URL_CHARS);
		$notes = $this->limitText(trim((string) $input->post('olivia_reference_notes')), self::MAX_NOTES_CHARS);
		$out = [
			'url' => $url,
			'notes' => $notes,
			'fetch' => null,
			'image' => null,
			'images' => [],
			'warnings' => [],
		];

		if($url !== '') $out['fetch'] = $this->fetchSite($url);
		$out['images'] = $this->saveUploadedImages('olivia_reference_image');
		if($out['images']) $out['image'] = $out['images'][0]; // backward-compatible summary/UI field

		if($out['fetch'] && empty($out['fetch']['ok'])) {
			$out['warnings'][] = $out['fetch']['message'] ?? 'Reference URL could not be read automatically.';
		}
		foreach($out['images'] as $image) if(empty($image['ok'])) $out['warnings'][] = $image['message'] ?? 'Reference screenshot could not be saved.';

		return $out;
	}

	/** Remove only old Olivia-owned reference uploads; never follow symlinks. */
	public function cleanupStaleReferences(?int $now = null): int {
		$dir = $this->wire->config->paths->assets . 'Olivia/references/';
		if(!is_dir($dir)) return 0;
		$cutoff = ($now ?? time()) - self::REFERENCE_TTL;
		$removed = 0;
		try { $items = new \DirectoryIterator($dir); }
		catch(\Throwable $e) { return 0; }
		foreach($items as $item) {
			if($removed >= self::MAX_CLEANUP_FILES) break;
			if($item->isDot() || $item->isLink() || !$item->isFile()) continue;
			$name = $item->getFilename();
			if(!preg_match('/^\d{8}-\d{6}-[a-f0-9]{10}(?:-\d{1,3})?\.(?:jpg|png|webp|gif)$/', $name)) continue;
			$mtime = $item->getMTime();
			if($mtime >= $cutoff) continue;
			if(@unlink($item->getPathname())) $removed++;
		}
		return $removed;
	}

	public function hasContext(array $ref): bool {
		return trim((string)($ref['url'] ?? '')) !== ''
			|| trim((string)($ref['notes'] ?? '')) !== ''
			|| !empty($ref['image']['ok'])
			|| !empty(array_filter((array)($ref['images'] ?? []), fn($image) => !empty($image['ok'])));
	}

	public function safeCaptureUrl(string $url): string {
		$url = $this->normalizeUrl($url);
		if($url === '' || $this->resolvesToPrivateReferenceAddress($url)) return '';
		return $url;
	}

	/** Validate and persist a binary PNG returned by a configured capture service. */
	public function saveCapturedImage(string $data): array {
		if($data === '' || strlen($data) > self::MAX_IMAGE_BYTES) return ['ok' => false, 'message' => 'Captured screenshot exceeds the byte limit.'];
		$info = @getimagesizefromstring($data);
		if(!is_array($info) || (string)($info['mime'] ?? '') !== 'image/png') return ['ok' => false, 'message' => 'Captured screenshot is not a valid PNG.'];
		if(!$this->validImageDimensions($info)) return ['ok' => false, 'message' => 'Captured screenshot dimensions exceed the safety limit.'];
		$dir = $this->wire->config->paths->assets . 'Olivia/references/';
		if(!is_dir($dir)) $this->wire->files->mkdir($dir, true);
		try { [$name, $dest] = $this->referenceUploadTarget($dir, substr(hash('sha256', $data), 0, 10), 'png'); }
		catch(\Throwable $e) { return ['ok' => false, 'message' => 'Could not allocate captured screenshot filename.']; }
		if($this->wire->files->filePutContents($dest, $data) !== strlen($data)) { @unlink($dest); return ['ok' => false, 'message' => 'Could not save captured screenshot.']; }
		return ['ok' => true, 'file' => $dest, 'url' => $this->wire->config->urls->assets . 'Olivia/references/' . $name, 'mime' => 'image/png', 'width' => (int)$info[0], 'height' => (int)$info[1]];
	}

	/** Minimal JSON-safe payload passed to the detached vision/planning worker. */
	public function workerReference(array $ref): array {
		$images = [];
		foreach((array)($ref['images'] ?? []) as $image) {
			if(empty($image['ok']) || empty($image['file'])) continue;
			$images[] = array_intersect_key($image, array_flip(['ok','file','mime','width','height']));
			if(count($images) >= self::MAX_IMAGES) break;
		}
		if(!$images && !empty($ref['image']['ok'])) $images[] = array_intersect_key($ref['image'], array_flip(['ok','file','mime','width','height']));
		return ['url' => $this->safeCaptureUrl((string)($ref['url'] ?? '')), 'notes' => mb_substr((string)($ref['notes'] ?? ''), 0, self::MAX_NOTES_CHARS), 'images' => $images];
	}

	public function augmentPrompt(string $prompt, array $ref): string {
		if(!$this->hasContext($ref)) return $prompt;

		$lines = [
			trim($prompt),
			'',
			'REFERENCE SITE CONTEXT — use this to infer the site category, structure, visual direction and content types.',
			'Do NOT copy names, text, images, branding, layout verbatim, or source code from the reference. Build an original site with a similar business archetype and quality bar.',
		];

		if(!empty($ref['url'])) $lines[] = 'Reference URL: ' . $ref['url'];
		if(!empty($ref['fetch']['ok'])) {
			$brief = trim((string)($ref['fetch']['brief'] ?? ''));
			if($brief !== '') $lines[] = "\nFetched reference brief:\n" . $brief;
		} elseif(!empty($ref['fetch'])) {
			$lines[] = 'Reference fetch status: unavailable (' . (string)($ref['fetch']['reason'] ?? 'unknown') . '). Rely on the user description and screenshot notes instead.';
		}

		$images = (array)($ref['images'] ?? []);
		if(!$images && !empty($ref['image'])) $images[] = $ref['image'];
		$validImages = array_values(array_filter($images, fn($image) => !empty($image['ok'])));
		if($validImages) {
			$meta = [];
			foreach(array_slice($validImages, 0, self::MAX_IMAGES) as $img) $meta[] = basename((string)$img['file']) . ' (' . (int)$img['width'] . 'x' . (int)$img['height'] . ')';
			$lines[] = 'Reference screenshots uploaded for background visual analysis: ' . implode(', ', $meta) . '.';
		}

		if(trim((string)($ref['notes'] ?? '')) !== '') {
			$lines[] = "\nUser notes about reference / screenshot:\n" . trim((string)$ref['notes']);
		}

		return implode("\n", $lines);
	}

	protected function fetchSite(string $url): array {
		$inputUrl = $url;
		$url = $this->normalizeUrl($url);
		if($url === '') return $this->invalidReferenceUrlResult($inputUrl);

		$seen = [];
		$queue = [$url];
		$pages = [];
		$blocked = null;

		while($queue && count($pages) < self::MAX_PAGES) {
			$current = array_shift($queue);
			if(isset($seen[$current])) continue;
			$seen[$current] = true;

			if($this->resolvesToPrivateReferenceAddress($current)) {
				$blocked = ['ok' => false, 'reason' => 'private_resolved_host', 'message' => 'Reference URL resolved to a private or reserved network address.'];
				continue;
			}

			$res = $this->fetchHtml($current);
			if(empty($res['ok'])) {
				if($blocked === null) $blocked = $res;
				continue;
			}

			$html = (string)$res['html'];
			if($this->looksBlocked($html, (int)$res['code'])) {
				$blocked = ['ok' => false, 'reason' => 'blocked_by_protection', 'message' => 'Reference site appears to be protected by CAPTCHA, Cloudflare, or bot filtering.'];
				continue;
			}

			$pageUrl = $this->fetchedUrl((string)($res['url'] ?? ''), $current) ?: $current;
			$parsed = $this->parseHtml($html, $pageUrl);
			$pages[] = $parsed;

			if(count($pages) === 1) {
				foreach($parsed['links'] as $link) {
					if(count($queue) + count($pages) >= self::MAX_PAGES) break;
					if(!isset($seen[$link])) $queue[] = $link;
				}
			}
		}

		if(!$pages) {
			return $blocked ?: ['ok' => false, 'reason' => 'empty', 'message' => 'Reference site could not be read automatically.'];
		}

		return [
			'ok' => true,
			'url' => $url,
			'pages' => count($pages),
			'brief' => $this->brief($pages),
		];
	}

	protected function normalizeUrl(string $url): string {
		$url = trim($url);
		if($url === '') return '';
		if(strlen($url) > self::MAX_URL_CHARS) return '';
		if(!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
		if(!filter_var($url, FILTER_VALIDATE_URL)) return '';
		$scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
		if(!in_array($scheme, ['http', 'https'], true)) return '';
		$url = $this->normalizeAbsoluteUrl($url);
		return strlen($url) <= self::MAX_URL_CHARS ? $url : '';
	}

	protected function invalidReferenceUrlResult(string $url): array {
		$url = trim($url);
		if($url === '') return ['ok' => false, 'reason' => 'invalid_url', 'message' => 'Reference URL is empty.'];
		if(strlen($url) > self::MAX_URL_CHARS) return ['ok' => false, 'reason' => 'url_too_long', 'message' => 'Reference URL is too long.'];
		if(!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
		$parts = parse_url($url);
		if(!is_array($parts)) return ['ok' => false, 'reason' => 'invalid_url', 'message' => 'Reference URL must be a valid http:// or https:// URL.'];
		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		if(!in_array($scheme, ['http', 'https'], true)) return ['ok' => false, 'reason' => 'unsupported_scheme', 'message' => 'Reference URL must use http:// or https://.'];
		if(isset($parts['user']) || isset($parts['pass'])) return ['ok' => false, 'reason' => 'url_credentials', 'message' => 'Reference URL cannot include username or password credentials.'];
		$host = (string)($parts['host'] ?? '');
		if($host === '' || !$this->isPublicReferenceHost($host)) return ['ok' => false, 'reason' => 'private_host', 'message' => 'Reference URL cannot use localhost, private, or reserved network hosts.'];
		$port = (int)($parts['port'] ?? 0);
		if($port > 0 && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
			return ['ok' => false, 'reason' => 'nonstandard_port', 'message' => 'Reference URL must use the standard HTTP/HTTPS ports.'];
		}
		if(!filter_var($url, FILTER_VALIDATE_URL)) return ['ok' => false, 'reason' => 'invalid_url', 'message' => 'Reference URL must be a valid http:// or https:// URL.'];
		return ['ok' => false, 'reason' => 'invalid_url', 'message' => 'Reference URL must be a valid public http:// or https:// URL.'];
	}

	protected function fetchHtml(string $url): array {
		$current = $url;
		$seen = [];
		for($i = 0; $i <= self::FETCH_MAX_REDIRECTS; $i++) {
			if(isset($seen[$current])) return ['ok' => false, 'reason' => 'redirect_loop', 'message' => 'Reference URL redirected in a loop.'];
			$seen[$current] = true;
			if($this->resolvesToPrivateReferenceAddress($current)) {
				return ['ok' => false, 'reason' => 'private_resolved_host', 'message' => 'Reference URL resolved to a private or reserved network address.'];
			}
			$res = $this->requestHtml($current);
			$code = (int)($res['code'] ?? 0);
			if($code >= 300 && $code < 400) {
				$next = $this->redirectUrl((string)($res['location'] ?? ''), $current);
				if($next === '') return ['ok' => false, 'reason' => 'invalid_redirect_url', 'message' => 'Reference URL redirected to an unsupported location.'];
				$current = $next;
				continue;
			}
			return $res;
		}
		return ['ok' => false, 'reason' => 'too_many_redirects', 'message' => 'Reference URL redirected too many times.'];
	}

	protected function requestHtml(string $url): array {
		if(!function_exists('curl_init')) {
			return ['ok' => false, 'reason' => 'curl_missing', 'message' => 'Reference fetch needs PHP cURL.'];
		}
		$ch = curl_init($url);
		$html = '';
		$truncated = false;
		$location = '';
		$options = [
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT => self::FETCH_TIMEOUT,
			CURLOPT_CONNECTTIMEOUT => self::FETCH_CONNECT_TIMEOUT,
			CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; OliviaReferenceBot/1.0; +ProcessWire)',
			CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
			CURLOPT_HEADERFUNCTION => function($ch, string $header) use (&$location): int {
				if(preg_match('/^Location:\s*(.+)\s*$/i', $header, $m)) $location = trim($m[1]);
				return strlen($header);
			},
			CURLOPT_WRITEFUNCTION => function($ch, string $chunk) use (&$html, &$truncated): int {
				return $this->collectHtmlChunk($html, $chunk, $truncated);
			},
		] + $this->curlProtocolOptions();
		curl_setopt_array($ch, $options);
		$result = curl_exec($ch);
		$err = curl_error($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
		$effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
		if($location === '') $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
		curl_close($ch);

		if($result === false && !$truncated) return ['ok' => false, 'reason' => 'network_error', 'message' => $this->referenceMessage('Reference fetch failed', $err)];
		if($code >= 300 && $code < 400) return ['ok' => true, 'code' => $code, 'url' => $url, 'location' => $location];
		if($code >= 400) return ['ok' => false, 'reason' => 'http_' . $code, 'message' => "Reference site returned HTTP {$code}."];
		if($type !== '' && stripos($type, 'html') === false) return ['ok' => false, 'reason' => 'not_html', 'message' => 'Reference URL did not return an HTML page.'];
		$finalUrl = $this->fetchedUrl($effectiveUrl, $url);
		if($finalUrl === '') return ['ok' => false, 'reason' => 'invalid_redirect_url', 'message' => 'Reference URL redirected to an unsupported location.'];
		return ['ok' => true, 'code' => $code, 'url' => $finalUrl, 'html' => $html];
	}

	protected function redirectUrl(string $location, string $baseUrl): string {
		if(trim($location) === '') return '';
		return $this->absoluteUrl($location, $baseUrl, true);
	}

	protected function fetchedUrl(string $effectiveUrl, string $fallbackUrl): string {
		$url = trim($effectiveUrl) !== '' ? $effectiveUrl : $fallbackUrl;
		$url = $this->normalizeAbsoluteUrl($url, true);
		if($url === '') return '';
		$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
		return in_array($scheme, ['http', 'https'], true) ? $url : '';
	}

	protected function curlProtocolOptions(): array {
		if(defined('CURLOPT_PROTOCOLS_STR')) {
			$options = [CURLOPT_PROTOCOLS_STR => 'http,https'];
			if(defined('CURLOPT_REDIR_PROTOCOLS_STR')) $options[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
			return $options;
		}
		if(!defined('CURLPROTO_HTTP') || !defined('CURLPROTO_HTTPS') || !defined('CURLOPT_PROTOCOLS')) return [];
		$protocols = CURLPROTO_HTTP | CURLPROTO_HTTPS;
		$options = [CURLOPT_PROTOCOLS => $protocols];
		if(defined('CURLOPT_REDIR_PROTOCOLS')) $options[CURLOPT_REDIR_PROTOCOLS] = $protocols;
		return $options;
	}

	protected function collectHtmlChunk(string &$html, string $chunk, bool &$truncated): int {
		$room = self::FETCH_HTML_BYTES - strlen($html);
		if($room <= 0) {
			$truncated = true;
			return 0;
		}
		$len = strlen($chunk);
		if($len > $room) {
			$html .= substr($chunk, 0, $room);
			$truncated = true;
			return 0;
		}
		$html .= $chunk;
		return $len;
	}

	protected function looksBlocked(string $html, int $code): bool {
		if(in_array($code, [401, 403, 429, 503], true)) return true;
		return (bool) preg_match('/captcha|cloudflare|cf-challenge|checking your browser|access denied|bot detection|verify you are human/i', substr($html, 0, 120000));
	}

	protected function parseHtml(string $html, string $url): array {
		$old = libxml_use_internal_errors(true);
		$dom = new \DOMDocument();
		$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
		libxml_clear_errors();
		libxml_use_internal_errors($old);
		$xp = new \DOMXPath($dom);

		foreach($xp->query('//script|//style|//noscript|//svg') as $n) $n->parentNode?->removeChild($n);

		$textOf = function(string $query) use ($xp): array {
			$out = [];
			foreach($xp->query($query) as $n) {
				$t = trim(preg_replace('/\s+/', ' ', (string)$n->textContent));
				if($t !== '' && mb_strlen($t) <= 180) $out[] = $t;
			}
			return array_values(array_unique($out));
		};

		$title = $textOf('//title')[0] ?? '';
		if($title === '') $title = $this->firstMetaContent($xp, ['og:title', 'twitter:title']);
		$meta = $this->firstMetaContent($xp, ['description', 'og:description', 'twitter:description']);
		$language = $this->pageLanguage($xp);
		$headings = array_slice($textOf('//h1|//h2|//h3'), 0, 40);
		$nav = array_slice($this->linkLabels($xp, '//nav//a|//header//a'), 0, 30);
		$alts = array_slice($this->imageLabels($xp), 0, 30);
		$formCues = array_slice($this->formCues($xp), 0, 24);

		$bodyText = trim(preg_replace('/\s+/', ' ', (string)($xp->query('//body')->item(0)?->textContent ?? '')));
		$bodyText = mb_substr($bodyText, 0, 2500);

		$links = [];
		$baseOrigin = $this->originKey($url);
		$linkBase = $url;
		foreach($xp->query('//base[@href]') as $base) {
			$baseUrl = $this->absoluteUrl((string)$base->getAttribute('href'), $url, true);
			if($baseUrl !== '' && $this->originKey($baseUrl) === $baseOrigin) $linkBase = $baseUrl;
			break;
		}
		foreach($xp->query('//a[@href]') as $a) {
			if(count($links) >= self::MAX_LINKS) break;
			$abs = $this->absoluteUrl((string)$a->getAttribute('href'), $linkBase);
			if($abs === '') continue;
			if($this->originKey($abs) !== $baseOrigin) continue;
			if(preg_match('/\\.(' . self::SKIP_LINK_EXTENSIONS . ')(\\?|$)/i', $abs)) continue;
			$link = $this->canonicalCrawlUrl($abs);
			if($link !== false && !in_array($link, $links, true)) $links[] = $link;
		}

		return [
			'url' => $url,
			'title' => $title,
			'language' => $language,
			'meta' => $meta,
			'nav' => array_values(array_unique($nav)),
			'headings' => array_values(array_unique($headings)),
			'image_alts' => array_values(array_unique($alts)),
			'form_cues' => array_values(array_unique($formCues)),
			'body' => $bodyText,
			'links' => $links,
		];
	}

	protected function pageLanguage(\DOMXPath $xp): string {
		foreach($xp->query('//html[@lang]') as $html) {
			$lang = strtolower(trim((string)$html->getAttribute('lang')));
			if($lang !== '') return $this->limitText($lang, 32);
			break;
		}
		$locale = strtolower(str_replace('_', '-', $this->firstMetaContent($xp, ['og:locale'])));
		return $locale !== '' ? $this->limitText($locale, 32) : '';
	}

	protected function originKey(string $url): string {
		$parts = parse_url($this->normalizeAbsoluteUrl($url));
		if(!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return '';
		$scheme = strtolower((string)$parts['scheme']);
		$port = (int)($parts['port'] ?? 0);
		if($port === 0) $port = $scheme === 'http' ? 80 : 443;
		return $scheme . '://' . strtolower((string)$parts['host']) . ':' . $port;
	}

	protected function firstMetaContent(\DOMXPath $xp, array $names): string {
		foreach($names as $name) {
			$name = strtolower((string)$name);
			$query = '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="' . $name . '" or translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="' . $name . '"]';
			foreach($xp->query($query) as $meta) {
				$content = trim((string)$meta->getAttribute('content'));
				if($content !== '') return $this->limitText($content, 300);
			}
		}
		return '';
	}

	protected function linkLabels(\DOMXPath $xp, string $query): array {
		$out = [];
		foreach($xp->query($query) as $link) {
			$text = trim(preg_replace('/\s+/', ' ', (string)$link->textContent));
			if($text === '') $text = trim((string)$link->getAttribute('aria-label'));
			if($text === '') $text = trim((string)$link->getAttribute('title'));
			if($text !== '' && mb_strlen($text) <= 180) $out[] = $text;
		}
		return array_values(array_unique($out));
	}

	protected function imageLabels(\DOMXPath $xp): array {
		$out = [];
		foreach($xp->query('//img') as $img) {
			$text = trim((string)$img->getAttribute('alt'));
			if($text === '') $text = trim((string)$img->getAttribute('aria-label'));
			if($text === '') $text = trim((string)$img->getAttribute('title'));
			if($text !== '' && mb_strlen($text) <= 180) $out[] = $text;
		}
		return array_values(array_unique($out));
	}

	protected function formCues(\DOMXPath $xp): array {
		$out = [];
		foreach($xp->query('//label|//input|//textarea|//select|//button') as $node) {
			if(strtolower((string)$node->nodeName) === 'input') {
				$type = strtolower(trim((string)$node->getAttribute('type'))) ?: 'text';
				if(in_array($type, ['hidden', 'password', 'file', 'reset', 'submit', 'image', 'button'], true)) continue;
			}
			$text = trim(preg_replace('/\s+/', ' ', (string)$node->textContent));
			foreach(['aria-label', 'placeholder', 'name', 'type'] as $attr) {
				if($text !== '') break;
				$text = trim((string)$node->getAttribute($attr));
			}
			if($text !== '' && mb_strlen($text) <= 120) $out[] = $text;
		}
		return array_values(array_unique($out));
	}

	protected function canonicalCrawlUrl(string $url): string {
		$parts = parse_url($this->normalizeAbsoluteUrl($url));
		if(!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return '';
		$query = [];
		if(isset($parts['query']) && $parts['query'] !== '') {
			parse_str((string)$parts['query'], $query);
			foreach(array_keys($query) as $key) {
				$lower = strtolower((string)$key);
				if(str_starts_with($lower, 'utm_') || in_array($lower, ['fbclid', 'gclid', 'dclid', 'msclkid', 'mc_cid', 'mc_eid'], true)) {
					unset($query[$key]);
				}
			}
			ksort($query);
		}
		$base = (string)$parts['scheme'] . '://' . (string)$parts['host'];
		if(isset($parts['port'])) $base .= ':' . (int)$parts['port'];
		$base .= (string)($parts['path'] ?? '/');
		if($query) $base .= '?' . http_build_query($query);
		return $base;
	}

	protected function attrs(\DOMXPath $xp, string $query, string $attr): array {
		$out = [];
		foreach($xp->query($query) as $n) $out[] = (string)$n->getAttribute($attr);
		return $out;
	}

	protected function absoluteUrl(string $href, string $base, bool $preserveTrailingSlash = false): string {
		$href = trim($href);
		if($href === '' || str_starts_with($href, '#') || preg_match('/^(mailto|tel|javascript):/i', $href)) return '';
		if(preg_match('#^https?://#i', $href)) return $this->normalizeAbsoluteUrl($href, $preserveTrailingSlash);
		if(preg_match('/^[a-z][a-z0-9+.-]*:/i', $href)) return '';
		$scheme = (string) parse_url($base, PHP_URL_SCHEME);
		$host = (string) parse_url($base, PHP_URL_HOST);
		if($scheme === '' || $host === '') return '';
		$authority = $scheme . '://' . $host;
		$port = (int)(parse_url($base, PHP_URL_PORT) ?: 0);
		if($port > 0) $authority .= ':' . $port;
		if(str_starts_with($href, '//')) return $this->normalizeAbsoluteUrl($scheme . ':' . $href, $preserveTrailingSlash);
		if(str_starts_with($href, '/')) return $this->normalizeAbsoluteUrl($authority . $href, $preserveTrailingSlash);
		$path = (string) parse_url($base, PHP_URL_PATH);
		$dir = str_ends_with($path, '/') ? rtrim($path, '/') : rtrim(dirname($path), '/');
		return $this->normalizeAbsoluteUrl($authority . ($dir ? $dir : '') . '/' . $href, $preserveTrailingSlash);
	}

	protected function normalizeAbsoluteUrl(string $url, bool $preserveTrailingSlash = false): string {
		$parts = parse_url($url);
		if(!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return '';
		if(isset($parts['user']) || isset($parts['pass'])) return '';
		if(!$this->isPublicReferenceHost((string)$parts['host'])) return '';
		$path = (string)($parts['path'] ?? '/');
		$hadTrailingSlash = $preserveTrailingSlash && $path !== '/' && str_ends_with($path, '/');
		$out = [];
		foreach(explode('/', $path) as $part) {
			if($part === '' || $part === '.') continue;
			if($part === '..') {
				array_pop($out);
				continue;
			}
			$out[] = $part;
		}
		$scheme = strtolower((string)$parts['scheme']);
		$normalized = $scheme . '://' . $this->referenceAuthorityHost((string)$parts['host']);
		$port = (int)($parts['port'] ?? 0);
		if($port > 0 && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) return '';
		$normalized .= '/' . implode('/', $out);
		if($hadTrailingSlash && $out) $normalized .= '/';
		if(isset($parts['query']) && $parts['query'] !== '') $normalized .= '?' . $parts['query'];
		return strlen($normalized) <= self::MAX_URL_CHARS ? $normalized : '';
	}

	protected function referenceAuthorityHost(string $host): string {
		$host = strtolower(trim($host));
		$plain = trim($host, '[]');
		if(filter_var($plain, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) return '[' . $plain . ']';
		return $host;
	}

	protected function isPublicReferenceHost(string $host): bool {
		$host = strtolower(trim($host, " \t\n\r\0\x0B[]"));
		if($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) return false;
		$ip = filter_var($host, FILTER_VALIDATE_IP);
		if($ip === false) return $this->validReferenceHostname($host) && str_contains($host, '.');
		return $this->isPublicReferenceAddress((string)$ip);
	}

	protected function resolvesToPrivateReferenceAddress(string $url): bool {
		$host = (string)parse_url($url, PHP_URL_HOST);
		if($host === '') return false;
		foreach($this->resolvedReferenceAddresses($host) as $address) {
			if(!$this->isPublicReferenceAddress($address)) return true;
		}
		return false;
	}

	protected function resolvedReferenceAddresses(string $host): array {
		$host = strtolower(trim($host, " \t\n\r\0\x0B[]"));
		if($host === '') return [];
		if(filter_var($host, FILTER_VALIDATE_IP) !== false) return [$host];
		if(!$this->validReferenceHostname($host)) return [];
		$out = [];
		if(function_exists('dns_get_record') && defined('DNS_A') && defined('DNS_AAAA')) {
			$records = @dns_get_record($host, DNS_A + DNS_AAAA);
			if(is_array($records)) {
				foreach($records as $record) {
					foreach(['ip', 'ipv6'] as $key) {
						if(!empty($record[$key]) && filter_var($record[$key], FILTER_VALIDATE_IP) !== false) $out[] = (string)$record[$key];
					}
				}
			}
		}
		if(!$out && function_exists('gethostbynamel')) {
			$records = @gethostbynamel($host);
			if(is_array($records)) {
				foreach($records as $record) {
					if(filter_var($record, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) $out[] = (string)$record;
				}
			}
		}
		return array_values(array_unique($out));
	}

	protected function validReferenceHostname(string $host): bool {
		$host = rtrim($host, '.');
		if($host === '' || strlen($host) > 253) return false;
		if(preg_match('/^[0-9.]+$/', $host)) return false;
		foreach(explode('.', $host) as $label) {
			if($label === '' || strlen($label) > 63) return false;
			if(!preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i', $label)) return false;
		}
		return true;
	}

	protected function isPublicReferenceAddress(string $address): bool {
		return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
	}

	protected function brief(array $pages): string {
		$lines = [];
		foreach($pages as $i => $p) {
			$lines[] = 'Page ' . ($i + 1) . ': ' . ($p['title'] ?: $p['url']);
			if(!empty($p['language'])) $lines[] = '- Language: ' . $p['language'];
			if($p['meta']) $lines[] = '- Meta: ' . $p['meta'];
			if($p['nav']) $lines[] = '- Navigation: ' . implode(', ', array_slice($p['nav'], 0, 14));
			if($p['headings']) $lines[] = '- Headings: ' . implode(' | ', array_slice($p['headings'], 0, 18));
			if($p['image_alts']) $lines[] = '- Image cues: ' . implode(', ', array_slice($p['image_alts'], 0, 12));
			if(!empty($p['form_cues'])) $lines[] = '- Form cues: ' . implode(', ', array_slice($p['form_cues'], 0, 12));
			if($p['body']) $lines[] = '- Text sample: ' . mb_substr($p['body'], 0, 900);
		}
		return mb_substr(implode("\n", $lines), 0, self::MAX_TEXT_CHARS);
	}

	protected function limitText(string $text, int $max): string {
		if($max <= 0) return '';
		if(function_exists('mb_strlen') && function_exists('mb_substr')) {
			return mb_strlen($text, 'UTF-8') > $max ? mb_substr($text, 0, $max, 'UTF-8') : $text;
		}
		return strlen($text) > $max ? substr($text, 0, $max) : $text;
	}

	protected function referenceMessage(string $message, string $detail = ''): string {
		$text = trim($message);
		$detail = trim($detail);
		if($detail !== '') $text .= ': ' . $detail;
		return $this->limitText($text, self::MAX_MESSAGE_CHARS);
	}

	protected function saveUploadedImage(string $field): ?array {
		if(empty($_FILES[$field]) || !is_array($_FILES[$field])) return null;
		$f = $_FILES[$field];
		if((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
		if((int)$f['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'message' => 'Screenshot upload failed.'];
		if((int)$f['size'] > self::MAX_IMAGE_BYTES) return ['ok' => false, 'message' => 'Screenshot is larger than 8 MB.'];

		$tmp = (string)$f['tmp_name'];
		$info = @getimagesize($tmp);
		if(!$info || empty($info['mime']) || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
			return ['ok' => false, 'message' => 'Screenshot must be JPG, PNG, WebP, or GIF.'];
		}
		if(!$this->validImageDimensions($info)) return ['ok' => false, 'message' => 'Screenshot dimensions exceed the safety limit.'];

		$ext = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp', 'image/gif'=>'gif'][$info['mime']];
		$dir = $this->wire->config->paths->assets . 'Olivia/references/';
		if(!is_dir($dir)) $this->wire->files->mkdir($dir, true);
		try {
			[$name, $dest] = $this->referenceUploadTarget($dir, substr(md5_file($tmp) ?: uniqid('', true), 0, 10), $ext);
		} catch(\Throwable $e) {
			return ['ok' => false, 'message' => 'Could not allocate screenshot upload filename.'];
		}
		if(!@move_uploaded_file($tmp, $dest)) return ['ok' => false, 'message' => 'Could not save screenshot upload.'];

		return [
			'ok' => true,
			'file' => $dest,
			'url' => $this->wire->config->urls->assets . 'Olivia/references/' . $name,
			'mime' => $info['mime'],
			'width' => (int)$info[0],
			'height' => (int)$info[1],
		];
	}

	protected function saveUploadedImages(string $field): array {
		if(empty($_FILES[$field]) || !is_array($_FILES[$field])) return [];
		$file = $_FILES[$field];
		if(!is_array($file['name'] ?? null)) {
			$one = $this->saveUploadedImage($field);
			return $one ? [$one] : [];
		}
		$out = [];
		$count = min(self::MAX_IMAGES, count($file['name']));
		$original = $_FILES[$field];
		try {
			for($i = 0; $i < $count; $i++) {
				$_FILES[$field] = [
					'name' => $file['name'][$i] ?? '', 'type' => $file['type'][$i] ?? '',
					'tmp_name' => $file['tmp_name'][$i] ?? '', 'error' => $file['error'][$i] ?? UPLOAD_ERR_NO_FILE,
					'size' => $file['size'][$i] ?? 0,
				];
				$one = $this->saveUploadedImage($field);
				if($one) $out[] = $one;
			}
		} finally {
			$_FILES[$field] = $original;
		}
		return $out;
	}

	protected function validImageDimensions(array $info): bool {
		$width = (int)($info[0] ?? 0);
		$height = (int)($info[1] ?? 0);
		if($width < 1 || $height < 1 || $width > self::MAX_IMAGE_DIMENSION || $height > self::MAX_IMAGE_DIMENSION) return false;
		return $width <= intdiv(self::MAX_IMAGE_PIXELS, $height);
	}

	protected function referenceUploadTarget(string $dir, string $hash, string $ext): array {
		$hash = preg_replace('/[^a-f0-9]/i', '', $hash) ?: substr(md5(uniqid('', true)), 0, 10);
		$hash = strtolower(substr($hash, 0, 10));
		$ext = strtolower(preg_replace('/[^a-z0-9]/i', '', $ext) ?: 'img');
		$stem = date('Ymd-His') . '-' . $hash;
		for($i = 0; $i < 100; $i++) {
			$name = $stem . ($i ? '-' . ($i + 1) : '') . '.' . $ext;
			$dest = rtrim($dir, '/') . '/' . $name;
			if(!is_file($dest)) return [$name, $dest];
		}
		throw new \RuntimeException('Could not allocate Olivia reference screenshot filename');
	}
}
