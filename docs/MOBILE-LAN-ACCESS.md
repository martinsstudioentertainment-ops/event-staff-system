# Phone on same Wi-Fi — open the staff app

## Do not use on your phone

| Wrong | Why |
|-------|-----|
| `localhost:8080` | That is the phone itself, not your PC |
| `127.0.0.1:8080` | Same |
| `event-staff-system.test:8080` | `.test` usually does not resolve on the phone |

## Use your PC’s Wi-Fi IP

1. On the PC: **Win + R** → `cmd` → `ipconfig` → note **IPv4** (e.g. `10.0.0.59`)
2. Laragon → **Start All** (Apache + MySQL)
3. On the phone (same Wi‑Fi, not mobile data):

```
http://10.0.0.59:8080/staff-app.php
```

Replace `10.0.0.59` with your IPv4.

Staff app home: `/staff-app.php`  
Register: `/index.php`  
Admin: `/admin/login.php`

## If it still does not open

### 1. Windows Firewall (allow port 8080)

PowerShell **as Administrator**:

```powershell
New-NetFirewallRule -DisplayName "Laragon Apache 8080" -Direction Inbound -Protocol TCP -LocalPort 8080 -Action Allow
```

### 2. Apache LAN vhost

File: `C:\laragon\etc\apache2\sites-enabled\000-event-staff-lan.conf`  
Then **Laragon → Apache → Reload** (or Stop All → Start All).

If your PC IP changes, edit `ServerAlias` in that file or use:

```
http://YOUR-IP:8080/event-staff-system/staff-app.php
```

### 3. Same network

- Phone on **Wi‑Fi**, not **4G/5G**
- Not on a **guest network** that blocks device-to-device traffic

### 4. Test from PC first

```bat
curl http://10.0.0.59:8080/staff-app.php
```

Should return HTML (not 404).

## Production

Use real HTTPS domain — PWA install, camera, and push need HTTPS on a public URL (not LAN IP).
