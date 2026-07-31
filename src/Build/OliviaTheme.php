<?php namespace ProcessWire;

/**
 * OliviaTheme — visual theme (font + colour palette) for generated sites.
 *
 * A theme is a small, safe descriptor stored on the plan as site.theme:
 *   { "font": "Poppins", "primary": "#0d9488", "background": "#ffffff", ... }
 *
 * - Fonts are a curated set of open-source Google Fonts (all support weights
 *   400-700) so the user can pick a typeface and Olivia loads it in the views.
 * - "primary" is a single brand colour; Olivia derives the darker hover shade.
 *
 * The planner proposes a theme fitting the vertical; the UI can override it; the
 * last applied theme is persisted so Change-mode rebuilds keep the look.
 */
class OliviaTheme extends Wire {
	protected const TMP_TTL = 3600;
	protected static $tempsCleaned = false;

	/** Curated open-source fonts (Google), all with weights 400;500;600;700. */
	const FONTS = [
		'Inter'            => 'clean, neutral sans — safe default',
		'Poppins'          => 'geometric sans — friendly, modern',
		'Montserrat'       => 'confident sans — corporate, bold',
		'DM Sans'          => 'soft sans — minimal, calm',
		'Space Grotesk'    => 'techy sans — startups, product',
		'Sora'             => 'rounded sans — approachable',
		'Manrope'          => 'precise sans — elegant, premium',
		'Work Sans'        => 'versatile sans — editorial',
		'Playfair Display' => 'high-contrast serif — luxury, fashion, hospitality',
		'Lora'             => 'readable serif — restaurants, writers, classic',
	];

	/** Palette presets the user can pick (label => primary hex). */
	const PALETTES = [
		'Indigo'  => '#4f46e5', 'Violet'  => '#7c3aed', 'Sky'     => '#0284c7',
		'Teal'    => '#0d9488', 'Emerald' => '#059669', 'Amber'   => '#b45309',
		'Rose'    => '#e11d48', 'Crimson' => '#dc2626', 'Slate'   => '#334155',
		'Stone'   => '#57534e',
	];

	const DEFAULT_FONT    = 'Inter';
	const DEFAULT_PRIMARY = '#4f46e5';
	const WEIGHTS         = '400;500;600;700';

	public function fontNames(): array { return array_keys(self::FONTS); }
	public function fonts(): array { return self::FONTS; }
	public function palettes(): array { return self::PALETTES; }

	public function wired() {
		if(!self::$tempsCleaned) {
			$this->cleanupStaleTemps();
			self::$tempsCleaned = true;
		}
		parent::wired();
	}

	/**
	 * Resolve the theme for a build: plan theme, else the last stored theme, else
	 * the default. Returns view-ready values (font stack, font URL, colours).
	 */
	public function resolve(array $plan): array {
		$theme = $plan['site']['theme'] ?? [];
		if(!is_array($theme) || (empty($theme['font']) && empty($theme['primary']))) {
			$stored = $this->current();
			if($stored) $theme = $stored;
		}
		return $this->normalize(is_array($theme) ? $theme : []);
	}

	/** Normalize a raw {font, primary} into safe, view-ready values. */
	public function normalize(array $theme): array {
		$font    = $this->validFont((string)($theme['font'] ?? ''));
		$primary = $this->validHex((string)($theme['primary'] ?? ''), self::DEFAULT_PRIMARY);
		$background = $this->validHex((string)($theme['background'] ?? ''), '#ffffff');
		$surface = $this->validHex((string)($theme['surface'] ?? ''), '#ffffff');
		$text = $this->validHex((string)($theme['text'] ?? ''), '#27272a');
		$muted = $this->validHex((string)($theme['muted'] ?? ''), '#71717a');
		$radius = max(0, min(24, (int)($theme['radius'] ?? 8)));
		$container = max(960, min(1600, (int)($theme['container'] ?? 1152)));
		$density = $this->validDensity((string)($theme['density'] ?? ''));
		return [
			'font'       => $font,
			'fontStack'  => "'" . $font . "','ui-sans-serif','system-ui','sans-serif'",
			'fontLink'   => $this->fontLink($font),
			'primary'    => $primary,
			'primary700' => $this->darken($primary, 0.82),
			'primaryRgb' => $this->rgbChannels($primary),
			'primary700Rgb' => $this->rgbChannels($this->darken($primary, 0.82)),
			'background' => $background,
			'surface' => $surface,
			'text' => $text,
			'muted' => $muted,
			'radius' => $radius,
			'container' => $container,
			'density' => $density,
		];
	}

