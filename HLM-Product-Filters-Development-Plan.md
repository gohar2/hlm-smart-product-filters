# HLM Product Filters - Low-Level Development Plan

## 0) Purpose and scope
This document defines a complete, low-level development plan for an advanced, smart WooCommerce product filter plugin built from scratch. It is independent from any existing plugin architecture. The plan includes requirements, architecture, data flow, admin configuration, frontend UI, query processing, AJAX transport, caching, extensibility, security, testing, and deliverables. The initial release must support filtering by Color (variation attribute), Breeds, Category, Size, Gender, and Tags, while being extensible for future attributes and filter types.

## 1) Product requirements (complete feature set)
- Dynamic filter builder with unlimited filters
- Attribute, category, tag, and custom taxonomy support
- Variation-aware attribute filtering (Color)
- Filter visibility rules per category and per tag
- Hide empty options using accurate counts
- AND/OR logic per filter (multi-select)
- URL synchronization for shareable filter URLs
- AJAX filtering without page reload
- Filter state persistence (back/forward support)
- Clear All and Reset controls
- Sorting integration (menu order, popularity, rating, newest/date, price ASC/DESC, title, attribute term)
- Performance optimized for large catalogs
- Accessibility support (ARIA, keyboard navigation)
- Hooks (filters/actions) for extension
- Theme and Elementor compatibility

## 2) Architecture overview and data flow
- High-level flow:
  1) Admin defines filters and settings.
  2) Shortcode renders UI using config.
  3) JS reads form state and sends AJAX request.
  4) Server builds WP_Query args, returns product loop HTML and pagination.
  5) JS updates product list, updates URL, and handles history.
- Core modules:
  - Admin: settings screens and config sanitization
  - Data: attribute and taxonomy discovery
  - Query: filter processor + facet count calculator
  - Ajax: request handlers
  - Rendering: templates for filter UI and product loops
  - Cache: transient/object cache helpers
  - Frontend: JS UI behavior and state

## 3) Plugin skeleton and standard structure
- Folder layout:
  - hlm-smart-product-filters/
    - hlm-smart-product-filters.php (bootstrap)
    - composer.json
    - src/
      - Admin/
      - Ajax/
      - Cache/
      - Data/
      - Frontend/
      - Query/
      - Rendering/
      - Support/
    - assets/
      - css/
      - js/
    - templates/
    - languages/
- PSR-4 namespace: HLM\Filters\ mapped to src/
- Bootstrap responsibilities:
  - define constants (PATH, URL, VERSION)
  - check WooCommerce activation
  - register autoloader
  - instantiate core services
  - register activation/deactivation hooks

## 4) Configuration model (admin-driven)
- Single option key: hlm_filters_config
- Schema version: integer in config
- Global settings:
  - enable_ajax (bool)
  - enable_cache (bool)
  - cache_ttl_seconds (int)
  - products_per_page (int)
  - default_sort (string)
  - debug_mode (bool)
  - enable_apply_button (bool)
  - enable_counts (bool)
- Filter definition schema (ordered list):
  - id (string, unique)
  - label (string)
  - key (string used in query string)
  - type (enum: checkbox, swatch, dropdown, range)
  - data_source (enum: taxonomy, attribute, product_cat, product_tag)
  - source_key (taxonomy or attribute slug)
  - ui:
    - style (swatch, list, dropdown)
    - swatch_type (color, image, text)
    - swatch_map (term_id => color/image/text)
    - show_more_threshold (int)
  - behavior:
    - multi_select (bool)
    - operator (AND/OR)
  - visibility:
    - show_on_categories (array of term IDs)
    - hide_on_categories (array of term IDs)
    - include_children (bool)
    - show_on_tags (array of term IDs)
    - hide_on_tags (array of term IDs)
    - include_tag_children (bool, if tag hierarchies are used)
    - hide_empty (bool)
- Sanitization:
  - strict types and defaults
  - remove unknown keys
  - normalize taxonomy slugs

## 5) Admin UI (complete dashboard)
- Admin menu:
  - WooCommerce -> HLM Product Filters
- Pages:
  - Filters builder
  - Global settings
  - Import/Export
- Filters builder features:
  - Add/edit/delete filters
  - Drag-and-drop ordering
  - Attribute/taxonomy selector
  - Auto-fill label + key
  - Type switch (checkbox/swatch/dropdown/range)
  - Visibility rules (include/exclude categories, include children)
  - Tag visibility rules (include/exclude tags, optional hierarchy handling)
  - Hide empty toggle
  - Swatch mapping UI for Color
  - Preview of filter output
- Global settings:
  - Enable/disable AJAX
  - Cache TTL
  - Products per page
  - Default sort
  - Enable counts
  - Enable apply button (instead of instant filtering)
