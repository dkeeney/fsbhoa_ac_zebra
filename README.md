# FSBHOA Access Control: Zebra Card Printer

An extension plugin for the [FSBHOA Access Control system](https://github.com/dkeeney/fsbhoa_ac_core). It prints photo ID badges on a Zebra ZC300 card printer and activates each badge by scanning it once it's printed.

For what the whole system does, how it is put together, and how the plugins relate to each other, start with the core plugin: **[fsbhoa_ac_core](https://github.com/dkeeney/fsbhoa_ac_core)**. This plugin requires core and does nothing without it.

## What it does

From a cardholder's record, an administrator opens the print page, checks the badge preview, and clicks **Start Print Process**:

1. The plugin builds the card image (photo, first and last name, and a third line showing the title or an expiry date) and sends it to the printer.
2. The printer prints the front of a blank card from its feeder. The cards come pre-encoded with random RFID numbers.
3. Staff put an adhesive sticker with the HOA logo and phone number on the back.
4. Staff swipe the card on a USB desk reader. The badge number is saved to the cardholder's record, the badge is activated, and the change is queued for sync to the door controllers.

The page waits for the swipe because the plugin can't know which pre-encoded card the printer pulled until someone scans it. Printing a new card for someone replaces their old badge, which stops working once the controllers sync. A badge number already held by another active cardholder is refused.

A **Debug Mode (dry run)** setting renders the card image without printing it, so the layout can be checked without using up a card.

## How it works

The plugin has two parts:

- **WordPress plugin** (PHP and JavaScript): the print page, the badge preview, the step-by-step print workflow and the settings page.
- **Go print service** (`zebra_print_service/`): a small HTTP service on `127.0.0.1` that turns the card template and cardholder data into a PNG and sends it to CUPS with `lp`.

```
Browser ──AJAX──> WordPress ──POST /print_card──> Go print service ──lp──> CUPS ──> printer
   ^                  ^                                   │
   │ polls status     └──── POST fsbhoa/v1/print_log_update (X-API-KEY)
   │
   └─ USB reader swipe ──> badge saved and activated ──> sync to controllers
```

Each job is tracked in core's `ac_print_log` table. The service reports progress back to WordPress (`printing`, then `completed_ok` or `failed_error`), authenticating with core's Access Verification API Key. The page polls that status and asks for the swipe once the card is done.

The card layout is an XML template (`zebra_print_service/templates/hoa_card_template.xml`) giving the position and size of the photo and of each text line on a 640×1016 pixel portrait card (CR80, 2.125" × 3.375"). Long names are shrunk to fit their box.

## Requirements

- WordPress with the [fsbhoa_ac_core](https://github.com/dkeeney/fsbhoa_ac_core) plugin active.
- A Linux server (a Raspberry Pi works well) with CUPS and a print queue for the card printer.
- Go 1.23 or later to build the print service.
- The DejaVu Sans font (`/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf`).
- Any USB MIFARE RFID reader that types the badge number like a keyboard. It must send the same 8-digit number the door readers report, with no Enter at the end. We use a FissaiD M303 (sold on Amazon) set to output format 9 (2H-3D 4H-5D). In that format the facility code (2 hex digits) becomes 3 decimal digits and the card number (4 hex digits) becomes 5, each zero-padded. This matches the UHPPOTE Wiegand readers.

## Installation

1. Put the repository in WordPress's plugin folder, or symlink it there, then activate **FSBHOA Access Control - Zebra Printer**:
   ```bash
   ln -s /home/pi/fsbhoa_ac_zebra /var/www/html/wp-content/plugins/fsbhoa_ac_zebra
   ```
2. Install a systemd unit for the print service, for example `/etc/systemd/system/fsbhoa_printer.service`:
   ```ini
   [Unit]
   Description=FSBHOA Printer Service
   After=network.target

   [Service]
   Type=simple
   User=pi
   Group=www-data
   WorkingDirectory=/var/lib/fsbhoa
   ExecStart=/usr/local/bin/fsbhoa_printer
   Restart=always
   RestartSec=5

   [Install]
   WantedBy=multi-user.target
   ```
   Set `User` to your server's service account. It needs write access to `wp-content/uploads/` (here, through the `www-data` group), where card images are written.
3. In core's General settings, generate the Access Verification API Key if there isn't one yet.
4. Under **FSBHOA AC → Print Service**, set the port, the CUPS printer name, the card template path and Debug Mode, then save. Saving writes the service's config to `/var/lib/fsbhoa/zebra_print_service.json`.
5. Build, install and start the service from the repository root:
   ```bash
   sudo systemctl daemon-reload
   sudo systemctl enable fsbhoa_printer
   ./build.sh install
   ```
6. Create a WordPress page with the slug `print-photo-id` containing the shortcode `[fsbhoa_print_card]`. Core's cardholder and vendor screens link to it.

The service reads its config only at startup, so restart it (`./build.sh install` or `sudo systemctl restart fsbhoa_printer`) after changing settings. Logs: `sudo journalctl -u fsbhoa_printer -f`.

Only administrators (`manage_options`) can print or activate badges.

## Using this as a pattern for another card printer

Nothing in this plugin is specific to Zebra except its name, the default queue name and the card template. The service makes a plain PNG and prints it through CUPS, so any card printer with a CUPS driver can work. To support another brand, copy this repository and change what differs:

| Part | What to change |
|---|---|
| Card template (`templates/*.xml`) | Positions, font sizes and fields for your card design. |
| Canvas size (`createImage` in `main.go`) | 640×1016 suits a 300 dpi CR80 portrait card. Change it for another resolution, orientation or card size. |
| Printing (`doPrintJob` in `main.go`) | The `lp` command and its options (e.g. `-o` for duplex, ribbon or color settings), or a vendor SDK if the printer isn't driven through CUPS. |
| Two-sided printing | The service draws only the front today. The card-back image setting is already sent to it, ready for back-side rendering. |
| Names | Plugin header, settings labels, the service name (`fsbhoa_printer`) and the config file name. |

Keep these the same so the rest of the system still works:

- **The core hooks and tables:** `ac_print_log`, `ac_credentials` (the badge is a `MIFARE_BADGE` credential), `fsbhoa_log_pending_change()` to push the new badge to the controllers, the `fsbhoa_render_card_preview` action and the `fsbhoa_system_services` filter.
- **The status protocol:** report `printing`, `completed_ok` or `failed_error` to `fsbhoa/v1/print_log_update` with the shared `X-API-KEY`.
- **The `print-photo-id` page and `[fsbhoa_print_card]` shortcode,** which core links to.
- **The scan-to-activate step,** if your cards are pre-encoded. If your printer encodes the chip itself, the service can report the number it wrote instead.

Run only one card-printer plugin at a time. They share the shortcode, the AJAX action names and the REST route.

As with every extension, a printer plugin may depend on core but never on another extension (see core's architecture notes).

## License

GPL v2 or later, the same as [fsbhoa_ac_core](https://github.com/dkeeney/fsbhoa_ac_core).
