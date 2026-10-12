# Config contract — single source of truth (SSOT)

**Omeka DIP Viewer** is configured in the Omeka admin UI under **Modules → Omeka DIP Viewer**. This repo ships no operator YAML for module behavior.

| Topic | SSOT |
|-------|------|
| Thresholds, gallery caps, caches, browse preview | Omeka module settings (`omeka_dip_viewer.*` in the DB) |
| Wasabi S3 credentials and endpoint | `config/dip-s3.config.php` (from `dip-s3.config.php.dist`; render via hitsave-omeka-test `render-dip-s3-config.py`) |
| DIP ingester name `omeka_dip_package` | This module (API clients must use this name) |
| Ingest and Omeka attach | hitsave-archiver (`build-bagit-package.py`, `upload-dip-omeka-api.py`, `config/omeka-uploader.yaml`) |

Do not add parallel config files for module behavior — use the module settings screen (or Omeka’s settings API if you automate installs).
