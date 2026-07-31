<?php namespace ProcessWire;

/**
 * Product-level site contracts Olivia promises to build.
 *
 * These profiles keep planner guidance, deterministic blueprints, validation,
 * and runtime dependency checks aligned. A visual product grid is a catalog;
 * it is not an online store until a commerce runtime provides checkout,
 * payments, orders, fulfilment, discounts, customers, and inventory.
 */
class OliviaSiteTypes extends Wire {

	public const LANDING = 'landing_page';
	public const BUSINESS = 'business_website';
	public const CATALOG = 'catalog';
	public const STORE = 'online_store';

	public function profiles(): array {
		return [
			self::LANDING => [
				'label' => 'Landing page',
				'aliases' => ['landing', 'landing page', 'one page', 'one-page', 'single page', 'single-page'],
				'summary' => 'One focused offer with a compact conversion path.',
				'requiredModules' => [],
			],
			self::BUSINESS => [
				'label' => 'Business website',
				'aliases' => ['business', 'business website', 'company website', 'corporate website', 'brochure website'],
				'summary' => 'A multi-page company presence for several services and trust signals.',
				'requiredModules' => [],
			],
			self::CATALOG => [
				'label' => 'Catalog',
				'aliases' => ['catalog', 'catalogue', 'catalog website', 'catalogue website', 'product catalog', 'product catalogue'],
				'summary' => 'Searchable and filterable products or services with detailed enquiry pages.',
				'requiredModules' => [],
			],
			self::STORE => [
				'label' => 'Online store',
				'aliases' => ['online store', 'store', 'shop', 'webshop', 'ecommerce', 'e-commerce', 'online shop'],
				'summary' => 'A transactional catalog backed by real commerce workflows.',
				'requiredModules' => ['Mercato'],
			],
		];
	}

	/** Return a canonical promised type, or the sanitized original for other verticals. */
	public function canonical(string $type): string {
		$raw = trim(mb_strtolower($type));
		$comparable = trim((string) preg_replace('/[\s_-]+/', ' ', $raw));
		foreach($this->profiles() as $id => $profile) {
			if($raw === $id) return $id;
			foreach($profile['aliases'] as $alias) {
				if($comparable === trim((string) preg_replace('/[\s_-]+/', ' ', $alias))) return $id;
			}
		}
		return $this->wire->sanitizer->name($type);
	}

	public function isPromised(string $type): bool {
		return isset($this->profiles()[$this->canonical($type)]);
	}

	public function requiredModules(array $plan): array {
		$type = $this->canonical((string)($plan['site']['type'] ?? ''));
		return array_values($this->profiles()[$type]['requiredModules'] ?? []);
	}

	public function label(string $type): string {
		$id = $this->canonical($type);
		return (string)($this->profiles()[$id]['label'] ?? $type);
	}

	/** Planner addendum derived from the four public product promises. */
	public function plannerGuidance(): string {
		return <<<'TXT'

MINIMUM SITE-TYPE CONTRACTS — classify a matching request with the exact site.type shown:

1. "landing_page"
- One product, service, campaign, or other focused offer.
- Keep the public journey compact: usually up to five purposeful top-level sections.
- Include benefits/value, proof, a clear repeated CTA, contact/lead form, social links when supplied,
  mobile-first layout, basic SEO-ready copy, and an analytics-ready structure.

2. "business_website"
- A multi-page company site for several services or areas of expertise.
- Include a strong home journey plus About, Services (with real service detail pages), proof/work/team
  as appropriate, optional News/Blog when useful, and Contact with a tailored enquiry form.
- Provide enhanced SEO-ready copy, social/contact URLs when supplied, mobile navigation, and an
  analytics-ready structure. Prefer roughly 5-10 useful public pages over shallow filler.

3. "catalog"
- A non-transactional product or service catalog. Use one Products/Catalog page with
  component "product-grid" and product children using a custom "product" template.
- Design the information architecture, categories, search, and pagination-ready collections to support
  at least 50 listings. The generated sample may contain fewer representative products.
- Product fields MUST include summary, body, price, product_category, photo, gallery, and availability.
- Every product child MUST set component "product-detail", have realistic detail copy, and support an
  enquiry about that exact product. Olivia supplies catalog search, category filtering, image galleries,
  and the per-product enquiry form from this structure.
- Add Contact and useful company/policy content. Do not add cart or checkout to a catalog.

4. "online_store"
- A transactional store requires the Mercato module. Include
  {"class":"Mercato","purpose":"Products, cart, checkout, payments, orders, discounts, delivery and inventory"}
  in modules. Do NOT invent parallel cart/order/payment fields or Olivia-owned product templates.
- Mercato owns products, collections, product search/filtering, cart, checkout, customer/order records,
  payments (including Stripe), discounts, fulfilment/shipping, and stock. Olivia may plan complementary
  brand, editorial, about, contact, and campaign sections around that commerce runtime.
- Plan collections and navigation for at least 100 products without treating that number as a hard cap.
  Stripe and other production payment methods remain disabled until the owner supplies and verifies keys.
- Add a public "Account" page using a custom "customer-account" template with summary and body fields.
  Olivia supplies CSRF-protected customer registration, login/logout, and the signed-in customer's
  Mercato order history from that exact template. Do not invent separate customer/order fields.
- Never represent a visual product grid, a contact-order form, or a Stripe link as a complete online store.

For all four types, produce responsive public content, a Contact path, realistic copy, image fields for
visual content, and SEO-ready titles/summaries. Analytics and social integrations require account IDs or
URLs from the owner; structure for them but never invent credentials or tracking IDs.
TXT;
	}
}
