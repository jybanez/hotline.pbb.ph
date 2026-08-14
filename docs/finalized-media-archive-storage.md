# Finalized Media Archive Storage

## Purpose

Hotline keeps live media ingestion responsive by storing temporary chunks in local application storage, then optionally moving only finalized audio/video outputs to a configured archive root.

This lets production hubs place long-lived finalized media on a larger local drive or LAN share without changing the existing media database contract.

## Runtime Setting

`media_archive_root`

- Blank value: finalized media continues to use Laravel's default public disk under `storage/app/public`.
- Non-blank value: must be an absolute local path or UNC path.
- Examples:
  - `D:\pbb-hotline-media`
  - `\\nas\pbb\hotline-media`

The setting is managed in Admin > Runtime Settings > Media Storage.

## Storage Contract

- Processing chunks remain in local private app storage under `media-processing/...`.
- Finalized media uses the configured archive root only after finalize/assembly.
- `media.path` remains a logical key such as:
  - `incidents/27/media/28/100_audio-peer_operator-main.weba`
  - `diagnostics/operator-media-stream-tests/2/101_operator-media-stream-test_operator-diagnostic.weba`
- Hotline does not store absolute archive paths in the database.
- Bad archive paths fail finalization loudly instead of silently falling back.

## Serving Contract

Hotline still serves finalized media through the existing app-controlled `/storage/{path}` route. The route resolves finalized media keys from the archive root when configured, or from the public disk when blank.

Message attachments, avatars, launcher assets, and other public disk files are not moved by this setting.

## Operational Notes

- Keep the archive root available to the machine running Hotline PHP.
- If the path is a UNC share, the web/server process account must have write and read access.
- Backups should include both the database and the archive root.
- Changing `media_archive_root` affects future reads of logical finalized media paths. Move existing finalized media files to the new root before changing the setting on a populated node.
