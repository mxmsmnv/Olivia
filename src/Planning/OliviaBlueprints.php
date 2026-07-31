<?php namespace ProcessWire;

/**
 * OliviaBlueprints — curated vertical blueprints
 *
 * Ready-made, complete site plans for common verticals. They produce the same
 * plan JSON shape as the AI planner, so they flow through validate → preview →
 * build → images unchanged. Value: instant, free (no API call), deterministic,
 * high-quality starting points — and the seed of a future blueprint library.
 *
 * Each blueprint includes image fields + realistic demo content so it shows off
 * the generated views and (if enabled) AI images.
 */
class OliviaBlueprints extends Wire {

	/** id => human label, for a picker. */
	public function listAll(): array {
		return [
			'landing'      => 'Landing page',
			'business'     => 'Business website',
			'catalog'      => 'Catalog',
			'online_store' => 'Online store',
			'restaurant'   => 'Restaurant',
			'photographer' => 'Photographer / Portfolio',
			'agency'       => 'Creative Agency',
		];
	}

	/** Return a plan array for a blueprint id, or null. */
	public function get(string $id): ?array {
		$id = $this->wire->sanitizer->name($id);
		$m = "bp_$id";
		return method_exists($this, $m) ? $this->$m() : null;
	}

	/* ----------------------------------------------------------- blueprints */

	protected function bp_landing(): array {
		return [
			'site' => [
				'title' => 'Northline Workspace',
				'type' => OliviaSiteTypes::LANDING,
				'tagline' => 'A focused workspace reset for growing creative teams.',
			],
			'fields' => [
				['name' => 'summary', 'type' => 'textarea', 'label' => 'Summary'],
				['name' => 'body', 'type' => 'textarea', 'label' => 'Body'],
				['name' => 'photo', 'type' => 'image', 'label' => 'Photo'],
			],
			'templates' => [],
			'pages' => [
				['title' => 'The offer', 'template' => 'section', 'component' => 'hero-split', 'content' => [
					'summary' => 'Plan and install a calmer, more productive studio in ten working days.',
					'body' => "Northline combines space planning, furniture sourcing, and installation in one fixed-scope engagement.\n\nYour team keeps working while we turn an awkward office into a place people are glad to use.",
				]],
				['title' => 'What is included', 'template' => 'section', 'component' => 'feature-grid', 'content' => [
					'summary' => 'Everything required to move from brief to a finished workspace.',
					'body' => "We audit how the team works, map zones and storage, source durable pieces, and coordinate delivery.\n\nThe final handover includes a layout pack and a practical maintenance guide.",
				]],
				['title' => 'Why Northline', 'template' => 'section', 'component' => 'metrics-grid', 'content' => [
					'summary' => 'A small senior team, one accountable lead, and no open-ended design process.',
					'body' => "The engagement is deliberately compact: one discovery session, one design direction, and a clear purchasing schedule.\n\nThat focus keeps decisions moving and costs visible.",
				]],
				['title' => 'How it works', 'template' => 'section', 'component' => 'steps', 'content' => [
					'summary' => 'Brief, plan, approve, install.',
					'body' => "Start with a 45-minute workspace review. Within three days you receive the proposed layout, material direction, and itemised budget.\n\nOnce approved, Northline orders, coordinates, and installs the complete scheme.",
				]],
				['title' => 'Contact', 'template' => 'section', 'content' => [
					'summary' => 'Tell us about your team and the space you want to improve.',
					'body' => "Share the city, approximate floor area, team size, and target date.\n\nWe will reply with fit, availability, and the next practical step.",
				]],
			],
			'modules' => [
				['class' => 'Ichiban', 'purpose' => 'Search previews, page metadata and sitemap controls'],
			],
		];
	}

