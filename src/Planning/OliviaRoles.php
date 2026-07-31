<?php namespace ProcessWire;

/**
 * OliviaRoles — model routing per role.
 *
 * Olivia does several different AI jobs, and models differ in what they're good
 * at. Roles let you point each job at the model best suited to it. Squad stays
 * the provider gateway (keys + default model live there); Olivia just adds the
 * role → model map on top, configured on the Olivia module screen.
 *
 *   developer   — plans the site (structure, templates, pages: the build plan) + interview
 *   designer    — reviews & improves design/UX/SEO (Operate / audit)
 *   content     — writes page copy (AI content fill)
 *   illustrator — generates images (the GrokImagine image model)
 *
 * A blank role model means "use the provider default" — nothing changes unless
 * you opt a role onto a specific model.
 */
class OliviaRoles extends Wire {

	const ROLES = [
		'developer'   => 'Plans the site — structure, templates and pages (the build plan), and the interview.',
		'designer'    => 'Reviews and improves design, UX and SEO (the Operate / audit pass).',
		'content'     => 'Writes page body copy (AI content fill).',
		'seo'         => 'Writes SEO text — meta descriptions, titles, schema copy (Ichiban-aligned).',
		'editor'      => 'Proofreads and tightens generated copy. Runs only when you set a model for it.',
		'artdirector' => 'Writes the image prompts the illustrator renders. Runs only when you set a model.',
		'illustrator' => 'Generates images (image model, via GrokImagine).',
		'translator'  => 'Translates / localizes copy for multilingual sites.',
		'visual'      => 'Analyzes reference screenshots into design tokens and component direction.',
	];

	public function roles(): array { return self::ROLES; }

	protected function cfg(): array {
		return $this->wire->modules->getModuleConfigData('Olivia') ?: [];
	}

	/** The model id configured for a role, or '' to use the provider default. */
	public function model(string $role): string {
		return trim((string)($this->cfg()['role_' . $role . '_model'] ?? ''));
	}

	/** True when a model is explicitly assigned (gates opt-in roles like editor). */
	public function enabled(string $role): bool {
		return $this->model($role) !== '';
	}

	/**
	 * Squad ask() options for a text role: ['model' => '...'] when configured,
	 * else [] so Squad's default model is used. Merge LAST-but-one in ask() so an
	 * explicit per-call override still wins.
	 */
	public function options(string $role): array {
		$m = $this->model($role);
		return $m !== '' ? ['model' => $m] : [];
	}
}
