# Config contract — single source of truth (SSOT)

**Omeka DIP Viewer** is configured in the Omeka admin UI under **Modules → Omeka DIP Viewer**. This repo ships no operator YAML.

| Topic | SSOT |
|-------|------|
| Thresholds, gallery caps, caches, browse preview | Omeka module settings (`omeka_dip_viewer.*` in the DB) |
| DIP upload ingester name `omeka_dip_package` | This module (API clients must use this name) |
| Ingest / ffmpeg access copies | hitsave-archiver (`build-dip-e-ark.py`, game YAML, `submissions.yaml`) |
| Omeka REST upload target | hitsave-archiver `config/omeka-uploader.yaml` |

Do not add parallel config files for module behavior — use the module settings screen (or Omeka’s settings API if you automate installs).