	protected function bp_business(): array {
		return [
			'site' => [
				'title' => 'Harbour & Field',
				'type' => OliviaSiteTypes::BUSINESS,
				'tagline' => 'Commercial landscape design grounded in how places are actually used.',
			],
			'fields' => [
				['name' => 'summary', 'type' => 'textarea', 'label' => 'Summary'],
				['name' => 'body', 'type' => 'textarea', 'label' => 'Body'],
				['name' => 'photo', 'type' => 'image', 'label' => 'Photo'],
				['name' => 'role', 'type' => 'text', 'label' => 'Role'],
			],
			'templates' => [
				['name' => 'service', 'label' => 'Service', 'fields' => ['summary', 'body', 'photo']],
				['name' => 'project', 'label' => 'Project', 'fields' => ['summary', 'body', 'photo']],
				['name' => 'team_member', 'label' => 'Team member', 'fields' => ['summary', 'body', 'photo', 'role']],
			],
			'pages' => [
				['title' => 'Services', 'template' => 'section', 'component' => 'service-list', 'content' => [
					'summary' => 'Landscape strategy, detailed design, and delivery support.',
					'body' => "Harbour & Field works from early feasibility through construction review.\n\nEach commission is led by a senior designer who remains involved through handover.",
				], 'children' => [
					['title' => 'Landscape strategy', 'template' => 'service', 'content' => ['summary' => 'Site analysis and a practical landscape brief.', 'body' => "We map movement, shade, servicing, ecology, and stakeholder priorities before drawing solutions.\n\nThe resulting strategy gives the wider project team a clear basis for cost and design decisions."]],
					['title' => 'Detailed design', 'template' => 'service', 'content' => ['summary' => 'Coordinated planting, materials, levels, and details.', 'body' => "The design develops into coordinated information suitable for pricing and construction.\n\nWe balance visual character with maintenance, accessibility, climate, and long-term performance."]],
					['title' => 'Delivery support', 'template' => 'service', 'content' => ['summary' => 'Responsive design oversight during construction.', 'body' => "We review samples, answer site queries, and inspect key stages so intent survives real-world constraints.\n\nAt handover, the client receives a clear record and establishment priorities."]],
				]],
				['title' => 'Selected work', 'template' => 'section', 'component' => 'case-study-grid', 'content' => [
					'summary' => 'Workplaces, mixed-use districts, and civic landscapes.',
					'body' => "Our portfolio focuses on places with complex daily use rather than decorative planting alone.\n\nThese examples show how landscape decisions improve arrival, comfort, identity, and resilience.",
				], 'children' => [
					['title' => 'Foundry Courtyard', 'template' => 'project', 'content' => ['summary' => 'A sheltered workplace garden in a converted industrial block.', 'body' => "The courtyard turns a hard service yard into a shared outdoor room with year-round structure.\n\nRobust planting, integrated seating, and clear circulation support lunch, meetings, and evening events."]],
					['title' => 'East Quay Promenade', 'template' => 'project', 'content' => ['summary' => 'A durable waterfront route shaped around wind and tide.', 'body' => "The scheme combines flood-tolerant planting with generous walking and resting space.\n\nA limited material palette makes maintenance straightforward while preserving a distinctive harbour identity."]],
				]],
				['title' => 'About', 'template' => 'section', 'content' => [
					'summary' => 'An independent studio combining design judgement with delivery experience.',
					'body' => "Harbour & Field was founded to give clients direct access to the people doing the work.\n\nThe studio collaborates closely with architects, engineers, contractors, and facilities teams from the first briefing session.",
				]],
				['title' => 'Team', 'template' => 'section', 'component' => 'team-grid', 'content' => [
					'summary' => 'Senior practitioners with complementary design and technical strengths.',
					'body' => "A compact team keeps communication direct and decisions accountable.\n\nSpecialist collaborators join when ecology, lighting, irrigation, or heritage expertise is required.",
				], 'children' => [
					['title' => 'Maya Rowan', 'template' => 'team_member', 'content' => ['role' => 'Studio director', 'summary' => 'Leads strategy and client collaboration.', 'body' => "Maya has fifteen years of experience across civic and commercial landscape projects.\n\nShe focuses on clear briefs, useful public space, and design decisions that survive delivery."]],
					['title' => 'Eli Mercer', 'template' => 'team_member', 'content' => ['role' => 'Technical lead', 'summary' => 'Turns design intent into buildable information.', 'body' => "Eli coordinates levels, materials, planting systems, and construction details.\n\nHis work connects visual quality with safety, maintenance, and realistic procurement."]],
				]],
				['title' => 'Contact', 'template' => 'section', 'content' => [
					'summary' => 'Start with the site, programme, and decision you need to make.',
					'body' => "Send a short project outline, location, target programme, and any available drawings.\n\nA studio director will respond with relevant experience and a sensible route forward.",
				]],
			],
			'modules' => [
				['class' => 'Ichiban', 'purpose' => 'Advanced page metadata, search previews and sitemap controls'],
			],
		];
	}

