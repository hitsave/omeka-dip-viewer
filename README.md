# Omeka DIP Viewer

Omeka S module for browsing **E-ARK CSIP DIP** packages stored as `.tar` media: parse `METS.xml` inside the archive, show a file tree, image carousel and lightbox, and Video.js playback—without extracting the package to disk.

Developed for [Hit Save! Archive](https://archive.hitsave.org) and used with the preservation ingest stack in [`hitsave-archiver`](https://github.com/hitsave/hitsave-archiver) (E-ARK DIP build + REST upload) and the [`hitsave-archive-theme`](https://github.com/hitsave/hitsave-archive-theme) public theme.

| | |
|---|---|
| **Omeka S** | `^4.0.0` (see `config/module.ini`) |
| **Module version** | `0.3.31` |
| **Media ingester** | `omeka_dip_package` |
| **License** | [GPL-3.0-or-later](LICENSE) |

## Install

From your Omeka S root:

```bash
cd modules
git clone https://github.com/hitsave/omeka-dip-viewer.git OmekaDipViewer
```

In the Omeka admin UI: **Modules** → install and activate **Omeka DIP Viewer**, then open the module **Configure** screen for thresholds (large package limits, gallery caps, etc.). See **[docs/config-contract.md](docs/config-contract.md)**.

Upload DIP `.tar` files via the **DIP package** ingester, or use an API client that registers media with ingester `omeka_dip_package` (same as the HitSave uploader).

## Features

- Stream members from the DIP tar for previews and downloads
- Gallery / Embla carousel and lightbox for still images
- Video.js for access copies in the package
- Optional browse collage previews on item lists
- Resource page block layout for media lists

## Development

Minimal METS/tar smoke test (PHP CLI, no full Omeka bootstrap):

```bash
php test/parse_fixture.php /path/to/sample-dip.tar
```

Bundled front-end assets include [Video.js](https://videojs.com/) and [Embla Carousel](https://www.embla-carousel.com/) (see `asset/js/`, `asset/css/`).

## Related projects

- **Packaging / upload:** [hitsave-archiver](https://github.com/hitsave/hitsave-archiver) — `build-dip-e-ark.py`, `upload-dip-omeka-api.py`
- **Theme:** [hitsave-archive-theme](https://github.com/hitsave/hitsave-archive-theme)

This module contains **no site credentials**; configure Omeka and API keys only on your instance.
