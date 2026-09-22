# SEOMI MCP Abilities

Modular MCP abilities for WordPress — exposes `seomi/*` abilities for content, taxonomy, media, and WooCommerce management to AI agents through the MCP Adapter plugin.

Built and maintained by SEOMI. Designed to be dropped into any WordPress project (with or without WooCommerce) by way of a Git submodule, a Composer package, or a direct copy.

## Requirements

- WordPress **6.4+**
- PHP **8.0+**
- [WP Abilities API](https://github.com/WordPress/abilities-api) — active
- [MCP Adapter](https://github.com/WordPress/mcp-adapter) — active
- WooCommerce **7.0+** — optional, only needed if you want product/order abilities

## Installation

This plugin lives in `wp-content/mu-plugins/seomi-mcp-abilities/`. Because WordPress only auto-loads mu-plugin files directly under `wp-content/mu-plugins/`, a tiny loader at `wp-content/mu-plugins/mcp-abilities.php` is required to bootstrap the package.

### Option A — Git submodule (recommended)

```bash
git submodule add https://github.com/seomi/wp-mcp-abilities \
  wp-content/mu-plugins/seomi-mcp-abilities

# Add the loader (one-time, idempotent):
cat > wp-content/mu-plugins/mcp-abilities.php <<'PHP'
<?php
defined( 'ABSPATH' ) || exit;
if ( defined( 'SEOMI_MCP_VERSION' ) ) return;
$f = __DIR__ . '/seomi-mcp-abilities/seomi-mcp-abilities.php';
if ( is_readable( $f ) ) require_once $f;
PHP
```

### Option B — Composer

```bash
composer require seomi/wp-mcp-abilities
```

…then create the same loader file as in Option A (or rely on Composer's mu-plugin installer if your `composer.json` is configured for `wordpress-muplugin`).

### Option C — Plain copy

Drop the `seomi-mcp-abilities/` directory under `wp-content/mu-plugins/` and create the same loader file.

## Abilities

All abilities are registered under the `seomi/` prefix and visible to MCP clients via `mcp-adapter-discover-abilities`.

### Posts (`category: content`)

| Ability | Purpose |
|---|---|
| `seomi/get-posts` | List posts with filters (status, category, tag, search, IDs) |
| `seomi/get-post` | Full content of one post by ID |
| `seomi/get-post-meta` | Read post meta (incl. ACF) for one or many posts |
| `seomi/find-posts-by-thumbnail` | Find posts by `_wp_attached_file` substring |
| `seomi/create-post`, `seomi/update-post`, `seomi/delete-post` | CRUD |
| `seomi/bulk-replace-in-posts` | Regex/string replace across `post_content` |

### Pages (`category: content`)

| Ability | Purpose |
|---|---|
| `seomi/get-pages`, `seomi/get-page` | Read |
| `seomi/create-page`, `seomi/update-page`, `seomi/delete-page` | CRUD |

### Terms (`category: taxonomy`)

| Ability | Purpose |
|---|---|
| `seomi/get-categories` and CRUD | Category management |
| `seomi/get-tags` and CRUD | Tag management |
| `seomi/search-terms` | Find terms by description substring (any taxonomy) |
| `seomi/bulk-replace-in-term-descriptions` | Regex/string replace across term descriptions |

> **Note on rich HTML in term descriptions.** All term-description writes route through `Core::with_admin_term_kses()`, which temporarily detaches `wp_filter_kses` from `pre_term_description` so `<table>`, `<tr>`, `<td>` survive outside the admin context (REST/MCP/CLI). Yoast does this only in `is_admin()` — we replicate it for AI agents.

### Media (`category: media`)

| Ability | Purpose |
|---|---|
| `seomi/get-attachment` | Read attachment metadata |
| `seomi/find-attachments-by-file` | LIKE-search by `_wp_attached_file` |
| `seomi/set-post-thumbnail` | Set featured image for a post |

### WooCommerce (`category: woocommerce`) — only when WC is active

| Ability | Purpose |
|---|---|
| `seomi-wc/get-products`, `seomi-wc/get-product` | Read products |
| `seomi-wc/create-product`, `seomi-wc/update-product`, `seomi-wc/delete-product` | CRUD via WC CRUD API |
| `seomi-wc/update-product-price`, `seomi-wc/update-product-stock` | Narrow writes |
| `seomi-wc/get-product-categories` + CRUD | `product_cat` terms |
| `seomi-wc/get-orders`, `seomi-wc/get-order` | Read orders |
| `seomi-wc/update-order-status` | Change order status |

All WC writes go through `wc_get_product()` / `$product->save()` (and `wc_get_order()` for orders) — never `wp_insert_post`. This keeps WooCommerce lookup tables in sync.

## Filtering modules

You can disable specific modules per site:

```php
add_filter( 'seomi_mcp_modules', function ( array $modules ): array {
	// Don't load the Media module on this site.
	return array_diff( $modules, [ 'media' ] );
} );
```

Valid keys: `posts`, `pages`, `terms`, `media`, `woocommerce`.

## Adding your own module

Implement `Seomi\Mcp\Modules\ModuleInterface` and register it under your own filter, **outside** this package:

```php
namespace MySite\Mcp;

use Seomi\Mcp\Modules\ModuleInterface;

class CustomAbilities implements ModuleInterface {
	public function register( array $mcp_meta ): void {
		wp_register_ability( 'mysite/something', [ /* ... */ ] );
	}
}

add_action( 'wp_abilities_api_init', function () {
	( new \MySite\Mcp\CustomAbilities() )->register( [ 'mcp' => [ 'public' => true ] ] );
}, 20 );
```

We intentionally don't ship a public registration helper — the module hook `seomi_mcp_modules` is private to this package's module set.

## Smoke tests

Run from your project root:

```bash
wp eval-file wp-content/mu-plugins/seomi-mcp-abilities/tests/smoke.php
```

Each check prints `[PASS]` or `[FAIL]`. Exit code is non-zero on any failure (CI-friendly).

## Debug logging

When `WP_DEBUG=true`, every module logs its write operations to the PHP error log with the prefix
`[seomi-mcp]`:

```
[seomi-mcp] [posts] update-post id=42
[seomi-mcp] [terms] bulk-replace taxonomy=category updated=17 errors=0
```

The boot banner -- plugin version plus the list of enabled modules -- is **opt-in**. It fires on
every single request, so with `WP_DEBUG` on a busy site it drowns out everything else in the log.
It is worth having the first time an MCP integration misbehaves on a new project, so enable it
deliberately in `wp-config.php`:

```php
define( 'SEOMI_MCP_VERBOSE_BOOT', true );
```

```
[seomi-mcp] booted v1.1.0, modules: posts,pages,terms,media,woocommerce
```

A constant rather than a filter: mu-plugins load before themes and regular plugins, so nothing
could have registered a filter callback by the time the banner runs, and `wp-config.php` is read
first.

## License

Proprietary — © [SEOMI.RU](https://seomi.ru/).
