# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

This plugin extends fsbhoa_ac_core.  @/home/pi/fsbhoa_ac_core/other_docs/ARCHITECTURE.md

## What this is

An add-on that prints photo ID badges on a Zebra card printer (default CUPS queue `Zebra-ZC300`). It has two halves that run on the same Raspberry Pi (aarch64):

- **WordPress plugin** (repo root: `fsbhoa-ac-zebra.php`, `includes/`, `assets/`): the print UI, AJAX workflow and settings page.
- **Go print service** (`zebra_print_service/`): a localhost HTTP daemon (systemd unit `fsbhoa_printer`) that renders the card image and sends it to CUPS.

What it uses from core: the `fsbhoa_ac_main_menu` admin menu, the tables `ac_cardholders`, `ac_credentials` and `ac_print_log`, `fsbhoa_log_pending_change()` (queues credential syncs to the doors), the `fsbhoa_render_card_preview` action (implemented by `fsbhoa_zebra_render_badge_only`) and the `fsbhoa_system_services` filter.

## Build / deploy

```bash
./build.sh            # go build -o zebra_print_service/fsbhoa_printer
./build.sh install    # build, copy to /usr/local/bin, restart the fsbhoa_printer systemd service (sudo)
cd zebra_print_service && go vet ./...
sudo journalctl -u fsbhoa_printer -f   # service logs
```

Run `build.sh` from the repo root because it uses `$(pwd)`. The compiled `fsbhoa_printer` binary is committed, so rebuilding changes a tracked file.

The plugin is symlinked into WordPress as `wp-content/plugins/fsbhoa_ac_zebra`, so PHP/JS edits are live and only the Go service needs building. Nothing deploys the card template: `fsbhoa_ac_print_template_path` must point at an XML file on the server, such as `zebra_print_service/templates/hoa_card_template.xml`.

## Print workflow (spans PHP, JS and Go)

1. `assets/js/fsbhoa-print-workflow.js` posts `fsbhoa_submit_print_job` (admin-ajax, nonce `fsbhoa_print_card_nonce`).
2. `Fsbhoa_Print_Actions::ajax_submit_print_job` inserts an `ac_print_log` row (status `submitted`) and builds a payload `{log_id, template_xml, fields}`. `fields` holds `firstName`, `lastName`, `residentPhoto` (base64 of the photo BLOB), `cardBackLogo`, and `line3` (the title if there is one, otherwise "Expires: …" unless the expiry is the sentinel `2099-12-31`). It then POSTs to `http://127.0.0.1:<fsbhoa_ac_print_port>/print_card`.
3. The Go service replies 202 right away and handles the job in a goroutine. It parses the template XML, draws a 640×1016 portrait PNG (front side only, DejaVuSans font, firstName and lastName auto-shrink to fit their box width) into `wp-content/uploads/fsbhoa_print_temp/printjob-<log_id>-*.png`, runs `lp -d <printer>`, and polls `lpstat -o` until the job leaves the queue (2 min timeout).
4. Go reports progress back by calling `POST /wp-json/fsbhoa/v1/print_log_update` (`class-fsbhoa-print-rest-api.php`). Statuses are `printing`, then `completed_ok` or `failed_error`.
5. The JS polls `fsbhoa_check_print_status` every 3s, which reads `ac_print_log`. On `COMPLETED_OK` it shows the RFID input. A scan of 8 or more chars triggers `fsbhoa_save_rfid`, which upserts the `MIFARE_BADGE` credential, sets the cardholder to active and calls `fsbhoa_log_pending_change` so the doors get updated.

**Debug/dry-run mode** (`fsbhoa_ac_print_debug_mode` = `on`): Go skips `lp`, keeps the PNG and reports `completed_ok`. The JS then shows a link to `fsbhoa_serve_dry_run_image`, which serves the PNG.

### REST auth differs from the shared convention

`print_log_update` does not use the Access Verification API Key and `X-API-KEY`. It checks header `X-Internal-API-Key` against its own option, `fsbhoa_ac_print_api_token`. `Fsbhoa_Zebra_Settings` seeds that option from a default that is hard-coded in the source.

## Configuration flow

Settings are WP options (`fsbhoa_ac_print_*`, `fsbhoa_ac_card_back_url`) edited on the "Print Service" admin submenu. On save, `Fsbhoa_Zebra_Settings::write_json_config()` writes `/var/lib/fsbhoa/zebra_print_service.json` (port, api_url, api_token, printer_name, debug_mode). The Go service reads that file only at startup (override with `-config`), so you have to restart `fsbhoa_printer` after changing settings. When adding a config field, update all three of these: the WP option/field, `write_json_config()`, and the `Config` struct in `zebra_print_service/config.go`.

## Card layout

Card layout appears in two places, and you need to keep them in sync by hand:
- The XML template (`zebra_print_service/templates/hoa_card_template.xml`, wherever `fsbhoa_ac_print_template_path` points), which controls what actually gets printed. Go reads only `fonts>font` (id, size) and the front side's `graphic`/`text` elements (field, x, y, width, alignment). The other attributes are ignored.
- The HTML/CSS badge preview in `includes/views/view-badge-preview-card.php`, which also builds the line3/expiry logic itself.

Assets are only enqueued on the page with slug `print-photo-id`, on `?action=print_card`, or on the admin page `fsbhoa_print_card` (see `fsbhoa_zebra_enqueue_assets`). The front-end entry point is the shortcode `[fsbhoa_print_card]`, which requires `manage_options`.
