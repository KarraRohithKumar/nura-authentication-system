# NURA AI LABS - Multi-Tier Authentication Architecture

## 1. System Overview

The NURA AI Labs Authentication Platform is engineered using a **heterogeneous multi-tier storage paradigm**. Rather than relying on a single database for all workload types, the system strategically bifurcates concerns across three specialized datastores:

1. **MySQL 8.0 (ACID Relational Core)**: Manages strict identity records, unique constraints (`username`, `email`), and BCrypt cryptographic password hashes.
2. **Redis In-Memory Key-Value Store**: Serves as a high-speed, sub-millisecond session state cache with automated time-to-live (TTL) expiration, immune to database bottlenecks.
3. **MongoDB Document Store**: Handles unstructured and polymorphic user profile metadata, research interests, and biographical data without requiring relational schema migrations.

---

## 2. End-to-End System Architecture Diagram

```mermaid
graph TD
    subgraph Client_Layer ["Client Tier (Browser)"]
        UI["Bootstrap 5 + Vanilla CSS UI<br>(index.html, register.html, login.html, profile.html)"]
        AJAX["jQuery 3.7.1 AJAX Engine<br>(register.js, login.js, profile.js)"]
        COOKIES["Client Storage & Cookies<br>(HttpOnly Session Cookie + CSRF Cookie)"]
        UI <--> AJAX
        AJAX <--> COOKIES
    end

    subgraph API_Layer ["Backend API Tier (PHP 8.2)"]
        ROUTER["RESTful JSON Endpoints"]
        CONFIG["config.php & .env"]
        CSRF["csrf.php (Anti-CSRF Guard)"]
        SESSION["session.php (Redis Session Manager)"]
        DB["db.php (Singleton Connection Pool)"]
        
        ROUTER --> CSRF
        ROUTER --> SESSION
        ROUTER --> DB
        DB --> CONFIG
    end

    subgraph Storage_Layer ["Heterogeneous Database Tier"]
        MYSQL[("MySQL 8.0 (InnoDB)<br>Table: users<br>• id (PK)<br>• username (UQ)<br>• email (UQ)<br>• password_hash")]
        REDIS[("Redis In-Memory Engine<br>Key: auth_session:{token}<br>• user_id<br>• username, email<br>• IP & User-Agent<br>• TTL: 3600s")]
        MONGO[("MongoDB NoSQL Engine<br>Collection: profiles<br>• user_id<br>• name, age, phone<br>• organization, role<br>• bio, interests []")]
    end

    AJAX -->|"HTTPS POST /php/register.php"| ROUTER
    AJAX -->|"HTTPS POST /php/login.php"| ROUTER
    AJAX -->|"HTTPS GET, POST /php/profile.php"| ROUTER
    AJAX -->|"HTTPS POST /php/logout.php"| ROUTER

    DB -->|"PDO Prepared Statements"| MYSQL
    SESSION -->|"Predis / RESP Socket"| REDIS
    DB -->|"MongoDB PHP Driver"| MONGO
```

---

## 3. Security Architecture & Threat Mitigations

| Threat Vector | Mitigation Strategy Implemented in Nura AI Labs |
| :--- | :--- |
| **SQL Injection** | Exclusively uses PDO prepared statements with parameterized queries (`PDO::ATTR_EMULATE_PREPARES => false`). |
| **Cross-Site Request Forgery (CSRF)** | Double-submit cookie with cryptographic 64-char hex token validation via `X-CSRF-Token` headers. |
| **Cross-Site Scripting (XSS)** | All user inputs are sanitized with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` before document storage. |
| **Session Fixation** | Every login produces a newly synthesized cryptographically secure 256-bit token via `random_bytes(32)`. |
| **Session Hijacking** | Cookie configured with `HttpOnly` (inaccessible via JavaScript) and `SameSite=Lax` (configurable to `Strict` and `Secure`). |
| **Credential Enumeration** | Constant-time execution and generic error messaging (`Invalid username/email or password`) across login attempts. |
| **Credential Leakage** | All database connection strings and passwords strictly managed via `.env` and loaded securely through `vlucas/phpdotenv`. |
