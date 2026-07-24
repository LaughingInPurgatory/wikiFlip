# WikiFlip

A lightweight, **databaseless** wiki. Content is plain **Markdown** in folders, with nested pages, image/PDF media next to each page, and a dark indigo glass UI.

No MySQL. No Composer at runtime. Optional Docker image built by GitHub Actions.

> [!CAUTION]
> **INSECURE — LOCAL / PRIVATE NETWORK USE ONLY**
>
> WikiFlip is **not hardened for the public internet**. Do **not** expose it to the open web, reverse-proxy it without additional controls, or treat it as a production multi-user system.
>
> **Why it is unsafe on the internet:**
> - Minimal authentication (shared admin password; no 2FA, lockout, or audit trail)
> - Light content sanitization only — XSS / HTML injection risk from untrusted editors
> - File uploads (images/PDFs) with limited validation
> - Flat-file write access for the web process — compromise can rewrite the whole wiki
> - Default credentials are intentionally simple for local demos
>
> **Intended use:** home lab, LAN, localhost, or other **trusted** private environments only.  
> If you need a public-facing wiki, use software designed and maintained for that threat model.

---

## Features

- **Flat-file storage** — one folder per page, `content.md` + media
- **Nested categories** — unlimited depth via nested folders
- **Markdown + WYSIWYG** — Toast UI Editor (bundled locally, no CDN required)
- **Images & PDFs** — stored in the page folder; relative paths like `![alt](photo.png)`
- **Inline PDF viewer** — upload from the editor toolbar
- **Admin auth** — session login; credentials from **env** or `config/admin.php`
- **Docker** — self-contained image, `pages/` as a volume
- **CI** — GitHub Actions builds and publishes to **GHCR**

---

## Requirements

| Mode | Need |
|------|------|
| **Docker** | Docker + Docker Compose |
| **Local PHP** | PHP 8.1+ (tested on 8.3 / 8.5), write access to `pages/` |

---

## Quick start

### Docker (published container image)

The app ships as a real **Apache + PHP** container image on GitHub Container Registry:

```text
ghcr.io/laughinginpurgatory/wikiflip:latest
```

#### Compose (pull & run)

Works with CLI Compose, Dockhand, Portainer, etc. The compose file uses
**literal values** (no `${VAR:-default}`) so stack UIs parse it cleanly.

```bash
docker compose up -d
```

In **Dockhand**: create a stack, paste `docker-compose.yaml`, override admin env if you want, deploy.

Open **http://localhost:8080/** (or whatever host port you map).

**If pull says `unauthorized`:** the container package on GHCR may still be private
(even when this git repo is public). Fix once:

1. GitHub → https://github.com/LaughingInPurgatory?tab=packages  
2. Open **wikiflip** → **Package settings** → **Change visibility** → **Public**  
3. Retry `docker compose up -d` / redeploy in Dockhand  

No `docker login` is needed after the package is public.

#### Plain `docker run` (no Compose)

```bash
docker run -d --name wikiflip \
  -p 8080:80 \
  -e WIKIFLIP_ADMIN_USER=admin \
  -e WIKIFLIP_ADMIN_PASSWORD=password \
  -v wiki_pages:/var/www/html/pages \
  ghcr.io/laughinginpurgatory/wikiflip:latest
```

| Setting | Value in compose |
|---------|------------------|
| Image | `ghcr.io/laughinginpurgatory/wikiflip:latest` |
| HTTP | host `8080` → container **80** (Apache) |
| Admin user | `admin` (change via `WIKIFLIP_ADMIN_USER`) |
| Admin password | `password` (change via `WIKIFLIP_ADMIN_PASSWORD`) |
| Pages data | volume `wiki_pages` → `/var/www/html/pages` |

**Bind-mount host folder** — change the volume line to:

```yaml
- ./data/pages:/var/www/html/pages
```

Empty volumes are **seeded** with starter pages on first start.

### Build from source (optional)

```bash
docker compose -f docker-compose.yaml -f docker-compose.dev.yaml up --build
```

### Local PHP (no Docker)

```bash
php -S localhost:8080 router.php
```

