# Olivia component catalog

Olivia builds sites from a **fixed vocabulary of section components** rather than
ad-hoc layout. This folder is that vocabulary.

- `catalog.json` — every component **type** Olivia knows: `id`, `name`,
  `category`, `purpose`, `needs` (the data a section must provide), and `status`:
  - `rendered` — the view generator (`src/Build/OliviaViewGenerator.php`) already
    outputs it (hero, card-grid, team-grid, pricing-cards, faq-accordion,
    stats-band, gallery, cta-band, contact-form, navbar, footer, prose).
  - `reference` — a ready-to-wire Tailwind archetype is named by `reference`
    (or defaults to `references/<id>.html`) but the generator does not
    auto-produce that specific variant yet.
- `references/<id>.html` — reference Tailwind markup for a component. Brand color
  via the `brand` theme color, gradient-free, shadow-light, md radii — must match
  the generator's style so a wired component looks native.

The catalog currently contains **119 patterns across 13 categories**: navigation,
heroes, content, media, people, social proof, commerce, conversion, data,
real estate, careers, help, feedback, and the core rendered utility patterns.
Shared archetype snippets deliberately cover related variants without copying a
third-party design system.

**97 patterns are rendered today.** Closely related variants intentionally share
deterministic layout engines: cards, people, pricing, testimonials, features,
alternating rows, logo clouds, lists, metrics, timelines, tables, galleries and
media-led detail pages. The remaining 22 patterns stay reference-only until their
interaction or data contract can be implemented honestly.

Read it from PHP via `OliviaComponents` (`all()`, `get($id)`, `reference($id)`,
`categories()`, `validate()`, `vocabulary()`). The planner receives rendered
components only through `vocabulary()`; pass `true` to include reference patterns
for catalog tooling and future renderer work. `referenceVocabulary()` contributes
a compact category taxonomy to planning as structural inspiration, explicitly
forbidding those ids from being forced until their renderer is implemented.

## Adding components from a design system

To import components from a design system, for each component:
1. add an entry to `catalog.json` (`status: "reference"`),
2. point `reference` at a compatible shared archetype or add
   `references/<id>.html` (restyled to Olivia's tokens — brand color, no heavy
   shadows, md radii),
3. (optionally) wire it into `OliviaViewGenerator` and flip its status to
   `rendered`.

Run `OliviaComponents::validate()` after edits. It rejects malformed or duplicate
ids, incomplete entries, unknown statuses and reference components whose snippet
cannot be resolved.

Olivia can't scrape an external design system on its own — point it at the
system's docs/URL and the components get catalogued here.

### Catalogued sources

The component **taxonomy** (names + purpose — facts, not their code) has been
imported from these design systems, recorded under `_meta.sources` in
`catalog.json`. Olivia implements its own Tailwind markup in its tokens — it does
not copy their CSS/components.

- **Cedar (REI)** — https://cedar.rei.com/
- **Sainsbury's Design System** — https://design-systems.sainsburys.co.uk/

New site patterns promoted into the active palette from these: `banner`,
`carousel`, `rating`, `alert` (reference snippets in `references/`).
