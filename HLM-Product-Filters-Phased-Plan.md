# HLM Product Filters - Phased Development Plan

This document breaks the full development plan into smaller, usable phases. Each phase includes at least one feature and builds on the previous phase. Start with core structure and progress toward advanced features.

## Phase 1: Core plugin skeleton (foundation)
- Plugin bootstrap with constants, autoloader, and WooCommerce checks
- Base folder structure (Admin, Data, Query, Ajax, Rendering, Frontend, Cache)
- Activation/deactivation hooks
- Minimal service container or bootstrap wiring

## Phase 2: Configuration model (data contract)
- `hlm_filters_config` option storage
- Config schema with defaults and sanitization
- Basic admin settings page to save global settings

## Phase 3: Data layer discovery (taxonomy + attributes)
- Attribute repository (list attributes, map to `pa_*`)
- Taxonomy repository (categories, tags)
- Initial attribute mapping defaults (Color, Breeds, Size, Gender, Category, Tags)

## Phase 4: Query engine (filter application)
- Filter processor (build WP_Query args from config + request)
- Filter validator (normalize/validate incoming values)
- Query merger (merge with main query safely)

## Phase 5: Frontend rendering (static, non-AJAX)
- Shortcode output with templates
- Basic filter UI elements (checkboxes and dropdowns)
- Clear/Reset controls (non-AJAX)
- URL sync for shareable filter URLs

## Phase 6: AJAX filtering (interactive UX)
- AJAX endpoints for apply/clear filters
- Response format for product loop + pagination
- JS behavior: apply, update URL, handle history state
- Security: nonce verification

## Phase 7: Admin filter builder (core UX)
- Filters builder UI (add/edit/delete)
- Drag-and-drop ordering
- Visibility rules (categories/tags)
- Swatch mapping UI for color

## Phase 8: Facet counts + hide empty
- Facet calculator with caching
- Exclude current facet from its own counts
- Hide empty terms when enabled

## Phase 9: Sorting + pagination support
- Sorting options (menu order, popularity, rating, newest/date, price ASC/DESC, title, attribute term)
- Preserve sort parameters across filtering
- Pagination links that keep filter state

## Phase 10: Variation-aware Color support
- Use product_attributes_lookup when available
- Ensure variable products are handled correctly
- Swatch display (color/image/text)

## Phase 11: Caching strategy (performance)
- Cache filtered query results (optional)
- Cache invalidation on product/term/config changes
- Prefer object cache with transient fallback

## Phase 12: Extensibility + QA
- Hooks and filters for integrations
- Security hardening (sanitize/escape, signatures)
- Unit/integration tests + manual QA checklist
- Packaging deliverables (zip, docs, shortcode examples)
