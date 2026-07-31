<?php namespace ProcessWire;

/**
 * OliviaBuilder
 *
 * Executes an Olivia build plan (a structured blueprint) against ProcessWire:
 * creates fields, templates and pages, and records a rollback manifest of
 * everything it created.
 *
 * The builder is deliberately AI-agnostic. It accepts a plain plan array
 * (the same JSON shape Olivia asks the model to produce) so it can be tested
 * end-to-end with a fixed plan, before any provider key is configured.
 *
 * Plan shape:
 *
 *   [
 *     'site'      => ['title' => '...', 'type' => 'dental_clinic'],
 *     'fields'    => [ ['name'=>'headline','type'=>'text','label'=>'Headline'], ... ],
 *     'templates' => [ ['name'=>'service','label'=>'Service','fields'=>['title','headline']], ... ],
 *     'pages'     => [ ['title'=>'Home','template'=>'home','parent'=>'/','children'=>[...]], ... ],
 *     'modules'   => ['Vox','Meteo']   // recommendations only; not installed here
 *   ]
 *
 * Safety boundaries:
 *  - additive only: never deletes or overwrites existing fields/templates/pages
 *  - existing objects are reused and labelled "reused", not modified
 *  - does not install modules, does not touch core/vendor/third-party module source
 */
class OliviaBuilder extends Wire {

	/** Map of plan field types => ProcessWire fieldtype module names */
	protected $typeMap = [
		'text'     => 'FieldtypeText',
		'textarea' => 'FieldtypeTextarea',
		'richtext' => 'FieldtypeTextarea',
		'body'     => 'FieldtypeTextarea',
		'url'      => 'FieldtypeURL',
		'email'    => 'FieldtypeEmail',
		'integer'  => 'FieldtypeInteger',
		'int'      => 'FieldtypeInteger',
		'float'    => 'FieldtypeFloat',
		'decimal'  => 'FieldtypeFloat',
		'checkbox' => 'FieldtypeCheckbox',
		'datetime' => 'FieldtypeDatetime',
		'date'     => 'FieldtypeDatetime',
		'image'    => 'FieldtypeImage',
		'file'     => 'FieldtypeFile',
		'page'     => 'FieldtypePage',
	];

	/** @var array Rollback manifest of what this run created */
	protected $manifest = [];

	public function __construct() {
		parent::__construct();
		$this->resetManifest();
	}

	protected function resetManifest() {
		$this->manifest = [
			'prompt'      => null,
			'created_at'  => null,
			'fields'      => [],   // names created
			'templates'   => [],   // names created
			'template_fields' => [], // fields added to reused templates
			'pages'       => [],   // ids created
			'content_values' => [], // scalar values changed on reused pages
			'files'       => [],   // view files written
			'updated_files' => [], // Olivia-owned files updated; path => previous contents
			'reused'      => ['fields' => [], 'templates' => [], 'pages' => []],
			'warnings'    => [],   // optional integrations that degraded without breaking the build
			'errors'      => [],
		];
	}

	/**
	 * Dry run: describe what a plan would create, without changing anything.
	 *
	 * @param array $plan
	 * @return array preview summary
	 */
	public function preview(array $plan): array {
		$fields = $templates = $pages = ['new' => [], 'reused' => []];

		foreach(($plan['fields'] ?? []) as $f) {
			$name = $this->sanitizeName($f['name'] ?? '');
			if(!$name) continue;
			if($this->wire->fields->get($name)) $fields['reused'][] = $name;
			else $fields['new'][] = $name;
		}
		foreach(($plan['templates'] ?? []) as $t) {
			$name = $this->tplName($t['name'] ?? '');
			if(!$name) continue;
			if($this->wire->templates->get($name)) $templates['reused'][] = $name;
			else $templates['new'][] = $name;
		}
		$pages['new'] = $this->flattenPageTitles($plan['pages'] ?? []);
		$pages['tree'] = $this->pagePreviewTree($plan['pages'] ?? []);

		return [
			'site'      => $plan['site'] ?? [],
			'fields'    => $fields,
			'templates' => $templates,
			'pages'     => $pages,
			'modules'   => $plan['modules'] ?? [],
			'counts'    => [
				'fields_new'    => count($fields['new']),
				'templates_new' => count($templates['new']),
				'pages_new'     => count($pages['new']),
			],
		];
	}