| URL | Purpose |
|-----|---------|
| http://localhost:8080/ | Home |
| http://localhost:8080/?slug=guides | Guides category |
| http://localhost:8080/guides | Same (clean URL via router) |
| http://localhost:8080/admin/ | Admin (login required) |
| http://localhost:8080/admin/edit.php?slug=home | Edit a page |

---

## Content model

Each page is a **directory**. Body lives in **`content.md`**. The first line is the title:

```markdown
# Page title

Body in **Markdown**.

![Screenshot](photo.png)
```

Hierarchy = nested folders:

```text
pages/
  home/
    content.md
  guides/
    content.md
    sample-page/
      content.md
      photo.png          ← ![alt](photo.png)
      notes.pdf          ← PDF embed / relative link
```

| Concept | How it works |
|---------|----------------|
| Top-level page | `pages/{slug}/content.md` |
| Sub-page | `pages/{parent}/…/{slug}/content.md` |
| Media | Files in the same folder as `content.md` |
| Public media URL | `media.php?slug={page}&file={filename}` |
| Parent in the app | Derived from the folder path |

Legacy `page.json` / HTML content is auto-migrated to `content.md` on load when present.

---

## Admin

1. Open `/admin/` and sign in.
2. Create or edit pages; set **parent** for nesting.
3. Set the **URL slug** before uploading media (defines the page folder).
4. Use the editor in **WYSIWYG** or **Markdown** mode; **PDF** button uploads an inline viewer.

### Credentials

**Priority:**

1. Environment variables (Docker / process env)
2. Else `config/admin.php` (bcrypt hash)

| Variable | Purpose |
|----------|---------|
| `WIKIFLIP_ADMIN_USER` | Admin username |
| `WIKIFLIP_ADMIN_PASSWORD` | Plaintext password (hashed in memory) |
| `WIKIFLIP_ADMIN_PASSWORD_HASH` | Optional bcrypt hash instead of plaintext |

Generate a hash:

```bash
php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
```

Change the file-based fallback in `config/admin.php` the same way.

---

## Project layout

```text
wikiFlip/
├── Dockerfile
├── docker-compose.yaml
├── docker/entrypoint.sh       # Seed pages volume if empty
├── .env.example
├── .github/workflows/docker.yml
├── index.php                  # Public page view
├── media.php                  # Serve page-folder media
├── router.php                 # PHP built-in server + clean URLs
├── .htaccess                  # Apache clean URLs
├── logo.png
├── admin/                     # Login, list, edit, save, upload
├── assets/
│   ├── css/style.css
│   ├── js/editor.js
│   └── vendor/toastui/        # Bundled WYSIWYG (no CDN)
├── config/admin.php           # Fallback admin credentials
├── pages/                     # Content (mount this in Docker)
└── src/
    ├── core/                  # Auth, storage, Markdown
    ├── includes/              # Header / footer chrome
    └── lib/Parsedown.php      # MD → HTML
```

---

## Docker & CI details

| Item | Detail |
|------|--------|
| Base image | `php:8.3-apache` (real HTTP webserver, not `php -S`) |
| Listen | container port **80** |
| Entrypoint | Seeds `/var/www/html/pages` when the volume is empty |
| Compose service | `wiki` |
| Registry image | `ghcr.io/laughinginpurgatory/wikiflip` |
| CI workflow | Build on PRs; **push** image on `main` and `v*` tags |

Useful commands:

```bash
docker compose up -d               # pull + run container
docker compose pull                # fetch latest image only
docker compose logs -f wiki        # Apache logs
docker compose down                # stop (volume kept)
docker compose -f docker-compose.yaml -f docker-compose.dev.yaml up --build
docker run --rm -p 8080:80 ghcr.io/laughinginpurgatory/wikiflip:latest
docker volume rm wikiflip_wiki_pages   # wipe content volume (name may vary)
```

---

## Notes

- Sanitization is light (scripts / some event handlers). For a public internet deploy, add stricter HTML filtering.
- Change the default admin password before exposing the app.
- Back up the `pages/` volume (or bind-mount directory) — that **is** your wiki.
- Theme tokens match the deep-indigo glass palette used elsewhere in the stack.

---

## License

Private repository. All rights reserved unless otherwise noted.