	/** Public normalization used by the bounded vision design-profile bridge. */
	public function normalizeReferenceTheme(array $theme): array {
		$normalized = $this->normalize($theme);
		return array_intersect_key($normalized, array_flip(['font', 'primary', 'background', 'surface', 'text', 'muted', 'radius', 'container', 'density']));
	}

	public function validDensity(string $density): string {
		$density = strtolower(trim($density));
		return in_array($density, ['compact', 'balanced', 'spacious'], true) ? $density : 'balanced';
	}

	/** Case-insensitive whitelist; unknown fonts fall back to the default. */
	public function validFont(string $f): string {
		foreach(self::FONTS as $name => $_) if(strcasecmp($name, $f) === 0) return $name;
		return self::DEFAULT_FONT;
	}

	/** Validate a #rrggbb colour, else return the fallback. */
	public function validHex(string $h, string $fallback): string {
		$h = trim($h);
		if($h !== '' && $h[0] !== '#') $h = '#' . $h;
		return preg_match('/^#[0-9a-fA-F]{6}$/', $h) ? strtolower($h) : $fallback;
	}

	protected function fontLink(string $font): string {
		return 'https://fonts.googleapis.com/css2?family=' . str_replace(' ', '+', $font) . ':wght@' . self::WEIGHTS . '&display=swap';
	}

	/** Darken a #rrggbb colour by factor (0..1) for the hover shade. */
	protected function darken(string $hex, float $f): string {
		$r = (int) max(0, min(255, hexdec(substr($hex, 1, 2)) * $f));
		$g = (int) max(0, min(255, hexdec(substr($hex, 3, 2)) * $f));
		$b = (int) max(0, min(255, hexdec(substr($hex, 5, 2)) * $f));
		return sprintf('#%02x%02x%02x', $r, $g, $b);
	}

	/** Space-separated channels for Tailwind's rgb(var(...) / alpha) colours. */
	protected function rgbChannels(string $hex): string {
		return hexdec(substr($hex, 1, 2)) . ' '
			. hexdec(substr($hex, 3, 2)) . ' '
			. hexdec(substr($hex, 5, 2));
	}

	/* --------------------------------------------------------- persistence */

	protected function file(): string {
		return $this->wire->config->paths->cache . 'Olivia/theme.json';
	}

	/** The last applied theme {font, primary}, or null. */
	public function current(): ?array {
		$f = $this->file();
		if(!is_file($f)) return null;
		$raw = @file_get_contents($f);
		if(!is_string($raw)) return null;
		$d = json_decode($raw, true);
		return is_array($d) ? $d : null;
	}

	/** Persist {font, primary} as the site's current theme. */
	public function save(array $theme): void {
		$normalized = $this->normalize($theme);
		$out = array_intersect_key($normalized, array_flip(['font', 'primary', 'background', 'surface', 'text', 'muted', 'radius', 'container', 'density']));
		$dir = dirname($this->file());
		if(!is_dir($dir)) $this->wire->files->mkdir($dir, true);
		$this->writeJsonFile($this->file(), $out);
	}

	protected function writeJsonFile(string $file, array $data): void {
		$json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
		if(!is_string($json)) throw new \RuntimeException('Could not encode Olivia theme JSON');
		$tmp = $file . '.' . getmypid() . '.' . str_replace('.', '', uniqid('', true)) . '.tmp';
		try {
			$this->wire->files->filePutContents($tmp, $json);
			if(!@rename($tmp, $file)) throw new \RuntimeException('Could not replace Olivia theme JSON');
		} finally {
			if(is_file($tmp)) @unlink($tmp);
		}
	}

	public function cleanupStaleTemps(): void {
		$files = glob($this->file() . '.*.tmp');
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
		return (bool) preg_match('/^theme\.json\.\d+\.[a-f0-9]+\.tmp$/', $name);
	}
}