	/**
	 * Execute a plan. Additive only. Returns the rollback manifest.
	 *
	 * @param array $plan
	 * @param string|null $prompt original user prompt (stored in manifest)
	 * @return array manifest
	 */
	public function build(array $plan, ?string $prompt = null, bool $generateViews = true, bool $generateImages = false, bool $fillContent = false): array {
		$this->resetManifest();
		$this->manifest['prompt'] = $prompt;
		$this->manifest['created_at'] = date('c');

		try {
			$this->executeBuild($plan, $generateViews, $generateImages, $fillContent);
		} catch(\Throwable $buildError) {
			$this->throwAfterPartialBuildRollback($buildError);
		}

		return $this->manifest;
	}

	/** Execute the mutating phase behind build()'s compensation boundary. */
	protected function executeBuild(array $plan, bool $generateViews, bool $generateImages, bool $fillContent): void {
		foreach(($plan['fields'] ?? []) as $f) $this->createField($f);
		foreach(($plan['templates'] ?? []) as $t) $this->createTemplate($t);

		// Olivia owns a styled "section" template; ensure it exists so section
		// pages render with the same Tailwind view as detail pages.
		if($this->planUsesSection($plan['pages'] ?? [])) $this->ensureSection();

		foreach(($plan['pages'] ?? []) as $p) $this->createPage($p, null);

		// if the Ichiban SEO module is installed, give Olivia's templates its field
		$this->ensureIchibanSeo();

		// site-level promo banner: store on the home page so every view can show it
		$this->applyBanner($plan);

		if($generateViews) {
			$gen = $this->wire(new OliviaViewGenerator());
			$gen->generate($plan, $this->manifest);
			$gen->generateHome($plan, $this->manifest);
			// site-level SEO files (sitemap.xml + robots.txt at the web root)
			$this->wire(new OliviaSeo())->writeForBuild($this->manifest);
		}

		if($fillContent) {
			$filler = $this->wire(new OliviaContentFiller());
			$filler->fillForBuild($plan, $this->manifest);
		}

		if($generateImages) {
			$img = $this->wire(new OliviaImageGenerator());
			$img->generateForBuild($plan, $this->manifest);
		}

		// Index built pages into the RAG store for later retrieval. No-op unless
		// the Atlas module is installed and has a working embedding provider.
		$this->wire(new OliviaAtlas())->indexBuild($this->manifest);
	}

	/** Roll back mutations from an interrupted build, then preserve the original failure. */
	protected function throwAfterPartialBuildRollback(\Throwable $buildError): void {
		$issues = [];
		try {
			$rollback = $this->rollback($this->manifest);
			$issues = is_array($rollback['errors'] ?? null) ? $rollback['errors'] : [];
		} catch(\Throwable $rollbackError) {
			$issues[] = $rollbackError->getMessage();
		}

		$message = 'Build stopped unexpectedly; Olivia attempted to roll back partial changes. Build error: ' . $buildError->getMessage();
		if($issues) $message .= ' Rollback issues: ' . implode('; ', $issues);
		throw new \RuntimeException($message, 0, $buildError);
	}