	protected function bp_catalog(): array {
		return [
			'site' => [
				'title' => 'Alder Workshop',
				'type' => OliviaSiteTypes::CATALOG,
				'tagline' => 'Architectural hardware made in small batches and specified with confidence.',
			],
			'fields' => [
				['name' => 'summary', 'type' => 'textarea', 'label' => 'Summary'],
				['name' => 'body', 'type' => 'textarea', 'label' => 'Body'],
				['name' => 'price', 'type' => 'float', 'label' => 'Guide price'],
				['name' => 'product_category', 'type' => 'text', 'label' => 'Category'],
				['name' => 'availability', 'type' => 'text', 'label' => 'Availability'],
				['name' => 'photo', 'type' => 'image', 'label' => 'Product photo'],
				['name' => 'gallery', 'type' => 'image', 'label' => 'Product gallery'],
			],
			'templates' => [
				['name' => 'product', 'label' => 'Product', 'fields' => ['summary', 'body', 'price', 'product_category', 'availability', 'photo', 'gallery']],
			],
			'pages' => [
				['title' => 'Products', 'template' => 'section', 'component' => 'product-grid', 'content' => [
					'summary' => 'Browse the collection by name or category, then request specifications for any piece.',
					'body' => "Alder hardware is produced in compatible families for doors, cabinets, and built-in furniture.\n\nGuide prices support early budgeting; final quotations reflect finish, quantity, and project schedule.",
				], 'children' => [
					['title' => 'Arc Lever Handle', 'template' => 'product', 'component' => 'product-detail', 'content' => ['summary' => 'A balanced solid-brass lever with a softened return.', 'body' => "The Arc lever is cast, machined, and hand-finished for residential and hospitality doors. Its compact rose suits both new joinery and restrained refurbishment work.\n\nAvailable in satin brass, dark bronze, and brushed nickel, with matching privacy and key escutcheons.", 'price' => 185, 'product_category' => 'Door hardware', 'availability' => 'Made to order · 4-6 weeks']],
					['title' => 'Line Cabinet Pull', 'template' => 'product', 'component' => 'product-detail', 'content' => ['summary' => 'A slim pull with generous finger clearance and quiet detailing.', 'body' => "Line is designed for kitchens, wardrobes, and fitted furniture where a precise rhythm matters. Three lengths share the same section and fixing language.\n\nEach pull includes concealed machine fixings and a drilling template for consistent installation.", 'price' => 72, 'product_category' => 'Cabinet hardware', 'availability' => 'In stock in satin brass']],
					['title' => 'Pivot Coat Hook', 'template' => 'product', 'component' => 'product-detail', 'content' => ['summary' => 'A compact wall hook that folds back when not in use.', 'body' => "Pivot provides useful hanging capacity in narrow halls, guest rooms, and changing areas without a permanent projection. A damped stop keeps the movement deliberate.\n\nSpecify it individually or in aligned groups on timber, stone, or painted wall panels.", 'price' => 58, 'product_category' => 'Accessories', 'availability' => 'Made to order · 3-4 weeks']],
					['title' => 'Plate Door Stop', 'template' => 'product', 'component' => 'product-detail', 'content' => ['summary' => 'A low-profile floor stop with a replaceable rubber buffer.', 'body' => "The broad circular base spreads impact and gives the stop a calm architectural presence. Countersunk fixings sit beneath a removable top plate.\n\nReplacement buffers are available separately so the fitting can remain in service for years.", 'price' => 64, 'product_category' => 'Door hardware', 'availability' => 'In stock in three finishes']],
				]],
				['title' => 'Materials & finishes', 'template' => 'section', 'component' => 'feature-rows', 'content' => [
					'summary' => 'Living finishes, clear samples, and practical maintenance guidance.',
					'body' => "Every finish is shown on the same base material so comparisons remain meaningful.\n\nSample plates are available for design review and client approval before a project order is released.",
				]],
				['title' => 'About', 'template' => 'section', 'content' => [
					'summary' => 'Hardware designed alongside architects, makers, and installers.',
					'body' => "Alder Workshop develops small families of fittings instead of an endless collection of unrelated shapes.\n\nThat approach makes specification simpler and gives complete interiors a consistent tactile language.",
				]],
				['title' => 'Contact', 'template' => 'section', 'content' => [
					'summary' => 'Request samples, technical data, or a project quotation.',
					'body' => "Tell us which products and finishes you are considering, together with approximate quantities and programme.\n\nWe will respond with drawings, current lead times, and the right next step.",
				]],
			],
			'modules' => [
				['class' => 'Ichiban', 'purpose' => 'Product metadata, search previews and sitemap controls'],
			],
		];
	}

