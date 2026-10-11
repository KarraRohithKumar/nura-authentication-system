# NURA AI LABS - Enterprise Multi-Tier Authentication System

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat&logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-8.0%2B-4479A1?style=flat&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Redis](https://img.shields.io/badge/Redis-6.0%2B-DC382D?style=flat&logo=redis&logoColor=white)](https://redis.io/)
[![MongoDB](https://img.shields.io/badge/MongoDB-6.0%2B-47A248?style=flat&logo=mongodb&logoColor=white)](https://www.mongodb.com/)
[![Bootstrap](https://img.shields.io/badge/Bootstrap-5.3-7952B3?style=flat&logo=bootstrap&logoColor=white)](https://getbootstrap.com/)
[![jQuery](https://img.shields.io/badge/jQuery-3.7-0769AD?style=flat&logo=jquery&logoColor=white)](https://jquery.com/)

A production-ready, security-hardened authentication and profile management system engineered for **NURA AI LABS**. The architecture strategically distributes responsibilities across **MySQL** (credentials & identity), **Redis** (in-memory sessions with TTL), and **MongoDB** (unstructured researcher profiles), mediated by **PHP RESTful JSON APIs** and interactive **jQuery AJAX** frontend flows.

---

## Architecture Overview

```
                      +-----------------------------+
                      |   Modern Frontend Browser   |
                      |  (HTML, Bootstrap, jQuery)  |
                      +--------------+--------------+
                                     |
                          AJAX JSON / CSRF Token
                                     |
                                     v
                      +-----------------------------+
                      |      PHP 8.2 API Layer      |
                      |  (Security, CSRF, Session)  |
                      +---+----------+----------+---+
                          |          |          |
         PDO Prepared SQL |          | Key/Val  | BSON Driver
                          |          | (TTL)    |
                          v          v          v
                  +------------+ +-------+ +------------+
                  |   MySQL    | | Redis | |  MongoDB   |
                  | Identities | | Cache | |  Profiles  |
                  +------------+ +-------+ +------------+
```

| Storage Tier | Technology | Responsibility |
| :--- | :--- | :--- |
| **Identity & Auth** | **MySQL 8.0** (`nura_auth.users`) | Username, normalized email, BCrypt password hashes, unique keys. |
| **Session Cache** | **Redis** (`auth_session:{token}`) | Cryptographic 64-char hex session tokens, 3600s TTL, fixation prevention. |
| **Profile Store** | **MongoDB** (`nura_profiles.profiles`) | Flexible researcher attributes (bio, organization, age, phone, interests array). |

---

## Mandatory Folder Structure

```
Nura/
├── .env                     # Local environment configuration (git-ignored)
├── .env.example             # Template environment configuration
├── .gitignore               # Secrets and dependency exclusions
├── composer.json            # Composer dependency declarations (predis, mongodb, dotenv)
├── composer.lock            # Locked package dependency versions
├── index.html               # System landing page & architecture overview
├── register.html            # Registration interface (live strength meter & validation)
├── login.html               # Authentication interface (session bootstrap)
├── profile.html             # Profile console (MongoDB data & Redis session state)
├── css/
│   └── style.css            # Custom modern dark glassmorphic design system
├── js/
│   ├── register.js          # AJAX registration, validation, CSRF handling
│   ├── login.js             # AJAX login, session token storage, redirection
│   └── profile.js           # AJAX profile fetch/update, session validation & logout
├── php/
│   ├── config.php           # Core environment loader & JSON response helpers
│   ├── db.php               # MySQL PDO, Redis (Predis/Socket), and MongoDB connectors
│   ├── session.php          # Redis session lifecycle, cookies, and require_auth()
│   ├── csrf.php             # Anti-CSRF double-submit token generator & validator
│   ├── register.php         # User registration API endpoint (MySQL + initial Mongo)
│   ├── login.php            # User authentication API endpoint (MySQL + Redis)
│   ├── profile.php          # User profile API endpoint (Redis auth + MongoDB)
│   └── logout.php           # Session termination endpoint (Redis del + cookie expire)
├── database/
│   └── mysql_schema.sql     # DDL script for nura_auth database & users table
├── docs/
│   ├── architecture.md      # End-to-end architecture & Mermaid diagrams
│   ├── application_flow.md  # Detailed registration, login, profile, & logout flows
│   ├── database_architecture.md # Schema specs for MySQL, Redis, and MongoDB
│   └── diagrams.html        # Interactive visualizer & PDF-exportable diagrams
└── vendor/                  # Composer dependencies
```

---

## Security Features & OWASP Hardening

1. **Password Hashing**: Passwords hashed using `password_hash($pwd, PASSWORD_DEFAULT, ['cost' => 12])` with automatic rehashing via `password_needs_rehash()`.
2. **SQL Injection Defense**: 100% PDO prepared statements with native parameters (`PDO::ATTR_EMULATE_PREPARES => false`).
3. **Session Fixation Prevention**: New random 256-bit token (`bin2hex(random_bytes(32))`) generated on every authentication event.
4. **Session Hijacking Mitigation**: Cookies configured with `HttpOnly=true` (JavaScript inaccessible) and `SameSite=Lax` (configurable to `Strict`).
5. **Anti-CSRF Protection**: State-changing requests enforce double-submit CSRF tokens via `X-CSRF-Token` headers and `csrf.php`.
6. **Stored XSS Prevention**: All profile inputs sanitized with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` before document persistence.
7. **Timing Attack Protection**: Sleep delays on failed authentication to normalize execution timing.
8. **Information Disclosure Prevention**: Error display disabled in production (`display_errors = 0`), internal traces logged server-side, generic user-facing messages.

---

## Prerequisites & Dependencies

- **PHP**: 8.2 or higher
  - Extensions: `pdo_mysql`, `mongodb`, `openssl`, `json`, `curl`
- **MySQL**: 8.0 or MariaDB 10.4+
- **Redis Server**: 6.0+ (or Memurai on Windows)
- **MongoDB Server**: 6.0+
- **Composer**: 2.x

---

## Installation & Setup Guide

### 1. Clone & Configure Environment

```bash
# Copy the environment template
cp .env.example .env
```

Edit `.env` to match your local service credentials:

```ini
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost:8000

# MySQL Configuration
MYSQL_HOST=localhost
MYSQL_PORT=3306
MYSQL_DATABASE=nura_auth
MYSQL_USERNAME=root
MYSQL_PASSWORD=your_mysql_password

# MongoDB Configuration
MONGODB_URI=mongodb://localhost:27017
MONGODB_DATABASE=nura_profiles

# Redis Configuration
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

# Security & Session Settings
SESSION_TTL=3600
SESSION_COOKIE_NAME=nura_auth_session
CSRF_COOKIE_NAME=nura_csrf_token
COOKIE_SECURE=false
COOKIE_SAMESITE=Lax
```

### 2. Install Composer Dependencies

```bash
composer install
```

### 3. Initialize MySQL Database

Open your MySQL client (e.g. MySQL CLI, phpMyAdmin, or MySQL Workbench) and execute:

```bash
mysql -u root -p < database/mysql_schema.sql
```

This creates database `nura_auth` and table `users`.

### 4. Start Services

Ensure MySQL, Redis, and MongoDB are active:

- **MySQL**: Run via XAMPP Control Panel or `net start mysql`
- **MongoDB**: Run via `net start MongoDB` or `mongod`
- **Redis**: Run `redis-server.exe`

### 5. Launch the Application

You can use the built-in PHP development server:

```bash
php -S localhost:8000
```

Now open your browser and navigate to:
```
http://localhost:8000
```

---

## Testing & Verification

1. **Landing Page**: Visit `http://localhost:8000` to inspect the architecture and navigation.
2. **Registration**:
   - Navigate to `http://localhost:8000/register.html`.
   - Submit new user details.
   - Verify user record in MySQL: `SELECT id, username, email FROM nura_auth.users;`.
   - Verify initial document in MongoDB: `db.profiles.find({ user_id: 1 });`.
3. **Login**:
   - Navigate to `http://localhost:8000/login.html`.
   - Sign in with username or email.
   - Verify Redis session key: In Redis CLI run `KEYS auth_session:*`.
4. **Profile Console**:
   - Page redirects to `http://localhost:8000/profile.html`.
   - Observe real-time data populated from MongoDB and active Redis session details.
   - Click **"Edit Profile"**, update details (bio, phone, age, interests), and save.
   - Confirm real-time update in MongoDB.
5. **Sign Out**:
   - Click **"Sign Out"**.
   - Redis session key is destroyed, session cookie is invalidated, and user is redirected to `login.html`.
   - Direct access to `profile.html` now returns HTTP 401 Unauthorized and redirects to login.

---

## License

Developed for NURA AI LABS. All rights reserved.