	/**
	 * Roll back a previous build using its manifest. Deletes only what that
	 * build created (never reused objects), in reverse dependency order:
	 * pages -> templates -> fieldgroups -> fields.
	 *
	 * @param array $manifest a manifest previously returned by build()
	 * @return array report of what was removed / skipped
	 */
	public function rollback(array $manifest): array {
		$report = ['pages' => [], 'templates' => [], 'fields' => [], 'files' => [], 'errors' => []];

		// site-level SEO files (sitemap.xml + robots.txt), only if Olivia-marked
		try {
			$this->removeSeoForRollback($manifest, $report);
		} catch(\Throwable $e) {
			$report['errors'][] = 'SEO cleanup: ' . $e->getMessage();
		}

		// remove this build's pages from the Atlas RAG store (no-op unless installed)
		try {
			$report['atlas_removed'] = $this->removeAtlasForRollback($manifest);
		} catch(\Throwable $e) {
			$report['atlas_removed'] = 0;
			$report['errors'][] = 'Atlas cleanup: ' . $e->getMessage();
		}

		// remove the promo banner this build stored on the home page
		if(!empty($manifest['banner_set'])) {
			try {
				$root = $this->wire->pages->get('/');
				$expected = is_array($manifest['banner_values'] ?? null) ? $manifest['banner_values'] : null;
				$current = ['text' => (string) $root->meta('olivia_banner'), 'link' => (string) $root->meta('olivia_banner_link')];
				if($expected !== null && $current === $expected) {
					$root->meta()->remove('olivia_banner');
					$root->meta()->remove('olivia_banner_link');
					$report['banner_removed'] = true;
				} else {
					$report['banner_skipped'] = true;
				}
			}
			catch(\Throwable $e) { $report['errors'][] = 'banner clear: ' . $e->getMessage(); }
		}

		// clear AI-filled text on pages that survive this rollback (we only ever
		// filled fields that were empty, so restoring = clearing them back to empty)
		foreach(($manifest['filled'] ?? []) as $item) {
			if(!is_array($item)) {
				$report['filled_skipped'][] = (string) $item;
				continue;
			}
			$pid = (int)($item['page'] ?? 0);
			$fname = $this->sanitizeName($item['field'] ?? '');
			$written = $item['written'] ?? null;
			if($pid < 1 || $fname === '' || is_array($written) || is_object($written)) continue;
			try {
				$page = $this->wire->pages->get($pid);
				if($page->id && $page->template->fieldgroup->hasField($fname)) {
					if($page->get($fname) !== $written) {
						$report['filled_skipped'][] = "{$pid}:{$fname}";
						continue;
					}
					$page->of(false);
					$page->set($fname, '');
					$page->save($fname);
					$report['filled_cleared'][] = "{$pid}:{$fname}";
				}
			} catch(\Throwable $e) {
				$report['errors'][] = "clear filled {$pid}:{$fname}: " . $e->getMessage();
			}
		}

		// restore deterministic plan content written into empty fields on reused pages
		foreach(($manifest['content_values'] ?? []) as $item) {
			$pid = (int)($item['page'] ?? 0);
			$fname = $this->sanitizeName($item['field'] ?? '');
			$value = $item['value'] ?? null;
			$written = $item['written'] ?? null;
			if($pid < 1 || $fname === '' || is_array($value) || is_object($value)) continue;
			if(!array_key_exists('written', $item) || is_array($written) || is_object($written)) {
				$report['content_skipped'][] = "{$pid}:{$fname}";
				continue;
			}
			try {
				$page = $this->wire->pages->get($pid);
				if($page->id && $page->template->fieldgroup->hasField($fname)) {
					if($page->get($fname) !== $written) {
						$report['content_skipped'][] = "{$pid}:{$fname}";
						continue;
					}
					$page->of(false);
					$page->set($fname, $value);
					$page->save($fname);
					$report['content_restored'][] = "{$pid}:{$fname}";
				}
			} catch(\Throwable $e) {
				$report['errors'][] = "restore content {$pid}:{$fname}: " . $e->getMessage();
			}
		}

		// generated view files (limit deletion to the templates dir)
		$tplPath = $this->wire->config->paths->templates;
		foreach(($manifest['files'] ?? []) as $file) {
			try {
				$file = (string) $file;
				if(!$this->isPathInside($file, $tplPath)) continue;
				if(is_file($file) && $this->wire->files->unlink($file, $tplPath)) {
					$report['files'][] = basename($file);
				}
			} catch(\Throwable $e) {
				$report['errors'][] = "file " . basename($file) . ": " . $e->getMessage();
			}
		}

		// restore Olivia-owned view files updated by this build
		foreach(($manifest['updated_files'] ?? []) as $file => $contents) {
			try {
				$file = (string) $file;
				if(!$this->isPathInside($file, $tplPath)) continue;
				$this->writeTemplateFile($file, (string) $contents);
				$report['updated_files'][] = basename($file);
			} catch(\Throwable $e) {
				$report['errors'][] = "updated file " . basename($file) . ": " . $e->getMessage();
			}
		}

		// restore home template/page if this build customized the landing
		if(array_key_exists('home_alt', $manifest) || array_key_exists('home_title', $manifest)) {
			try {
				$home = $this->wire->templates->get('home');
				if($home && array_key_exists('home_alt', $manifest)) {
					$home->altFilename = $manifest['home_alt'];
					if(array_key_exists('home_noappend', $manifest)) {
						$home->noAppendTemplateFile = $manifest['home_noappend'][0] ?? 0;
						$home->noPrependTemplateFile = $manifest['home_noappend'][1] ?? 0;
					}
					$this->wire->templates->save($home);
					$report['home'][] = 'home template restored';
				}
				if(array_key_exists('home_title', $manifest)) {
					$root = $this->wire->pages->get('/');
					$root->of(false);
					$root->title = $manifest['home_title'];
					$root->save();
					$report['home'][] = 'title restored';
				}
			} catch(\Throwable $e) {
				$report['errors'][] = 'home restore: ' . $e->getMessage();
			}
		}

		// pages first (by id)
		foreach(($manifest['pages'] ?? []) as $id) {
			try {
				$p = $this->wire->pages->get((int) $id);
				if($p && $p->id) {
					$label = $p->title ?: $p->name;
					$this->wire->pages->delete($p, true);
					$report['pages'][] = $label;
				}
			} catch(\Throwable $e) {
				$report['errors'][] = "page {$id}: " . $e->getMessage();
			}
		}

		// fields added to reused templates
		foreach(($manifest['template_fields'] ?? []) as $item) {
			try {
				$tplName = $this->tplName($item['template'] ?? '');
				$fieldName = $this->sanitizeName($item['field'] ?? '');
				if(!$tplName || !$fieldName) continue;
				$tpl = $this->wire->templates->get($tplName);
				$field = $this->wire->fields->get($fieldName);
				if($tpl && $field && $tpl->fieldgroup->hasField($fieldName)) {
					$tpl->fieldgroup->remove($field);
					$this->wire->fieldgroups->save($tpl->fieldgroup);
					$report['template_fields'][] = "{$tplName}.{$fieldName}";
				}
			} catch(\Throwable $e) {
				$report['errors'][] = "template field {$tplName}.{$fieldName}: " . $e->getMessage();
			}
		}

		// templates (and their fieldgroups)
		foreach(($manifest['templates'] ?? []) as $name) {
			try {
				$tpl = $this->wire->templates->get($name);
				if(!$tpl) continue;
				if($this->wire->pages->count("template=$name, include=all") > 0) {
					$report['errors'][] = "template {$name}: still in use, skipped";
					continue;
				}
				$fg = $tpl->fieldgroup;
				$this->wire->templates->delete($tpl);
				if($fg && $fg->id) $this->wire->fieldgroups->delete($fg);
				$report['templates'][] = $name;
			} catch(\Throwable $e) {
				$report['errors'][] = "template {$name}: " . $e->getMessage();
			}
		}

		// fields last
		foreach(($manifest['fields'] ?? []) as $name) {
			try {
				$f = $this->wire->fields->get($name);
				if(!$f) continue;
				if(count($f->getFieldgroups())) {
					$report['errors'][] = "field {$name}: still used by a template, skipped";
					continue;
				}
				$this->wire->fields->delete($f);
				$report['fields'][] = $name;
			} catch(\Throwable $e) {
				$report['errors'][] = "field {$name}: " . $e->getMessage();
			}
		}

		return $report;
	}

