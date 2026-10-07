# Config contract — single source of truth (SSOT)

This module ships **no operator YAML**. Runtime tuning uses Omeka’s module settings and (optionally) a host-mounted file.

## Precedence (highest wins)

| Layer | Where | Use |
|-------|--------|-----|
| 1 | Omeka admin → **Omeka DIP Viewer** module settings | Per-site DB settings (`omeka_dip_viewer.*`) |
| 2 | **`/config/settings.yaml`** → `dip_viewer:` | Hit Save test/prod stacks that bind-mount [`hitsave-omeka-test` `settings.yaml`](https://github.com/hitsave/hitsave-omeka-test/blob/main/config/omeka-test/settings.example.yaml) (see that repo’s [config-contract.md](https://github.com/hitsave/hitsave-omeka-test/blob/main/docs/config-contract.md)) |
| 3 | **`src/Service/DipConfig.php`** `DEFAULT_*` constants | Generic installs with no file mount |

Implementation: [`DipConfig.php`](../src/Service/DipConfig.php) (`getModuleSetting` → `loadFileConfig()` → constants).

## Not owned here

| Topic | SSOT |
|-------|------|
| Ingest / ffmpeg access copies | hitsave-archiver `build-dip-e-ark.py` + game YAML / `submissions.yaml` |
| DIP upload API target | hitsave-archiver `config/omeka-uploader.yaml` |
| Ingester name `omeka_dip_package` | This module (stable contract for uploader) |

## Anti-patterns

- Adding a second `dip-viewer.yaml` in this repo — use Omeka module settings or the host `settings.yaml` `dip_viewer` block.
- Duplicating threshold numbers in README — defaults live in `DipConfig.php`; deployment copies live in hitsave-omeka-test `settings.example.yaml`.