	protected function bp_online_store(): array {
		return [
			'site' => [
				'title' => 'Field Objects',
				'type' => OliviaSiteTypes::STORE,
				'tagline' => 'Useful home objects from independent workshops.',
			],
			'fields' => [
				['name' => 'summary', 'type' => 'textarea', 'label' => 'Summary'],
				['name' => 'body', 'type' => 'textarea', 'label' => 'Body'],
			],
			'templates' => [
				['name' => 'customer-account', 'label' => 'Customer account', 'fields' => ['summary', 'body']],
			],
			'pages' => [
				['title' => 'Our approach', 'template' => 'section', 'component' => 'intro-split', 'content' => [
					'summary' => 'A considered edit of durable objects for daily use.',
					'body' => "Field Objects works with independent studios that care about material, repair, and useful proportions.\n\nMercato supplies the transactional storefront; this page gives the shop a distinct editorial point of view.",
				]],
				['title' => 'Materials', 'template' => 'section', 'component' => 'feature-grid', 'content' => [
					'summary' => 'Natural materials selected for character and long service.',
					'body' => "The collection prioritises solid timber, fired clay, woven fibres, glass, and repairable metalwork.\n\nProduct pages explain care, stock state, shipping notes, and the variation customers should expect.",
				]],
				['title' => 'Contact', 'template' => 'section', 'content' => [
					'summary' => 'Questions about a product, delivery, or an existing order are welcome.',
					'body' => "Include the product name or order reference so the team can answer quickly.\n\nFor returns and delivery terms, review the policy pages created by the commerce system before checkout.",
				]],
				['title' => 'Account', 'template' => 'customer-account', 'content' => [
					'summary' => 'Create an account, sign in, and review orders associated with your email.',
					'body' => "Your account keeps order history connected without exposing payment credentials.\n\nCheckout, fulfilment, receipts, and order records remain managed by Mercato.",
				]],
			],
			'modules' => [
				['class' => 'Mercato', 'purpose' => 'Products, cart, checkout, payments, orders, discounts, delivery and inventory'],
			],
		];
	}

	protected function bp_restaurant(): array {
		return [
			'site' => ['title' => 'Olive & Ember', 'type' => 'restaurant', 'tagline' => 'Wood-fired Mediterranean cooking in the heart of town.'],
			'fields' => [
				['name' => 'summary', 'type' => 'textarea', 'label' => 'Summary'],
				['name' => 'body', 'type' => 'textarea', 'label' => 'Body'],
				['name' => 'price', 'type' => 'float', 'label' => 'Price'],
				['name' => 'photo', 'type' => 'image', 'label' => 'Photo'],
			],
			'templates' => [
				['name' => 'dish', 'label' => 'Dish', 'fields' => ['summary', 'price', 'photo']],
			],
			'pages' => [
				['title' => 'About', 'template' => 'section', 'content' => ['summary' => 'A neighbourhood kitchen built around an open wood fire and seasonal produce.', 'body' => "We opened Olive & Ember to share honest Mediterranean food in a warm, unpretentious room. Everything is made in-house, from the bread to the slow-cooked lamb."]],
				['title' => 'Menu', 'template' => 'section', 'content' => ['summary' => 'Seasonal small plates and wood-fired mains.'], 'children' => [
					['title' => 'Charred Octopus', 'template' => 'dish', 'content' => ['summary' => 'Smoky octopus, salsa verde, confit potato.', 'price' => 18]],
					['title' => 'Lamb Shoulder', 'template' => 'dish', 'content' => ['summary' => 'Slow-roasted lamb, harissa yoghurt, herbs.', 'price' => 26]],
					['title' => 'Wild Mushroom Flatbread', 'template' => 'dish', 'content' => ['summary' => 'Wood-fired flatbread, taleggio, thyme.', 'price' => 15]],
				]],
				['title' => 'Gallery', 'template' => 'section', 'content' => ['summary' => 'Inside the room and around the fire.'], 'children' => [
					['title' => 'The Dining Room', 'template' => 'dish', 'content' => ['summary' => 'Warm lighting and an open kitchen.']],
					['title' => 'The Wood Fire', 'template' => 'dish', 'content' => ['summary' => 'The heart of the kitchen.']],
				]],
				['title' => 'Contact', 'template' => 'section', 'content' => ['summary' => 'Find us, or book a table.', 'body' => "123 Harbour Street\nOpen Tue–Sun, 5pm–late\nhello@oliveandember.example"]],
			],
			'modules' => ['FormBuilder', 'Vox', 'ProCache'],
		];
	}

