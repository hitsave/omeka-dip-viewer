# Omeka DIP Viewer

Omeka S module for browsing **RFC 8493 BagIt** packages on **Wasabi S3**: file tree, image carousel and lightbox, and Video.js playback without copying the full package into Omeka storage.

Developed for [Hit Save! Archive](https://archive.hitsave.org) with [`hitsave-archiver`](https://github.com/hitsave/hitsave-archiver) (BagIt ingest + REST attach) and [`hitsave-archive-theme`](https://github.com/hitsave/hitsave-archive-theme).

## Requirements

- Omeka S 4.x
- PHP 8.1+ with `gd`, optional `apcu` for index cache
- Wasabi (or S3-compatible) credentials in `config/dip-s3.config.php` (see `config/dip-s3.config.php.dist`)

## Omeka media registration

POST `/api/media` with ingester `omeka_dip_package` and JSON `data`:

- `dip_storage`: `wasabi-s3`
- `package_storage_prefix`: e.g. `press-material/F/{package_id}`
- `dip_wasabi_uri`: `s3://hitsave-incoming/press-material/F/{package_id}/`

No file upload. Ingest + `register-dip-omeka-api.py` set these fields automatically.

## Local dev

```bash
composer install
php test/parse_fixture.php /path/to/bag/dir   # needs metadata/inventory.json
```

## Version

See `config/module.ini` (0.5.x = S3 BagIt only).

## License

Copyright 2026 Hit Save! Licensed under the [Apache License, Version 2.0](LICENSE).
