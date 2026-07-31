<?php namespace ProcessWire;

/**
 * OliviaValidator
 *
 * Checks and normalizes a build plan before it reaches the builder. Weak/cheap
 * models (DeepSeek, MiniMax, etc.) often return JSON that parses but is wrong:
 * unknown field types, templates that don't exist, content referencing missing
 * fields, missing names. This catches that with clear messages instead of
 * letting Build fail or silently skip things.
 *
 * Result:
 *   [
 *     'ok'       => bool,    // false if any errors (Build should be blocked)
 *     'errors'   => [..],    // hard problems
 *     'warnings' => [..],    // build can proceed, but note these
 *     'plan'     => [..],    // normalized plan (safe names, coerced types)
 *   ]
 */
class OliviaValidator extends Wire {

	/** Allowed plan field types (mirror of OliviaBuilder::$typeMap keys). */
	protected $allowedTypes = [
		'text','textarea','richtext','body','url','email','integer','int',
		'float','decimal','checkbox','datetime','date','image','file','page',
	];

	/** Template names that always exist or Olivia provides at build time. */
	protected $defaultTemplates = ['basic-page','section','home'];

	public function validate(array $plan): array {
		$errors = $warnings = [];

		// ---- top level ----
		foreach(['fields','templates','pages','modules'] as $k) {
			if(!isset($plan[$k]) || !is_array($plan[$k])) $plan[$k] = [];
		}
		if(!isset($plan['site']) || !is_array($plan['site'])) $plan['site'] = [];
		$siteTypes = $this->wire(new OliviaSiteTypes());
		$rawSiteType = trim((string)($plan['site']['type'] ?? ''));
		if($rawSiteType !== '') {
			$canonicalSiteType = $siteTypes->canonical($rawSiteType);
			if($siteTypes->isPromised($rawSiteType) && $canonicalSiteType !== $rawSiteType) {
				$warnings[] = "Site type '$rawSiteType' normalized to '$canonicalSiteType'.";
			}
			$plan['site']['type'] = $canonicalSiteType;
		}

		// ---- theme (font + colour palette): whitelist font, validate hex ----
		$rawTheme = (isset($plan['site']['theme']) && is_array($plan['site']['theme'])) ? $plan['site']['theme'] : [];
		if($rawTheme) {
			$tm = $this->wire(new OliviaTheme());
			$font = $tm->validFont((string)($rawTheme['font'] ?? ''));
			if(($rawTheme['font'] ?? '') !== '' && strcasecmp($font, (string)$rawTheme['font']) !== 0) {
				$warnings[] = "Theme font '" . (string)$rawTheme['font'] . "' is not in the curated set — using '{$font}'.";
			}
			$plan['site']['theme'] = [
				'font'    => $font,
				'primary' => $tm->validHex((string)($rawTheme['primary'] ?? ''), OliviaTheme::DEFAULT_PRIMARY),
			];
		}

		if(!$plan['pages'] && !$plan['templates']) {
			$errors[] = 'Plan has no pages and no templates — nothing to build.';
		}

		// ---- fields ----
		$declaredFields = [];
		$cleanFields = [];
		$renameMap = [];   // original field name => safe renamed field name
		foreach($plan['fields'] as $i => $f) {
			if(!is_array($f)) { $warnings[] = "Field #$i is not an object — skipped."; continue; }
			$raw = $this->wire->sanitizer->fieldName((string)($f['name'] ?? ''));
			if($raw === '') { $warnings[] = "A field has no usable name — skipped."; continue; }
			if($raw === 'title') { $warnings[] = "Field 'title' is built in — skipped."; continue; }

			$collapsed = trim(preg_replace('/_+/', '_', $raw), '_');
			if($collapsed === '') { $warnings[] = "Field '$raw' has no usable name — skipped."; continue; }
			$reserved = $this->wire->fields->isNative($collapsed) || $collapsed !== $raw;

			if(!$reserved) {
				if(isset($declaredFields[$collapsed])) { $warnings[] = "Duplicate field '$collapsed' — kept once."; continue; }
				$name = $collapsed;
			} else {
				// reserved word / double underscore -> rename instead of dropping
				$name = $this->safeFieldName($collapsed, $declaredFields);
				if($name === '') { $warnings[] = "Field '$raw' could not be made safe — skipped."; continue; }
				$renameMap[$raw] = $name;
				$warnings[] = "Field '$raw' is a reserved/invalid name — renamed to '$name'.";
			}

			$type = strtolower((string)($f['type'] ?? 'text'));
			if(!in_array($type, $this->allowedTypes, true)) {
				$warnings[] = "Field '$name' has unknown type '$type' — using 'text'.";
				$type = 'text';
			}
			$f['name'] = $name;
			$f['type'] = $type;
			$declaredFields[$name] = true;
			$cleanFields[] = $f;
		}
		$plan['fields'] = $cleanFields;

		// existing fields are also usable
		$fieldExists = function($name) use ($declaredFields) {
			return isset($declaredFields[$name]) || $this->wire->fields->get($name);
		};

		// ---- templates ----
		$declaredTemplates = [];
		$templateFields = []; // name => [field names]
		$cleanTemplates = [];
		foreach($plan['templates'] as $i => $t) {
			if(!is_array($t)) { $warnings[] = "Template #$i is not an object — skipped."; continue; }
			$name = $this->wire->sanitizer->name((string)($t['name'] ?? ''));
			if($name === '') { $warnings[] = "A template has no usable name — skipped."; continue; }
			if(isset($declaredTemplates[$name])) { $warnings[] = "Duplicate template '$name' — kept once."; continue; }

			$tf = [];
			foreach((array)($t['fields'] ?? []) as $fn) {
				$fn = $this->wire->sanitizer->fieldName((string)$fn);
				if($fn === '' || $fn === 'title') continue;
				$fn = $renameMap[$fn] ?? $fn; // follow reserved-word renames
				if(!$fieldExists($fn)) {
					$warnings[] = "Template '$name' lists field '$fn' that is neither declared nor existing — it will be skipped.";
					continue;
				}
				$tf[] = $fn;
			}
			$t['name'] = $name;
			$t['fields'] = $tf;
			$declaredTemplates[$name] = true;
			$templateFields[$name] = $tf;
			$cleanTemplates[] = $t;
		}
		$plan['templates'] = $cleanTemplates;

		$templateExists = function($name) use ($declaredTemplates) {
			return isset($declaredTemplates[$name])
				|| in_array($name, $this->defaultTemplates, true)
				|| $this->wire->templates->get($name);
		};
		$plannedParentRefs = $this->plannedParentRefs($plan['pages']);
		$parentExists = function($ref) use ($plannedParentRefs) {
			$ref = trim((string) $ref);
			if($ref === '' || $ref === '/') return true;
			$norm = $this->normalizeParentRef($ref);
			if(isset($plannedParentRefs[$norm])) return true;
			$p = $this->wire->pages->get($ref);
			if($p && $p->id) return true;
			$name = $this->wire->sanitizer->pageName($ref, true);
			$p = $this->wire->pages->get('/')->child("name=$name, include=all");
			return $p && $p->id;
		};

		// ---- pages (recursive) ----
		$plan['pages'] = $this->validatePages(
			$plan['pages'], $templateExists, $templateFields, $errors, $warnings, $fieldExists, $renameMap, $parentExists
		);
		$this->validateSiteContract($plan, $warnings);

		return [
			'ok'       => count($errors) === 0,
			'errors'   => $errors,
			'warnings' => $warnings,
			'plan'     => $plan,
		];
	}

