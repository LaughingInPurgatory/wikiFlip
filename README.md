# WikiFlip

WikiFlip is a small, file-based wiki for home labs, private networks, and local
projects. Pages are written in Markdown and stored as ordinary folders, so there
is no database to install or maintain.

It includes:

- Nested pages and categories
- A Markdown editor with a WYSIWYG mode
- Image thumbnails that open in a full-size lightbox
- Inline PDF viewing
- Newest-created-first page ordering, with optional manual ordering
- Expandable and collapsible category navigation
- A dark indigo interface for both the public site and the admin area
- A ready-to-run Docker image published to GitHub Container Registry

## Important: private use only

WikiFlip is designed for a trusted home lab or private network. It is not hardened
for the public internet.

The application has simple shared-password authentication, light HTML
sanitisation, limited upload validation, and flat-file write access for the web
process. If an attacker reaches the app, they may be able to read or change the
whole wiki.

Change the default password before sharing the app with anyone else, and add your
own network-level protection if the wiki is not running only on localhost.

## The quickest way to start

The easiest option is Docker. The published image is:

```text
ghcr.io/laughinginpurgatory/wikiflip:latest
```

From the repository directory, run:

```bash
docker compose up -d
```

Then open [http://localhost:8080/](http://localhost:8080/). The default admin
login is `admin` / `password`; change it in `docker-compose.yaml` or with the
environment variables described below.

The same compose file can be used with Dockhand, Portainer, or another Docker
stack manager. Empty page volumes are seeded with starter content on first start.

### If Docker says `unauthorized`

The GitHub Container Registry package may still be private even when the GitHub
repository is public. In GitHub, open the **wikiflip** package under the
[LaughingInPurgatory packages](https://github.com/LaughingInPurgatory?tab=packages),
choose **Package settings**, and change its visibility to **Public**. Then retry
the deployment.

### Run the image directly

```bash
docker run -d --name wikiflip \
  -p 8080:80 \
  -e WIKIFLIP_ADMIN_USER=admin \
  -e WIKIFLIP_ADMIN_PASSWORD=password \
  -v wiki_pages:/var/www/html/pages \
  ghcr.io/laughinginpurgatory/wikiflip:latest
```

To keep the pages in a folder on the host instead of a Docker volume, replace the
volume line with:

```yaml
- ./data/pages:/var/www/html/pages
```

The container runs Apache and PHP on port `80`; the example maps that to port
`8080` on the host.

## Using the wiki

Open the home page to read the wiki, or go to `/admin/` to manage it.

When creating or editing a page:

1. Set its title and URL slug.
2. Choose a parent page if it belongs inside a category.
3. Write in Markdown or switch to the WYSIWYG editor.
4. Upload images or PDFs from the editor when needed.

Images appear as smaller thumbnails in the article. Click one to see the full
image, then close the preview with the close button, by clicking outside it, or by
pressing `Escape`.

Click a category in the sidebar to expand or collapse its sub-pages. The chevron
shows whether the category is open, and the choice is kept while you move around
the wiki.

Pages in each category are sorted newest-created-first by default. If a category
already has a manual order, a newly created page is still placed at the top until
you move it with the admin controls. Moving a page saves a manual order for that
category. Editing an older page does not change its original creation date or move
it to the top.

## How content is stored

Each page is a folder containing a `content.md` file and any media used by that
page. Nested folders represent nested pages:

```text
pages/
  home/
    content.md
  guides/
    content.md
    sample-page/
      content.md
      photo.png
      notes.pdf
```

The first line of `content.md` is the page title:

```markdown
# Page title

Body in **Markdown**.

![Screenshot](photo.png)
```

WikiFlip also keeps a small `.created_at` file beside each page so editing the
page does not affect its creation order. When a category is manually reordered,
the order is saved in `.order.json`.

For pages that existed before creation timestamps were added, WikiFlip creates a
one-time timestamp using the earliest filesystem timestamp it can find. It keeps
that value unchanged afterwards.

Older `page.json` and HTML page files are migrated to Markdown automatically when
they are loaded.

## Admin credentials

WikiFlip checks credentials in this order:

1. Environment variables, which is the usual Docker setup
2. The fallback values in `config/admin.php`

| Variable | Purpose |
|----------|---------|
| `WIKIFLIP_ADMIN_USER` | Admin username |
| `WIKIFLIP_ADMIN_PASSWORD` | Plain-text password supplied through the environment |
| `WIKIFLIP_ADMIN_PASSWORD_HASH` | Optional bcrypt password hash |

To generate a bcrypt hash:

```bash
php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
```

## Run without Docker

For local PHP development, you need PHP 8.1 or newer and write access to the
`pages/` directory.

```bash
php -S localhost:8080 router.php
```

Useful URLs:

| URL | Purpose |
|-----|---------|
| `http://localhost:8080/` | Home page |
| `http://localhost:8080/guides` | A page using a clean URL |
| `http://localhost:8080/?slug=guides` | The same page using a query parameter |
| `http://localhost:8080/admin/` | Admin area |

To build the development Docker image from source:

```bash
docker compose -f docker-compose.yaml -f docker-compose.dev.yaml up --build
```

## Docker and GitHub Actions

The Docker image is built from `php:8.3-apache` and listens on container port
`80`. GitHub Actions:

- Builds the image for pull requests
- Publishes `latest` to GHCR when changes are pushed to `main`
- Publishes versioned images for `v*` tags

Useful Docker commands:

```bash
docker compose pull                # Download the latest published image
docker compose up -d               # Start the wiki
docker compose logs -f wiki        # Follow Apache logs
docker compose down                # Stop the wiki but keep its volume
docker run --rm -p 8080:80 ghcr.io/laughinginpurgatory/wikiflip:latest
```

Your `pages/` volume is the wiki. Back it up regularly before upgrading or
removing containers.

## Project layout

```text
wikiFlip/
├── Dockerfile
├── docker-compose.yaml
├── docker-compose.dev.yaml
├── docker/entrypoint.sh       # Seeds an empty pages volume
├── .github/workflows/docker.yml
├── index.php                  # Public page view
├── media.php                  # Serves page media
├── router.php                 # Clean URLs for PHP's built-in server
├── admin/                     # Login and page management
├── assets/                    # CSS, JavaScript, and bundled editor assets
├── config/admin.php           # File-based credential fallback
├── pages/                     # Wiki content; mount this in Docker
└── src/                       # Storage, authentication, and Markdown code
```

## License

Private repository. All rights reserved unless otherwise noted.
