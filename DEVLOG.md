# ShiftAI – Dev Log

## 2026-05-05 — Login "Unable to connect" fix

### Problem
Logging in via `index.html` always showed:

> "Unable to connect. Please check your network."

### Root Cause
`doLogin()` tries the Express API first (`/api/auth/login`). When that fails (backend not running), it falls back to reading `config.json` directly. The fallback fetch used an **absolute path**:

```js
var cfgRes = await fetch('/config.json');  // resolves to http://localhost/config.json  ❌
```

Since the project lives inside `smart shift backend/`, XAMPP serves it at:

```
http://localhost/smart shift backend/config.json
```

The absolute `/config.json` path misses the subdirectory, so the fetch 404s and the outer catch block fires — showing the network error.

### Fix
Changed the fallback fetch to a **relative path** in `index.html` line 451:

```js
// Before
var cfgRes = await fetch('/config.json');

// After
var cfgRes = await fetch('config.json');
```

### Result
Login now works without the Express backend running. XAMPP/Apache serves `config.json` from the same folder, authentication falls back correctly, and RBAC redirects work:

| Credentials | Role | Redirect |
|---|---|---|
| `MIN-4821` / `1234` | Miner | Worker app |
| `MIN-3301` / `1234` | Supervisor | `supervisor.html` |
| `ADMIN-01` / `admin` | Admin | `admin.html` |

### Notes
- The Express backend (`backend_api.js`) still needs to run on port 3000 for AI features (briefings, chat, report generation) to work. Without it, the app uses local fallback logic.
- PINs in `config.json` are plain text (demo only). Swap for bcrypt hashes before any production deployment.
