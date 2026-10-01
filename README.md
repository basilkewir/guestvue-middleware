# GuestVue Middleware (XC_VM) — customized build

Customized XC_VM panel ("GuestVue middleware") captured from the production host
**kotel-hms-server-kribi** (`kotelserver`, 100.68.125.112) on 2026-10-01, packaged
so it can be installed on another server.

Based on [Vateron-Media/XC_VM](https://github.com/Vateron-Media/XC_VM) (AGPL-3.0),
with the GuestVue branding, media-upload feature and permission enforcement added.

## Repository layout

| Path | Purpose |
| --- | --- |
| `install` | Upstream Python installer (packages, MariaDB 11.4, service, crons) |
| `xc_vm/` | The panel itself, with the customizations applied |
| `pack.sh` | Builds `./xc_vm.tar.gz` from `./xc_vm/` (required before install) |
| `deploy/` | Reference copies of the systemd unit and crontabs the installer writes |

`xc_vm/` ships unpacked because GitHub rejects files over 100 MB and the
installer's archive is ~174 MB. `./install` consumes `./xc_vm.tar.gz` — which
`pack.sh` builds — and this repo's `install` refuses to silently download the
upstream release when it sees the local tree, so the customizations can never be
overwritten by accident.

## Requirements

* Fresh Linux server: Ubuntu 20.04/22.04/24.04, Debian 11/12/13, or
  Rocky/Alma/RHEL 8/9, CentOS 7/8
* Root access
* Outbound internet (apt/dnf packages, MariaDB repo, GeoIP + proxy fetch)
* The bundled runtime binaries under `xc_vm/bin/` (php, nginx, nginx_rtmp, redis,
  ffmpeg, python, GeoIP) are included, so nothing is fetched from
  `Vateron-Media/XC_VM_Binaries` at install time unless the installer chooses to
  refresh them

## Install

```bash
git clone https://github.com/basilkewir/guestvue-middleware.git
cd guestvue-middleware
./pack.sh            # builds ./xc_vm.tar.gz from ./xc_vm
sudo ./install       # or: sudo python3 install
```

The installer asks for the HTTP/HTTPS ports and a MariaDB root password (it can
generate one). Everything it writes is recorded in `/root/credentials.txt` on the
target — move that file somewhere safe and delete the original.

At the end it prints the admin URL:

```text
Continue Setup: http://<server-ip>:<port>/<admin-code>
```

The admin code and its nginx vhost (`xc_vm/bin/nginx/conf/codes/`) are generated
per install and are deliberately not part of this repository.

## What is (and is) not in this repo

Included: the full customized panel, the bundled runtime (`bin/`), the install
schema (`bin/install/database.sql`), module archives, and the GuestVue branding
assets (logos, favicons, login background, lottie animation) and language files.

Excluded on purpose:

* **Secrets / per-install state** — `config/config.ini`, `config/config.enc`,
  `install_id`, admin access-code nginx vhosts, the Redis password
  (`bin/redis/redis.conf` keeps the upstream `#PASSWORD#` placeholder — the panel
  generates and syncs the real one at runtime), the `realip_xc_vm.conf` trusted
  proxy list (restored to the stock empty file), and TLS material
  (`bin/nginx/conf/server.key`/`.crt` restored to the stock distribution pair).
  A template lives at `xc_vm/config/config.example.ini`.
* **All movies / media content from the source host** — the media library
  (`content/vod`, `content/movies`, `content/streams`), stream buffers, EPG
  dumps, custom offline screens and the cached images under `storage/images/`.
  `pack.sh` recreates the empty `content/` and `storage/` skeleton; the panel
  guards these paths with `file_exists()`, so a fresh install starts fine
  without them. Re-upload media through the panel.
* **Live database contents** — DB backups and runtime logs. A fresh install
  creates its own database from `bin/install/database.sql` plus the module
  schemas (`Modules/*/database.sql`) and the migrations in `migrations/`.

## Customizations vs. upstream XC_VM

* GuestVue branding: logos, favicons, admin lottie animation, custom login
  background video, language strings (`resources/langs/*`)
* `Public/Controllers/Admin/MediaUploadController.php` +
  `Public/assets/admin/js/media-upload.js` — media upload feature
* Permission enforcement on admin controllers (`requirePermission()` in
  `EpgController`, `OndemandController`, `PageAuthorization`, admin routes)
* nginx tuning: `client_body_timeout 300s`, `client_max_body_size 16m`
* HTTP port 8089 / HTTPS 443 (the installer prompts and rewrites these)
* Panel-user role **VOD Only** (vod admin): admin access limited to movies,
  series, episodes, VOD mass-edit and TV profiles. Seeded by
  `bin/install/database.sql` on fresh installs and by
  `migrations/010_add_vod_only_group.sql` on existing ones
* Modules updated in place: `watch` 1.0.5, `plex` 1.0.3 (archives under
  `modules_archives/`)

## Reference files (`deploy/`)

`deploy/xc_vm.service`, `deploy/crontab.xc_vm` and `deploy/crontab.root` are the
exact unit and crontab entries this build runs with on the source host, kept for
reference — `./install` writes its own equivalents on the target.

## Notes

* The panel runs as the `xc_vm` user under the `xc_vm.service` systemd unit
  (see `deploy/xc_vm.service`), with its own nginx, nginx_rtmp, redis and
  php-fpm from `xc_vm/bin/`.
* Do not run `install` on a host where another stack owns the same ports.
* Upstream project and module sources: `Vateron-Media/XC_VM`,
  `Vateron-Media/XC_VM_Binaries`, `Vateron-Media/xc_vm-module-watch`.