	/** Validate the structural promises of Olivia's four first-class site types. */
	protected function validateSiteContract(array &$plan, array &$warnings): void {
		$types = $this->wire(new OliviaSiteTypes());
		$type = $types->canonical((string)($plan['site']['type'] ?? ''));
		if(!$types->isPromised($type)) return;
		$plan['site']['type'] = $type;

		$flatPages = $this->flattenPages($plan['pages']);
		$templateNames = array_column($plan['templates'], 'name');
		$fieldNames = array_column($plan['fields'], 'name');
		$components = array_values(array_filter(array_map(
			fn($p) => strtolower((string)($p['component'] ?? '')),
			$flatPages
		)));
		$titles = array_map(fn($p) => mb_strtolower((string)($p['title'] ?? '')), $flatPages);
		$hasContact = false;
		foreach($titles as $title) {
			if(str_contains($title, 'contact') || str_contains($title, 'enquir')) { $hasContact = true; break; }
		}
		if(!$hasContact) $warnings[] = $types->label($type) . ' should include a Contact or Enquiry page.';

		if($type === OliviaSiteTypes::LANDING) {
			if(count($plan['pages']) > 6) {
				$warnings[] = 'Landing page has more than six top-level sections; keep one focused conversion journey.';
			}
			return;
		}

		if($type === OliviaSiteTypes::BUSINESS) {
			if(count($plan['pages']) < 4) {
				$warnings[] = 'Business website should have at least four useful top-level pages or sections.';
			}
			$hasServices = false;
			foreach($titles as $title) if(str_contains($title, 'service')) { $hasServices = true; break; }
			if(!$hasServices) $warnings[] = 'Business website should include services with useful detail.';
			return;
		}

		if($type === OliviaSiteTypes::CATALOG) {
			if(!in_array('product', $templateNames, true)) {
				$warnings[] = "Catalog should declare a reusable 'product' template.";
			}
			if(!in_array('product-grid', $components, true)) {
				$warnings[] = "Catalog should use component 'product-grid' on its catalog page to enable search and filtering.";
			}
			if(!in_array('product-detail', $components, true)) {
				$warnings[] = "Catalog products should use component 'product-detail' to enable product enquiry.";
			}
			foreach(['summary','body','price','product_category','photo','gallery','availability'] as $field) {
				if(!in_array($field, $fieldNames, true)) {
					$warnings[] = "Catalog product structure is missing '$field'.";
				}
			}
			return;
		}

		if($type === OliviaSiteTypes::STORE) {
			if(!in_array('customer-account', $templateNames, true)) {
				$warnings[] = "Online store should declare a 'customer-account' template for verified registration and order history.";
			}
			if(!$this->planHasModule($plan, 'Mercato')) {
				$plan['modules'][] = [
					'class' => 'Mercato',
					'purpose' => 'Products, cart, checkout, payments, orders, discounts, delivery and inventory',
				];
				$warnings[] = 'Online store requires Mercato; the recommendation was added to the plan.';
			}
		}
	}

