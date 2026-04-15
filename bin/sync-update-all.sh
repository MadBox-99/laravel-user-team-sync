#!/bin/bash
#
# Mass-update all local projects that use madbox-99/laravel-user-team-sync
#
# Usage:
#   ./bin/sync-update-all.sh              # Update all projects
#   ./bin/sync-update-all.sh --dry-run    # Show what would be updated
#   ./bin/sync-update-all.sh --migrate    # Also run migrations in each project

set -euo pipefail

HERD_DIR="${HERD_DIR:-$HOME/Herd}"
PACKAGE="madbox-99/laravel-user-team-sync"
DRY_RUN=false
RUN_MIGRATE=false

for arg in "$@"; do
    case $arg in
        --dry-run)   DRY_RUN=true ;;
        --migrate)   RUN_MIGRATE=true ;;
    esac
done

echo "🔍 Searching for projects using $PACKAGE in $HERD_DIR..."
echo ""

PROJECTS=()

for composer_file in "$HERD_DIR"/*/composer.json; do
    dir=$(dirname "$composer_file")
    name=$(basename "$dir")

    # Skip the package itself
    [[ "$name" == "laravel-user-team-sync" ]] && continue

    if grep -q "$PACKAGE" "$composer_file" 2>/dev/null; then
        PROJECTS+=("$dir")
    fi
done

if [[ ${#PROJECTS[@]} -eq 0 ]]; then
    echo "No projects found using $PACKAGE"
    exit 0
fi

echo "Found ${#PROJECTS[@]} projects:"
for dir in "${PROJECTS[@]}"; do
    echo "  - $(basename "$dir")"
done
echo ""

if $DRY_RUN; then
    echo "(dry-run mode, no changes made)"
    exit 0
fi

SUCCESS=0
FAILED=0
FAILED_PROJECTS=()

for dir in "${PROJECTS[@]}"; do
    name=$(basename "$dir")
    echo "━━━ $name ━━━"

    if ! composer update "$PACKAGE" -d "$dir" --no-interaction 2>&1 | tail -5; then
        echo "  ✗ composer update failed"
        FAILED=$((FAILED + 1))
        FAILED_PROJECTS+=("$name")
        echo ""
        continue
    fi

    if $RUN_MIGRATE; then
        if php "$dir/artisan" migrate --force --ansi 2>&1 | tail -5; then
            echo "  ✓ migrated"
        else
            echo "  ⚠ migration failed (may need manual run)"
        fi

        php "$dir/artisan" config:clear --ansi 2>/dev/null || true
        php "$dir/artisan" route:clear --ansi 2>/dev/null || true
    fi

    SUCCESS=$((SUCCESS + 1))
    echo ""
done

echo "━━━━━━━━━━━━━━━━━━"
echo "Done: $SUCCESS updated, $FAILED failed"

if [[ ${#FAILED_PROJECTS[@]} -gt 0 ]]; then
    echo "Failed: ${FAILED_PROJECTS[*]}"
    exit 1
fi