	protected function bp_photographer(): array {
		return [
			'site' => ['title' => 'Mara Vance', 'type' => 'photography portfolio', 'tagline' => 'Documentary & portrait photography, natural light.'],
			'fields' => [
				['name' => 'summary', 'type' => 'textarea', 'label' => 'Summary'],
				['name' => 'body', 'type' => 'textarea', 'label' => 'Body'],
				['name' => 'photo', 'type' => 'image', 'label' => 'Photo'],
			],
			'templates' => [
				['name' => 'project', 'label' => 'Project', 'fields' => ['summary', 'body', 'photo']],
			],
			'pages' => [
				['title' => 'Work', 'template' => 'section', 'content' => ['summary' => 'Selected projects and commissions.'], 'children' => [
					['title' => 'Coastal Mornings', 'template' => 'project', 'content' => ['summary' => 'A series shot along the northern coast at dawn.']],
					['title' => 'City in Motion', 'template' => 'project', 'content' => ['summary' => 'Street photography across a week in the city.']],
					['title' => 'Portraits', 'template' => 'project', 'content' => ['summary' => 'Natural-light portraits of artists and makers.']],
				]],
				['title' => 'About', 'template' => 'section', 'content' => ['summary' => 'Photographer based wherever the light is good.', 'body' => "I make quiet, honest images — for editorial, brands and people who want to remember a moment as it really felt."]],
				['title' => 'Contact', 'template' => 'section', 'content' => ['summary' => 'Available for commissions worldwide.', 'body' => "studio@maravance.example"]],
			],
			'modules' => ['ProCache', 'MarkupSrcSet'],
		];
	}

	protected function bp_agency(): array {
		return [
			'site' => ['title' => 'Northwind Studio', 'type' => 'creative agency', 'tagline' => 'Brand, web and product design for ambitious teams.'],
			'fields' => [
				['name' => 'summary', 'type' => 'textarea', 'label' => 'Summary'],
				['name' => 'body', 'type' => 'textarea', 'label' => 'Body'],
				['name' => 'photo', 'type' => 'image', 'label' => 'Photo'],
			],
			'templates' => [
				['name' => 'service_item', 'label' => 'Service', 'fields' => ['summary', 'body']],
				['name' => 'case_study', 'label' => 'Case Study', 'fields' => ['summary', 'body', 'photo']],
			],
			'pages' => [
				['title' => 'Services', 'template' => 'section', 'content' => ['summary' => 'What we do.'], 'children' => [
					['title' => 'Brand Identity', 'template' => 'service_item', 'content' => ['summary' => 'Names, logos and systems that last.']],
					['title' => 'Web & Product', 'template' => 'service_item', 'content' => ['summary' => 'Sites and apps that feel effortless.']],
					['title' => 'Strategy', 'template' => 'service_item', 'content' => ['summary' => 'Positioning and direction before pixels.']],
				]],
				['title' => 'Work', 'template' => 'section', 'content' => ['summary' => 'Selected client projects.'], 'children' => [
					['title' => 'Fintech Rebrand', 'template' => 'case_study', 'content' => ['summary' => 'A complete identity and site for a growing fintech.']],
					['title' => 'Marketplace App', 'template' => 'case_study', 'content' => ['summary' => 'Product design for a two-sided marketplace.']],
				]],
				['title' => 'About', 'template' => 'section', 'content' => ['summary' => 'A small team that ships.', 'body' => "We're a compact, senior team — strategy, design and engineering working side by side."]],
				['title' => 'Contact', 'template' => 'section', 'content' => ['summary' => "Let's build something.", 'body' => "hello@northwind.example"]],
			],
			'modules' => ['FormBuilder', 'ProCache'],
		];
	}
}