	protected function planHasModule(array $plan, string $class): bool {
		foreach(($plan['modules'] ?? []) as $module) {
			$name = is_array($module) ? (string)($module['class'] ?? $module['name'] ?? '') : (string)$module;
			if(strcasecmp($name, $class) === 0) return true;
		}
		return false;
	}

	protected function flattenPages(array $pages, array &$out = []): array {
		foreach($pages as $page) {
			if(!is_array($page)) continue;
			$out[] = $page;
			if(!empty($page['children']) && is_array($page['children'])) {
				$this->flattenPages($page['children'], $out);
			}
		}
		return $out;
	}

	/**
	 * Turn a (possibly reserved/invalid) field name into a safe, unique one.
	 * Collapses double underscores, then if the name is a reserved ProcessWire
	 * word it appends a suffix until it is both non-reserved and not already
	 * declared in this plan.
	 *
	 * @param string $raw already passed through sanitizer->fieldName
	 * @param array $declared map of already-declared field names
	 * @return string safe name, or '' if it cannot be made safe
	 */
	protected function safeFieldName(string $raw, array $declared): string {
		$name = trim(preg_replace('/_+/', '_', $raw), '_');
		if($name === '' || $name === 'title') return '';

		$reserved = fn($n) => $this->wire->fields->isNative($n);

		if(!$reserved($name) && !isset($declared[$name])) return $name; // already fine

		// reserved or taken -> try '<name>_field', then numeric suffixes
		$candidate = $reserved($name) ? $name . '_field' : $name;
		$i = 2;
		while($reserved($candidate) || isset($declared[$candidate])) {
			$candidate = $name . '_' . $i++;
			if($i > 50) return '';
		}
		return $candidate;
	}

