# Deploying to Dokploy

Replaces ngrok. ngrok's free tier hands out a new hostname on every restart,
which means re-registering the OpenAI webhook every single time and a demo that
dies the moment a laptop sleeps. A real domain fixes that permanently.

## 0. Generate APP_KEY — and understand what it is

```bash
php artisan key:generate --show
```

Copy the output. **Set it once and never change it.**

This is not the usual boilerplate warning. This app encrypts real data with that
key: **call transcripts** (a real person's words, captured under a GDPR retention
clock) and **each rep's Sonetel access and refresh tokens**. Rotate the key and
that data does not reset — it becomes permanently unreadable, and
`CallContentEraser` can no longer read what it is legally required to erase.

`docker/entrypoint.sh` therefore refuses to start without `APP_KEY` and never
runs `key:generate`. Many Docker Laravel templates do generate one on boot; here
that would silently destroy personal data on every deploy.

**Back the key up somewhere you would not lose it.** Losing it is worse than
losing the database, because the database becomes undecryptable rather than gone.

## 1. Push

```bash
git push
```

## 2. Dokploy: Compose service

**Create Service → Compose** → this repo → branch `main` →
**Compose Path: `docker-compose.prod.yml`**

Not `docker-compose.yml` — that is the dev file and runs only Postgres.

## 3. Environment tab

| Variable | Value |
|---|---|
| `APP_KEY` | from step 0. **Never change.** |
| `APP_URL` | `https://crm.smoothware.nl` (whatever domain you attach) |
| `DB_PASSWORD` | `openssl rand -base64 32` |
| `LOG_LEVEL` | `warning` |
| `ANTHROPIC_API_KEY` | your key |
| `LLM_DRIVER` | `claude` |
| `ANALYSIS_LLM_DRIVER` | `claude` |
| `WEBSITE_ANALYZER_DRIVER` | `http` |
| `OPENAI_API_KEY` | your key |
| `OPENAI_PROJECT_ID` | `proj_...` |
| `OPENAI_WEBHOOK_SECRET` | from OpenAI's webhook settings |
| `OUTBOUND_ENABLED` | **`false`** — see below |

Anything omitted falls back to the **offline fake**, which is the safe default:
a missing key means the AI is stubbed, not that it calls a vendor with an empty
credential.

**`OUTBOUND_ENABLED` stays `false`.** Deploying is not the same as enabling. It
stays false until counsel answers the ZZP'er question in `GO-LIVE-LEGAL.md` #3 —
cold-calling Dutch sole traders may be prohibited rather than merely regulated.

## 4. Domain

Attach a domain in Dokploy (Domains tab), pointed at the **`app`** service, port
**80**. Traefik handles the certificate.

`SERVER_NAME=:80` in the Dockerfile disables FrankenPHP's own auto-HTTPS. Without
it, FrankenPHP tries to get its own certificate behind the proxy, fails, and
serves nothing — presenting as "the site is down" rather than a TLS error.

## 5. Point OpenAI at it — permanently

**platform.openai.com → Settings → Project → Webhooks:**

```
https://<your-domain>/webhooks/openai/realtime
```

**This is the last time you set this.** It stops moving.

## 6. Create the first user

Dokploy → the `app` container → Terminal:

```bash
php artisan make:filament-user
php artisan db:seed --class=RolePermissionSeeder
php artisan db:seed --class=SmoothwareKnowledgeSeeder   # 6 KB entries + rules v1
```

Then assign yourself `super_admin`:

```bash
php artisan tinker --execute="App\Models\User::first()->assignRole('super_admin');"
```

## What runs

| Container | Job |
|---|---|
| `app` | HTTP + the OpenAI webhook. **Only this one migrates.** |
| `worker` | Queue: `ObserveRealtimeCall`, imports, analysis |
| `scheduler` | `schedule:work` — follow-up rules, token refresh |
| `postgres` | Not published to the host |

One image, three commands. The worker exists because `ObserveRealtimeCall` holds
a WebSocket open for the length of a call; in the web container that would occupy
a request thread for minutes.

Only `app` sets `RUN_MIGRATIONS=true`. All three running migrations would race
the same schema on every deploy, which is how you get a half-applied migration
and a lock nobody owns.

## Verifying it actually works

`assertOk()` on a page proves nothing here — Filament widgets are lazy Livewire.
Check the things that can silently fail:

```bash
curl -fsS https://<domain>/up                    # framework booted
```

Then in the container terminal:

```bash
php artisan about | grep -i -E "environment|debug|cache"
php artisan queue:monitor default                # worker alive?
```

And confirm the webhook end-to-end by dialling from a softphone (see
`smoothware-voice-sip`) or by calling a Twilio number pointed at OpenAI. A
`POST /webhooks/openai/realtime → 200` in the app logs is the real proof.

## Not yet verified

**Docker was unavailable on the dev machine, so this image has never been
built.** Expect first-build failures. Likely candidates, in order:

- **`npm run build`** — `vite.config.js` fetches fonts from Bunny at build time
  and needs network access in the builder.
- **A missing PHP extension** — the list in the Dockerfile is reasoned, not
  tested. A missing one surfaces as a clear "extension not found" at boot.
- **`composer install`** — `composer.lock` must be committed and current.

Watch the build log in Dokploy and send the error rather than guessing at it.

## Backups

`postgres-data` holds contact details and call transcripts. Dokploy can schedule
volume backups — set that up before this holds anything real. And note again:
**a backup is worthless without the APP_KEY that decrypts the transcripts in it.**
Store the key separately from the backups, and store both.