	protected function removeSeoForRollback(array $manifest, array &$report): void {
		$this->wire(new OliviaSeo())->removeForBuild($manifest, $report);
	}

	protected function removeAtlasForRollback(array $manifest): int {
		return (int) $this->wire(new OliviaAtlas())->removeBuild($manifest);
	}

	/**
	 * Persist the completed Build invariant. If History/Undo cannot be written,
	 * compensate immediately while the in-memory manifest is still available.
	 */
	public function saveManifestOrRollback(OliviaStore $store, array $manifest): string {
		try {
			return $store->save($manifest);
		} catch(\Throwable $saveError) {
			$rollback = $this->rollback($manifest);
			$issues = is_array($rollback['errors'] ?? null) ? $rollback['errors'] : [];
			$message = 'Build manifest could not be saved; Olivia rolled back the untracked build. Save error: ' . $saveError->getMessage();
			if($issues) $message .= ' Rollback issues: ' . implode('; ', $issues);
			throw new \RuntimeException($message, 0, $saveError);
		}
	}

	protected function writeTemplateFile(string $file, string $contents): void {
		$tmp = $file . '.' . getmypid() . '.' . str_replace('.', '', uniqid('', true)) . '.tmp';
		try {
			$this->wire->files->filePutContents($tmp, $contents);
			if(!@rename($tmp, $file)) throw new \RuntimeException('Could not replace Olivia rollback file');
		} finally {
			if(is_file($tmp)) @unlink($tmp);
		}
	}

