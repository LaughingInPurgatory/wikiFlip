#!/bin/sh
set -e

PAGES_DIR="${WIKIFLIP_PAGES_DIR:-/var/www/html/pages}"
SEED_DIR="${WIKIFLIP_SEED_DIR:-/var/www/html/pages.dist}"

# If the pages volume is empty, seed starter content from the image
if [ ! -f "$PAGES_DIR/home/content.md" ]; then
  echo "Seeding $PAGES_DIR from image defaults..."
  mkdir -p "$PAGES_DIR"
  if [ -d "$SEED_DIR" ]; then
    cp -a "$SEED_DIR"/. "$PAGES_DIR"/
  fi
fi

# Apache (www-data) must write content.md and media
chown -R www-data:www-data "$PAGES_DIR" 2>/dev/null || true
chmod -R u+rwX,g+rwX "$PAGES_DIR" 2>/dev/null || true

exec "$@"
