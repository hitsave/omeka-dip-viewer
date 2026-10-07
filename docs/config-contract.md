# Config contract — single source of truth (SSOT)

This module ships **no operator YAML**. Tune behavior in the Omeka admin UI or rely on code defaults.

## Precedence (highest wins)

| Layer | Where | Use |
|-------|--------|-----|
| 1 | Omeka admin → **Omeka DIP Viewer** module settings | Per-install DB settings (`omeka_dip_viewer.*`) — **operator SSOT** |
| 2 | [`src/Service/DipConfig.php`](../src/Service/DipConfig.php) `DEFAULT_*` constants | Fallback when a setting was never saved in admin |

Implementation: each getter checks `getModuleSetting()` first, then falls back to defaults (and, for a few keys, an optional file — see below).

## Optional file read (not operator SSOT)

`DipConfig` can read **`dip_viewer:`** from a hard-coded path **`/config/settings.yaml`** when the corresponding **module DB setting is empty**. That path is **not** part of generic Omeka S; it exists for Hit Save Docker images that bind-mount one YAML file into the container.

- **Do not** treat that mount as a second config product for this module.
- **Hit Save** documents the mount and `dip_viewer` keys in [hitsave-omeka-test config-contract](https://github.com/hitsave/hitsave-omeka-test/blob/main/docs/config-contract.md) (`settings.yaml` is SSOT there; admin UI can still override per key once saved).

## Not owned here

| Topic | SSOT |
|-------|------|
| Ingest / ffmpeg access copies | hitsave-archiver `build-dip-e-ark.py` + game YAML / `submissions.yaml` |
| DIP upload API target | hitsave-archiver `config/omeka-uploader.yaml` |
| Ingester name `omeka_dip_package` | This module (stable contract for uploader) |

## Anti-patterns

- Adding module-local `dip-viewer.yaml` — use Omeka module settings.
- Duplicating threshold numbers in README — defaults live in `DipConfig.php`.
