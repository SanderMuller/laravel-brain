# Installation

Laravel Brain is installed as a dev dependency — it's a development tool, not something you need in production.

## Install the package

```bash
composer require --dev laramint/laravel-brain
```

Laravel will auto-discover the service provider. No manual registration needed.

::: tip
Everything — the `/_laravel-brain` routes, Artisan commands, and MCP server — only registers when `app()->environment('local')`. Since it's a `require-dev` dependency, it's absent entirely from a production build (`composer install --no-dev`).
:::

## Storage Driver

Scan output (the graph) can be persisted in one of two ways, selected with the
`driver` config key (env `LARAVEL_BRAIN_DRIVER`):

| Driver | Where it stores | Setup |
| --- | --- | --- |
| `file` (default) | `.graph-*.json` files under `storage/app/laravel-brain/` | none |
| `database` | a database table (`laravel_brain_graphs`) | none — table auto-created on first scan |

Use the `database` driver when `storage/` is not writable or not shared between
the web and CLI processes (read-only containers, multi-node deploys):

```dotenv
LARAVEL_BRAIN_DRIVER=database
# Optional — defaults to laravel_brain_graphs
LARAVEL_BRAIN_DB_TABLE=laravel_brain_graphs
```

By default it uses your app's default connection. You can instead point it at a
dedicated database with its own credentials — no need to edit
`config/database.php`. Set the connection name to the bundled `laravel-brain`
connection and fill in its credentials:

```dotenv
LARAVEL_BRAIN_DB_CONNECTION=laravel-brain

LARAVEL_BRAIN_DB_DRIVER=mysql
LARAVEL_BRAIN_DB_HOST=127.0.0.1
LARAVEL_BRAIN_DB_PORT=3306
LARAVEL_BRAIN_DB_DATABASE=laravel_brain
LARAVEL_BRAIN_DB_USERNAME=brain
LARAVEL_BRAIN_DB_PASSWORD=secret
```

Or set `LARAVEL_BRAIN_DB_CONNECTION` to the name of any existing connection
already defined in your `config/database.php`. All reads/writes honour the
chosen connection.

The graph table is **created automatically the first time you run a scan**
(`brain:scan` or the "Scan" button in the UI) — no `php artisan migrate` step
is required. If the table already exists, it is left untouched.

If you'd rather manage the table with your normal migrations instead, publish
the migration and run it yourself:

```bash
php artisan vendor:publish --tag=laravel-brain-migrations
php artisan migrate
```

## Output Files

With the default `file` driver, `brain:scan` writes these files to
`storage/app/laravel-brain/`:

```
.graph-manifest.json   — Tab manifest (list of all route tabs)
.graph-{tab-id}.json   — Per-route subgraph (one per route)
```

Every scan rewrites the whole set and drops the subgraphs it did not write, so a tab
disappears from the viewer when the route behind it disappears from the application.

They are safe to gitignore:

```
storage/app/laravel-brain/
```

(The `database` driver stores the same payloads as rows in the configured table.)

## Security

The `/_laravel-brain` routes, the artisan commands, and the MCP server are all only registered in the `local` environment by default. Since it's a `require-dev` dependency, it will not be present in production builds (`composer install --no-dev`).

::: warning
If you do install it in a non-production environment accessible over a network, consider protecting the routes with middleware.
:::