	protected function validatePages(array $pages, callable $templateExists, array $templateFields,
		array &$errors, array &$warnings, callable $fieldExists, array $renameMap = [],
		?callable $parentExists = null, int $depth = 0): array {

		if($depth > 6) { $warnings[] = 'Page nesting too deep — truncated.'; return []; }
		$clean = [];

		foreach($pages as $i => $p) {
			if(!is_array($p)) { $warnings[] = "Page #$i is not an object — skipped."; continue; }
			$title = trim((string)($p['title'] ?? ''));
			if($title === '') { $warnings[] = "A page has no title — skipped."; continue; }

			$tpl = $this->wire->sanitizer->name((string)($p['template'] ?? 'basic-page'));
			if($tpl === '') $tpl = 'basic-page';
			if(!$templateExists($tpl)) {
				$errors[] = "Page '$title' uses template '$tpl' which is not in the plan and does not exist — cannot build.";
				continue; // hard error: don't keep an unbuildable page
				}
				$p['template'] = $tpl;

				if($depth === 0 && isset($p['parent']) && $parentExists && !$parentExists($p['parent'])) {
					$errors[] = "Page '$title' uses parent '{$p['parent']}' which does not exist and is not created by this plan — cannot build.";
					continue;
				}

				// content keys must be fields on this template (declared ones only checkable)
			if(isset($p['content']) && is_array($p['content'])) {
				$allowed = $templateFields[$tpl] ?? null; // null = existing template, can't introspect cheaply
				$cleanContent = [];
				foreach($p['content'] as $fn => $val) {
					$fn = $this->wire->sanitizer->fieldName((string)$fn);
					if($fn === '' || $fn === 'title') continue;
					$fn = $renameMap[$fn] ?? $fn; // follow reserved-word renames
					if(is_array($val) || is_object($val)) { $warnings[] = "Page '$title' content '$fn' is not a scalar — skipped."; continue; }
					if($allowed !== null && !in_array($fn, $allowed, true)) {
						$warnings[] = "Page '$title' sets '$fn', not a field on template '$tpl' — skipped.";
						continue;
					}
					$cleanContent[$fn] = $val;
				}
				$p['content'] = $cleanContent;
			}

				if(isset($p['children']) && is_array($p['children'])) {
					$p['children'] = $this->validatePages(
						$p['children'], $templateExists, $templateFields, $errors, $warnings, $fieldExists, $renameMap, $parentExists, $depth + 1
					);
				}

			$clean[] = $p;
		}
			return $clean;
		}

	protected function plannedParentRefs(array $pages, string $base = '/'): array {
		$out = [];
		foreach($pages as $p) {
			if(!is_array($p) || empty($p['title'])) continue;
			$name = $this->wire->sanitizer->pageName((string) $p['title'], true);
			if($name === '') continue;
			$path = rtrim($base, '/') . '/' . $name . '/';
			$out[$this->normalizeParentRef($path)] = true;
			$out[$this->normalizeParentRef($name)] = true;
			if(!empty($p['children']) && is_array($p['children'])) {
				$out += $this->plannedParentRefs($p['children'], $path);
			}
		}
		return $out;
	}

	protected function normalizeParentRef(string $ref): string {
		$ref = trim($ref);
		if($ref === '' || $ref === '/') return '/';
		if(strpos($ref, '/') !== false) return '/' . trim($ref, '/') . '/';
		return $this->wire->sanitizer->pageName($ref, true);
	}
	}