	protected function isPathInside(string $file, string $basePath): bool {
		$base = realpath($basePath);
		$dir = realpath(dirname($file));
		if($base === false || $dir === false) return false;
		$base = rtrim(str_replace('\\', '/', $base), '/') . '/';
		$dir = rtrim(str_replace('\\', '/', $dir), '/') . '/';
		return strpos($dir, $base) === 0;
	}

	/* ---------------------------------------------------------------- fields */

	protected function createField(array $def): ?Field {
		$name = $this->sanitizeName($def['name'] ?? '');
		if(!$name) return null;

		$existing = $this->wire->fields->get($name);
		if($existing) {
			$this->manifest['reused']['fields'][] = $name;
			return $existing;
		}

		$typeKey = strtolower((string)($def['type'] ?? 'text'));
		$fieldtype = $this->typeMap[$typeKey] ?? 'FieldtypeText';

		try {
			$field = new Field();
			$field->type = $this->wire->modules->get($fieldtype);
			$field->name = $name;
			$field->label = (string)($def['label'] ?? $this->labelize($name));
			$this->wire->fields->save($field);
			$this->manifest['fields'][] = $name;
			return $field;
		} catch(\Throwable $e) {
			$this->manifest['errors'][] = "field {$name}: " . $e->getMessage();
			return null;
		}
	}

	/* ------------------------------------------------------------- templates */

	protected function createTemplate(array $def): ?Template {
		$name = $this->tplName($def['name'] ?? '');
		if(!$name) return null;

		$existing = $this->wire->templates->get($name);
		if($existing) {
			$this->manifest['reused']['templates'][] = $name;
			// Change-mode: add any new plan fields to the existing template (fields
			// were created earlier in build()); ensureTemplateField skips dupes.
			foreach(($def['fields'] ?? []) as $fname) {
				$fname = $this->sanitizeName($fname);
				if(!$fname || $fname === 'title') continue;
				if($this->wire->fields->get($fname)) $this->ensureTemplateField($name, $fname);
			}
			return $existing;
		}

		try {
			$fg = new Fieldgroup();
			$fg->name = $name;
			$fg->add($this->wire->fields->get('title')); // every template gets title
			foreach(($def['fields'] ?? []) as $fname) {
				$fname = $this->sanitizeName($fname);
				if(!$fname || $fname === 'title') continue;
				$f = $this->wire->fields->get($fname);
				if($f) $fg->add($f);
			}
			$this->wire->fieldgroups->save($fg);

			$tpl = new Template();
			$tpl->name = $name;
			$tpl->label = (string)($def['label'] ?? $this->labelize($name));
			$tpl->fieldgroup = $fg;
			$this->wire->templates->save($tpl);

			$this->manifest['templates'][] = $name;
			return $tpl;
		} catch(\Throwable $e) {
			$this->manifest['errors'][] = "template {$name}: " . $e->getMessage();
			return null;
		}
	}

	/** Does any page (recursively) use the section/basic-page template? */
	protected function planUsesSection(array $pages): bool {
		foreach($pages as $p) {
			if(!is_array($p)) continue;
			$t = $this->tplName($p['template'] ?? 'basic-page');
			if($t === 'section' || $t === 'basic-page') return true;
			if(!empty($p['children']) && $this->planUsesSection($p['children'])) return true;
		}
		return false;
	}

	/** Create Olivia's standard styled "section" template + its fields + view. */
	protected function ensureSection(): void {
		$this->createField(['name' => 'headline', 'type' => 'text',     'label' => 'Headline']);
		$this->createField(['name' => 'summary',  'type' => 'textarea', 'label' => 'Summary']);
		$this->createField(['name' => 'body',     'type' => 'textarea', 'label' => 'Body']);
		$this->createField(['name' => 'hero_image', 'type' => 'image', 'label' => 'Hero Image']);

		if($this->wire->templates->get('section')) {
			foreach(['headline', 'summary', 'body', 'hero_image'] as $fname) {
				$this->ensureTemplateField('section', $fname);
			}
			return;
		}

		$this->createTemplate(['name' => 'section', 'label' => 'Section', 'fields' => ['headline', 'summary', 'body', 'hero_image']]);
	}

	/** Add a field to an existing template and record it for rollback. */
	protected function ensureTemplateField(string $tplName, string $fieldName): void {
		$tpl = $this->wire->templates->get($tplName);
		$field = $this->wire->fields->get($fieldName);
		if(!$tpl || !$field || $tpl->fieldgroup->hasField($fieldName)) return;

		try {
			$tpl->fieldgroup->add($field);
			$this->wire->fieldgroups->save($tpl->fieldgroup);
			$this->manifest['template_fields'][] = ['template' => $tplName, 'field' => $fieldName];
		} catch(\Throwable $e) {
			$this->manifest['errors'][] = "template {$tplName}.{$fieldName}: " . $e->getMessage();
		}
	}

