# Is Radio Up?

A WISP diagnostic tool for quickly checking if a customer's radio is online, viewing signal quality, LAN status, and last-seen timestamps. Searches by customer name, phone number, IP address, or physical address.

## Features

- **Customer search** — fuzzy search across name, secondary contact, phone (with NZ number normalisation), IP, and address
- **Ping check** — instant reachability test via fping
- **AirControl2 integration** — signal, noise, TX/RX rates, LAN speed, uptime, firmware, last seen
- **WISP-graphing integration** — RSSI, MCS, retransmits, AP name, session time
- **Splynx enrichment** — customer name, service status, address, phone, linked from service data store
- **SNMP diagnostics** — Cambium radio stats via SNMP
- **SSH diagnostics** — direct AirOS/ePMP device interrogation
- **DHCP lease viewer** — view LAN-side DHCP leases and ARP table via SSH
- **TP-Link HX510 management** — auto-detect TP-Link on LAN, port forward creation, speed tests, diagnostics
- **Speed test** — trigger and view speed test results (WISP-graphing and TP-Link)
- **OUI lookup** — identify device manufacturer from MAC address
- **Ticket creation** — link directly to Radio Ticket for fault logging

## Architecture

```
[Browser] → index.php (search UI)
              ↓
         search.php ← /dev/shm/splynx_active_services.json (fast lookup)
              ↓
         diagnose.php → ping, AirControl2 API, WISP-graphing SQLite, Splynx data
              ↓
         External helpers:
           ├── /tplink-HX510-helpers/ubiquiti_airos_portforward.php  (AirOS port forward)
           ├── /tplink-HX510-helpers/cambium_portforward.php         (ePMP port forward)
           ├── /cambium-ePMP-helpers/cambium_epmp_ssh.php             (ePMP SSH commands)
           └── /ubiquiti-airos-helpers/airos_ssh.php                  (AirOS SSH commands)
```

The tool reads from a pre-generated shared-memory data store (populated by `splynx_exporter_cli.php` on a daily cron). No live Splynx API calls are made during normal operation.

## Requirements

- PHP 7.4+ with curl extension
- `fping` (preferred) or `ping`
- Access to AirControl2 instance (optional)
- WISP-graphing SQLite database (optional)
- Splynx service data store at `/dev/shm/splynx_active_services.json`

## Setup

1. Copy `secrets.php.example` to `secrets.php` and fill in your AirOS/ePMP SSH credentials
2. Configure `config.php` with your Splynx, AirControl2, and WISP-graphing paths
3. Ensure the Splynx exporter cron is running to populate the data store

## Files

| File | Purpose |
|------|---------|
| `index.php` | Web UI — search and diagnostic display |
| `search.php` | JSON API — searches the Splynx data store |
| `diagnose.php` | JSON API — runs diagnostics (ping, AC2, WISP-graphing) |
| `config.php` | Configuration — paths, URLs, API keys |
| `secrets.php` | Credentials — SSH passwords (git-ignored) |
| `secrets.php.example` | Template for secrets.php |
| `dhcp_leases.php` | API — SSH to radio, show LAN DHCP leases and ARP table |
| `snmp_cambium.php` | API — Cambium radio SNMP stats |
| `cambium_lan.php` | API — Cambium LAN-side device detection |
| `speed_test.php` | API — trigger/view speed tests |
| `oui_lookup.php` | API — MAC address manufacturer lookup |
| `debug_snmp.php` | Debug tool — raw SNMP queries |
| `debug_ssh.php` | Debug tool — raw SSH commands to AirOS |

## Related Projects

| Folder | Purpose |
|--------|---------|
| `tplink-HX510-helpers/` | TP-Link port forward management (AirOS + Cambium) |
| `ubiquiti-airos-helpers/` | AirOS SSH command library |
| `cambium-ePMP-helpers/` | Cambium ePMP SSH command library |
| `splynx-service/` | Builds the shared-memory data store from Splynx API |
| `WISP-graphing/` | Radio monitoring — RSSI, MCS, subscriber data |
| `splynx-ticket-events/` | Ticket event dispatcher — Slack, voicemail transcription, customer matching |

## API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `search.php?q=<term>` | GET | Search customers (min 2 chars, max 20 results) |
| `diagnose.php?ip=<ipv4>` | GET | Full diagnostic for an IP |
| `dhcp_leases.php?ip=<ipv4>&ssh_port=22` | GET | LAN DHCP leases and ARP table via SSH |
| `speed_test.php?ip=<ipv4>` | GET | Speed test results |
| `snmp_cambium.php?ip=<ipv4>` | GET | Cambium SNMP stats |

## Security

- `secrets.php` is excluded from git via `.gitignore`
- `.htaccess` restricts direct access to sensitive files
- AirControl2 credentials are read from a shared config file at runtime
- No credentials are hardcoded in committed files