- Import/Export:
  - JSON export of full config
  - JSON import with validation
- Admin assets:
  - sortable list
  - dynamic form fields
  - WordPress color picker + media uploader
  - tag/category selectors with search

## 6) Data layer and attribute discovery
- Data\AttributeRepository:
  - list available WooCommerce attributes
  - map raw slugs to taxonomy names (pa_*)
- Data\TaxonomyRepository:
  - list product_cat tree
  - list product_tag terms
  - resolve term labels and counts
- Attribute mapping defaults (initial release):
  - Color -> pa_color (variation-aware)
  - Breeds -> pa_breeds
  - Size -> pa_size
  - Gender -> pa_gender
  - Category -> product_cat
  - Tags -> product_tag
- Admin can change mapping without code changes

## 7) Query engine (filter application)
- Query\FilterProcessor:
  - Accept request params + config
  - Build WP_Query args
  - Apply taxonomy filters
  - Apply category and tag scope
  - Apply search term (s)
  - Apply AND/OR logic for multi-select
  - Preserve sort parameters
- Query\FilterValidator:
  - Validate request values against known terms
  - Normalize arrays and comma-separated lists
- Query\QueryMerger:
  - Merge constraints into main query
  - Avoid empty post__in causing 0=1

## 8) Facet counts and availability
- Query\FacetCalculator:
  - Compute term counts based on active filters
  - Exclude the current facet from its own counts
  - Use WooCommerce product_attributes_lookup table if available
  - Fallback to WP_Query when lookup table missing
- Batch queries:
  - One query per taxonomy, not per term
  - Cache results keyed by filter state hash
- Hide empty:
  - If enabled, exclude terms with count = 0

## 9) Variation-aware Color support
- For Color attribute:
  - Use product_attributes_lookup to include variations
  - Ensure parent products are returned
  - Fallback to taxonomy query if lookup disabled
- Swatch display:
  - Color value from term meta or swatch_map
  - Optional image-based swatches

## 10) Frontend UI rendering
- Shortcode: [hlm_smart_product_filters]
- Rendering engine:
  - Templates in templates/ with override support
- UI elements:
  - Checkbox list
  - Swatch grid
  - Dropdown
  - Range slider (future)
- Show more toggle:
  - Expand/collapse term list above threshold
- Clear All button:
  - Reset filters while preserving search term
- URL sync:
  - Maintain filters in query string for shareability
- Tag archive support:
  - Render filters on product_tag archives via template integration
  - Allow shortcode placement on tag templates and tag pages

## 11) AJAX transport
- AJAX endpoints:
  - wp_ajax_hlm_apply_filters
  - wp_ajax_nopriv_hlm_apply_filters
  - wp_ajax_hlm_clear_filters
  - wp_ajax_nopriv_hlm_clear_filters
- Request format:
  - Serialized filter form
  - Current category
  - Current tag
  - Search term
  - Sort parameters
- Response format:
  - HTML for product loop
  - Pagination HTML
  - Optional JSON metadata (counts, total)
- Security:
  - Nonce verification
  - Signed request with time-bucket hash
  - Capability checks for admin-only actions

## 12) Frontend product rendering
- Rendering modes:
  - Use standard WooCommerce loop (default)
  - Optional custom template ID for Elementor
- Pagination:
  - Build pagination links that preserve filters
- Maintain accessibility:
  - ARIA labels on filters
  - Keyboard navigation for swatches

## 13) Caching strategy
- Cache types:
  - Facet counts
  - Filtered query results (optional)
- Cache keys:
  - category
  - tag
  - filter state hash
  - user role (if needed)
- Cache invalidation:
  - on product save
  - on term update
  - on config save
- Prefer object cache, fallback to transients

## 14) Hooks and extensibility
- Filters:
  - hlm_filters_config
  - hlm_filters_query_args
  - hlm_filters_facet_counts
  - hlm_filters_render_item
  - hlm_filters_ajax_permission
  - hlm_filters_signature_window
- Actions:
  - hlm_filters_before_render
  - hlm_filters_after_render
  - hlm_filters_cache_cleared

## 15) Security and validation
- Sanitize all inputs
- Escape outputs in templates
- Nonce + signature for AJAX
- Prevent SQL injection in custom queries

## 16) Testing and QA
- Unit tests:
  - config sanitization
  - query builder
  - facet counts
- Integration tests:
  - category pages
  - tag pages
  - search pages
  - variable products
  - AJAX apply/clear
- Manual QA checklist:
  - filter order
  - visibility rules
  - empty filters hidden
  - URL sharing

## 17) Performance targets
- Filter render time under 200ms on 100k products
- Facet count computation under 300ms (cached)
- AJAX response under 500ms average

## 18) Deliverables
- Plugin zip
- Admin usage guide
- Shortcode examples
- Developer hook reference