	/**
	 * When the Ichiban SEO module is installed, give Olivia's templates its SEO
	 * field ("seo") so the views can render `echo $page->seo` and the owner can
	 * tune SEO per page. Creates the field once (recorded for rollback) and adds
	 * it to the build's templates + the home + section templates.
	 */
	protected function ensureIchibanSeo(): void {
		if(!$this->wire->modules->isInstalled('Ichiban')) return;

		$seo = $this->wire->fields->get('seo');
		if(!$seo) {
			try {
				$f = new Field();
				$f->type = $this->wire->modules->get('FieldtypeIchiban');
				$f->name = 'seo';
				$f->label = 'SEO';
				$this->wire->fields->save($f);
				$this->manifest['fields'][] = 'seo';
			} catch(\Throwable $e) {
				$this->manifest['errors'][] = 'seo field: ' . $e->getMessage();
				return;
			}
		}

		$targets = $this->manifest['templates'] ?? [];
		foreach(['section', 'home'] as $extra) {
			if(!in_array($extra, $targets, true) && $this->wire->templates->get($extra)) $targets[] = $extra;
		}
		foreach($targets as $tplName) $this->ensureTemplateField($tplName, 'seo');
	}

	/**
	 * Store the plan's site-level promo banner on the home page (page meta) so every
	 * generated view can render a top announcement bar. Accepts a string or
	 * {text, link}. Recorded for rollback.
	 */
	protected function applyBanner(array $plan): void {
		$banner = $plan['site']['banner'] ?? '';
		if(is_array($banner)) { $text = trim((string)($banner['text'] ?? '')); $link = trim((string)($banner['link'] ?? '')); }
		else { $text = trim((string) $banner); $link = ''; }
		$text = mb_substr($text, 0, 300);
		$link = $this->safePublicHref($link);
		if($text === '') return;
		try {
			$root = $this->wire->pages->get('/');
			if(trim((string) $root->meta('olivia_banner')) !== '') {
				$this->manifest['reused']['banner'] = true;
				return;
			}
			$root->meta('olivia_banner', $text);
			if($link !== '') $root->meta('olivia_banner_link', $link);
			$this->manifest['banner_set'] = true;
			$this->manifest['banner_values'] = ['text' => $text, 'link' => $link];
		} catch(\Throwable $e) {
			$this->manifest['errors'][] = 'banner: ' . $e->getMessage();
		}
	}

	/** Allow only normal web URLs or root-relative paths in generated public links. */
	protected function safePublicHref(string $value): string {
		$value = trim($value);
		if($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x20]/', $value)) return '';
		if($value[0] === '/') return str_starts_with($value, '//') ? '' : $value;
		$scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
		return in_array($scheme, ['http', 'https'], true) ? $value : '';
	}

	/* ----------------------------------------------------------------- pages */

	protected function createPage(array $def, ?Page $parent): ?Page {
		$title = trim((string)($def['title'] ?? ''));
		if(!$title) return null;

		// resolve parent
		if($parent === null) {
			$parentRef = $def['parent'] ?? '/';
			$parent = $this->resolveParent($parentRef);
		}
		if(!$parent || !$parent->id) {
			$this->manifest['errors'][] = "page {$title}: parent not found (" . (string)($parentRef ?? '/') . ")";
			return null;
		}

		$tplName = $this->tplName($def["template"] ?? "basic-page");
		if($tplName === 'basic-page') $tplName = 'section'; // use Olivia's styled section template
		$tpl = $this->wire->templates->get($tplName);
		if(!$tpl) {
			$this->manifest['errors'][] = "page {$title}: template '{$tplName}' missing";
			return null;
		}

		$name = $this->wire->sanitizer->pageName($title, true);

		// Models often emit a duplicate "Home" page. Map it to the existing root
		// home instead of creating /home/, so its sections land at top level.
		$root = $this->wire->pages->get('/');
		if($parent->id === $root->id && ($name === 'home' || $tplName === 'home')) {
			$this->manifest['reused']['pages'][] = $root->id;
			$this->applyContent($root, $def['content'] ?? [], true);
			foreach(($def['children'] ?? []) as $child) $this->createPage($child, $root);
			return $root;
		}

		$existing = $parent->child("name=$name, include=all");
		$created = false;
		if($existing && $existing->id) {
			$this->manifest['reused']['pages'][] = $existing->id;
			$page = $existing;
		} else {
			try {
				$page = new Page();
				$page->template = $tpl;
				$page->parent = $parent;
				$page->name = $name;
				$page->title = $title;
				$page->save();
				$created = true;
				$this->manifest['pages'][] = $page->id;
				// demo/field content only on pages we create (never overwrite)
				$this->applyContent($page, $def['content'] ?? []);
			} catch(\Throwable $e) {
				$this->manifest['errors'][] = "page {$title}: " . $e->getMessage();
				return null;
			}
		}

		// remember the declared catalog component so the view renders that layout
		$comp = $this->wire->sanitizer->name((string)($def['component'] ?? ''));
		if($created && $comp !== '' && $page->id) {
			try { $page->meta('olivia_component', $comp); } catch(\Throwable $e) {}
		}

		foreach(($def['children'] ?? []) as $child) {
			$this->createPage($child, $page);
		}
		return $page;
	}

