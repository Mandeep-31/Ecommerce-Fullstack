# Environment files

There are four environment files in this project:

| File | Purpose |
| --- | --- |
| `backend/.env` | Local Laravel settings and your local UAT notes. It is ignored by Git. |
| `backend/.env.example` | Safe starter settings copied to `.env` when setting up Laravel. |
| `frontend/.env` | Local React/Vite WebSocket connection settings. It is ignored by Git. |
| `frontend/.env.example` | Safe starter values for the React/Vite app. |

Never commit `backend/.env` or `frontend/.env`. The examples are committed; put production secrets in your hosting provider's secret settings.

## Laravel: `backend/.env` and `.env.example`

The table describes the Laravel variables in both files. The actual `.env` also contains `ESEWA_TEST_*` notes, explained below.

### App and language

| Variable | What it controls |
| --- | --- |
| `APP_NAME` | Human-readable Laravel application name. Used in generated labels and mail defaults. |
| `APP_ENV` | Environment name, usually `local`, `staging`, or `production`. |
| `APP_KEY` | Laravel's encryption key. Generate it with `php artisan key:generate`; keep it private and stable after deployment. It is separate from the eSewa key. |
| `APP_DEBUG` | Shows detailed error pages when `true`. Use `true` locally and `false` in production. |
| `APP_URL` | Base Laravel URL used to create absolute links, including eSewa callback URLs. It must be the public backend URL in production. |
| `APP_LOCALE` | Default language for Laravel translations (`en` here). |
| `APP_FALLBACK_LOCALE` | Language used when a translation is missing. |
| `APP_FAKER_LOCALE` | Locale Faker uses when generating fake development data. |
| `APP_MAINTENANCE_DRIVER` | Where Laravel stores maintenance-mode state (`file` here). |
| `APP_MAINTENANCE_STORE` *(commented out)* | Optional shared store if maintenance mode is managed across multiple app servers. |
| `PHP_CLI_SERVER_WORKERS` *(commented out)* | Optional number of workers for PHP's built-in development server; not for production hosting. |

### Password hashing, logs, and database

| Variable | What it controls |
| --- | --- |
| `BCRYPT_ROUNDS` | Work factor for bcrypt password hashing. Higher values take longer to hash; this is not a session duration. |
| `LOG_CHANNEL` | Main logging destination (`stack` combines the configured channels). |
| `LOG_STACK` | Channels used by the `stack` logger (`single` writes to Laravel's log file). |
| `LOG_DEPRECATIONS_CHANNEL` | Where framework deprecation warnings go; `null` disables a separate deprecation log. |
| `LOG_LEVEL` | Minimum severity written to logs, such as `debug`, `info`, or `error`. |
| `DB_CONNECTION` | Database type. `sqlite` uses `backend/database/database.sqlite`. |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` *(commented out)* | Example MySQL connection settings. Uncomment and configure these only if switching away from SQLite. |

### Sessions, cache, queues, and broadcasts

| Variable | What it controls |
| --- | --- |
| `SESSION_DRIVER` | Where Laravel stores session data. `database` stores it in SQLite. |
| `SESSION_LIFETIME` | **Idle session duration in minutes. `120` means two hours without a request.** It is not the eSewa payment session timeout. |
| `SESSION_ENCRYPT` | Whether Laravel encrypts session data before storing it (`false` here). |
| `SESSION_PATH` | URL path where the browser sends the session cookie; `/` means the whole site. |
| `SESSION_DOMAIN` | Cookie domain. `null` lets the browser use the current host. |
| `BROADCAST_CONNECTION` | Laravel event broadcaster. `reverb` sends payment status events to the local WebSocket server. |
| `FILESYSTEM_DISK` | Default storage destination for uploaded files (`local` here). |
| `QUEUE_CONNECTION` | Where queued jobs are stored. `database` stores them in the jobs table; a queue worker is needed for jobs that are actually queued. |
| `CACHE_STORE` | Default cache backend (`database` here). |
| `CACHE_PREFIX` *(commented out)* | Optional prefix to keep this app's cache keys separate from other apps sharing a cache. |

The default `SESSION_LIFETIME` measures inactivity: each request refreshes the session's activity. Closing the browser does not immediately expire it with the current defaults. Laravel's session config has an `expire_on_close` option, but this example does not override it.

### Redis and Memcached

These are optional service settings. The current app uses SQLite/database stores and does not need a Redis or Memcached server for its local demo.

| Variable | What it controls |
| --- | --- |
| `MEMCACHED_HOST` | Hostname of a Memcached server if that cache/session driver is selected. |
| `REDIS_CLIENT` | PHP Redis client library (`phpredis` here). |
| `REDIS_HOST` | Redis server hostname. |
| `REDIS_PASSWORD` | Redis password; `null` means no password is configured. |
| `REDIS_PORT` | Redis server port, normally `6379`. |

### Email and AWS

| Variable | What it controls |
| --- | --- |
| `MAIL_MAILER` | Email transport. `log` writes outgoing email content to logs instead of sending it. |
| `MAIL_SCHEME` | Optional transport security scheme for SMTP. |
| `MAIL_HOST`, `MAIL_PORT` | SMTP server host and port if using SMTP. |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | SMTP login, if required. |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Sender address and display name for outgoing mail. `${APP_NAME}` reuses the app name. |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | AWS credentials for AWS-backed services. Leave empty when unused. |
| `AWS_DEFAULT_REGION` | Default AWS region. |
| `AWS_BUCKET` | S3 bucket name if S3 storage is configured. |
| `AWS_USE_PATH_STYLE_ENDPOINT` | Enables path-style S3 URLs for compatible S3 services; normally `false` for AWS. |

### eSewa, Khalti, and Reverb

| Variable | What it controls |
| --- | --- |
| `ESEWA_PRODUCT_CODE` | Merchant/product code sent to eSewa (`EPAYTEST` for UAT). |
| `ESEWA_SECRET_KEY` | Server-side HMAC key used to sign checkout fields and verify eSewa responses. Never put this in the frontend. |
| `ESEWA_FORM_URL` | eSewa's hosted payment form URL. The UAT URL begins with `https://rc-epay...`; production uses the URL supplied by eSewa. |
| `ESEWA_STATUS_URL` | eSewa server-to-server transaction status endpoint. |
| `KHALTI_SECRET_KEY` | Khalti server-side Secret Key from the Khalti merchant dashboard. Keep it private and never add it to React or a `VITE_*` variable. |
| `KHALTI_API_BASE_URL` | Khalti API environment. `https://dev.khalti.com/api/v2/` is the sandbox; production uses `https://khalti.com/api/v2/`. |
| `FRONTEND_URL` | Public React site URL. Laravel sends the browser here after either provider's return callback. |
| `REVERB_APP_ID` | Identifier for this Reverb WebSocket app. |
| `REVERB_APP_KEY` | Public app key used to connect to Reverb. This is not the eSewa secret. |
| `REVERB_APP_SECRET` | Server-side Reverb signing secret. Keep private in production. |
| `REVERB_HOST` | Host the browser and Laravel use to reach Reverb. |
| `REVERB_PORT` | Reverb client connection port. It is a port number, not a duration. |
| `REVERB_SCHEME` | WebSocket security scheme: `http` locally or `https`/`wss` behind TLS in production. |
| `VITE_APP_NAME` | Laravel Vite's public app-name variable. The separate React app does not currently need it. |
| `VITE_REVERB_APP_KEY` | Frontend-visible Reverb app key, normally copied from `REVERB_APP_KEY`. |
| `VITE_REVERB_HOST` | Reverb host compiled into the frontend. |
| `VITE_REVERB_PORT` | Reverb port compiled into the frontend. |
| `VITE_REVERB_SCHEME` | Reverb scheme compiled into the frontend. |

For Khalti sandbox payments, create a merchant account at [test-admin.khalti.com](https://test-admin.khalti.com), copy its Secret Key into `KHALTI_SECRET_KEY` in `backend/.env`, and keep `KHALTI_API_BASE_URL` on the sandbox URL above. Khalti's published sandbox buyer IDs are `9800000000` through `9800000005`; its documented test MPIN is `1111` and OTP is `987654`. The backend uses the secret only for server-to-server payment initiation and lookup. Khalti requires lookup verification before an order is marked paid.

`VITE_*` variables are bundled into browser code and must never contain passwords or private keys. The backend-only `ESEWA_TEST_*` values in your local `.env` are just reminders for manual UAT login; the app does not send them to eSewa.

## React: `frontend/.env` and `.env.example`

| Variable | What it controls |
| --- | --- |
| `VITE_REVERB_APP_KEY` | Public Reverb app key used by Laravel Echo. |
| `VITE_REVERB_HOST` | Hostname of the Reverb WebSocket server. |
| `VITE_REVERB_PORT` | WebSocket port; `8081` in the current local setup. |
| `VITE_REVERB_SCHEME` | `http` locally or `https` when served securely. |

These four frontend values must match `REVERB_APP_KEY`, `REVERB_HOST`, `REVERB_PORT`, and `REVERB_SCHEME` in `backend/.env`. Restart Vite after changing them because Vite reads them at startup/build time.