	/**
	 * Set field values on a freshly created page. Scalar text/number values
	 * for fields that exist on the page's template. Skips title, missing fields,
	 * and non-scalar values (images/files handled later).
	 */
	protected function applyContent(Page $page, array $content, bool $recordPrevious = false): void {
		if(!$content) return;
		$changed = false;
		$previous = [];
		foreach($content as $fname => $val) {
			$fname = $this->sanitizeName((string) $fname);
			if(!$fname || $fname === 'title') continue;
			if(!$page->template->fieldgroup->hasField($fname)) continue;
			if(is_array($val) || is_object($val)) continue;
			$field = $this->wire->fields->get($fname);
			if(!$field) continue;
			$current = $page->get($fname);
			try {
				if(!$field->type->isEmptyValue($field, $current)) continue;
			} catch(\Throwable $e) {
				if($current !== null && $current !== '') continue;
			}
			$page->set($fname, $val);
			if($recordPrevious) $previous[] = ['page' => $page->id, 'field' => $fname, 'value' => $current];
			$changed = true;
		}
		if($changed) {
			try {
				$page->save();
				foreach($previous as &$item) $item['written'] = $page->get($item['field']);
				unset($item);
				if($previous) array_push($this->manifest['content_values'], ...$previous);
			}
			catch(\Throwable $e) { $this->manifest['errors'][] = "content {$page->name}: " . $e->getMessage(); }
		}
	}

	protected function resolveParent($ref): ?Page {
		if($ref instanceof Page) return $ref;
		$ref = (string)$ref;
		if($ref === '' || $ref === '/') return $this->wire->pages->get('/');
		// try path, then page name under home
		$p = $this->wire->pages->get($ref);
		if($p && $p->id) return $p;
			$p = $this->wire->pages->get("/")->child("name=" . $this->wire->sanitizer->pageName($ref, true));
			return ($p && $p->id) ? $p : null;
		}

	/* --------------------------------------------------------------- helpers */

	protected function sanitizeName($name): string {
		return $this->wire->sanitizer->fieldName((string)$name);
	}

	/** Template/fieldgroup names may contain hyphens (e.g. basic-page). */
	protected function tplName($name): string {
		return $this->wire->sanitizer->name((string)$name);
	}

	protected function labelize(string $name): string {
		return ucwords(str_replace(['_', '-'], ' ', $name));
	}

	protected function flattenPageTitles(array $pages, array &$out = []): array {
		foreach($pages as $p) {
			if(!empty($p['title'])) $out[] = $p['title'];
			if(!empty($p['children'])) $this->flattenPageTitles($p['children'], $out);
		}
		return $out;
	}

	protected function pagePreviewTree(array $pages): array {
		$out = [];
		foreach($pages as $p) {
			if(!is_array($p) || empty($p['title'])) continue;
			$template = $this->tplName($p['template'] ?? 'basic-page') ?: 'basic-page';
			if($template === 'basic-page') $template = 'section';
			$out[] = [
				'title' => (string) $p['title'],
				'template' => $template,
				'children' => !empty($p['children']) && is_array($p['children']) ? $this->pagePreviewTree($p['children']) : [],
			];
		}
		return $out;
	}
}
