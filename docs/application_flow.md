# NURA AI LABS - Application Workflows & Sequence Flows

This document details the lifecycle workflows for User Registration, Authentication/Login, Profile Retrieval & Mutation, and Session Invalidation (Logout).

---

## 1. User Registration Flow

```mermaid
sequenceDiagram
    autonumber
    actor User as User (Browser)
    participant JS as js/register.js
    participant PHP as php/register.php
    participant CSRF as php/csrf.php
    participant MySQL as MySQL (nura_auth)
    participant Mongo as MongoDB (nura_profiles)

    User->>JS: Enters username, email, password, confirm password
    JS->>JS: Client-side regex & strength validation
    JS->>CSRF: GET /php/csrf.php (fetches token & sets cookie)
    CSRF-->>JS: Returns CSRF token
    JS->>PHP: AJAX POST /php/register.php (JSON payload + X-CSRF-Token)
    PHP->>PHP: Validate CSRF token & input rules
    PHP->>MySQL: SELECT id FROM users WHERE username = ? OR email = ?
    alt Duplicate Username or Email
        MySQL-->>PHP: Record found
        PHP-->>JS: HTTP 409 Conflict (User already exists)
        JS-->>User: Display error notification
    else Account is New
        MySQL-->>PHP: No match
        PHP->>PHP: password_hash(password, PASSWORD_DEFAULT, ['cost' => 12])
        PHP->>MySQL: INSERT INTO users (username, email, password_hash)
        MySQL-->>PHP: lastInsertId (new user_id)
        PHP->>Mongo: insertOne({ user_id, name, bio, interests, ... })
        Mongo-->>PHP: Insert confirmed
        PHP-->>JS: HTTP 201 Created (Success JSON)
        JS-->>User: Display success banner & redirect to login.html
    end
```

---

## 2. User Authentication & Login Flow

```mermaid
sequenceDiagram
    autonumber
    actor User as User (Browser)
    participant JS as js/login.js
    participant PHP as php/login.php
    participant MySQL as MySQL (nura_auth)
    participant Redis as Redis Session Cache

    User->>JS: Submits username/email and password
    JS->>PHP: AJAX POST /php/login.php (with X-CSRF-Token)
    PHP->>PHP: Validate CSRF token
    PHP->>MySQL: SELECT id, username, email, password_hash FROM users WHERE ...
    MySQL-->>PHP: User row
    alt Invalid User or Password Mismatch
        PHP->>PHP: password_verify(password, hash) fails
        PHP-->>JS: HTTP 401 Unauthorized (Generic security message)
        JS-->>User: Display error message
    else Credentials Verified
        PHP->>PHP: Generate random 64-char hex session ID
        PHP->>Redis: SETEX auth_session:{token} 3600 (JSON payload)
        Redis-->>PHP: OK
        PHP->>PHP: setcookie('nura_auth_session', token, [HttpOnly, SameSite=Lax])
        PHP-->>JS: HTTP 200 OK (session_id, user metadata)
        JS->>JS: Store session metadata in sessionStorage
        JS-->>User: Redirect to profile.html
    end
```

---

## 3. Profile Retrieval & Update Flow

```mermaid
sequenceDiagram
    autonumber
    actor User as User (Browser)
    participant JS as js/profile.js
    participant PHP as php/profile.php
    participant Redis as Redis Session Cache
    participant MySQL as MySQL (nura_auth)
    participant Mongo as MongoDB (nura_profiles)

    Note over User, Mongo: Phase A: Load Profile (GET)
    JS->>PHP: AJAX GET /php/profile.php (with Session Cookie)
    PHP->>Redis: GET auth_session:{sessionId}
    alt Invalid or Expired Session
        Redis-->>PHP: null / key missing
        PHP-->>JS: HTTP 401 Unauthorized
        JS-->>User: Redirect to login.html
    else Active Session Found
        Redis-->>PHP: { user_id, username, email, ... }
        PHP->>MySQL: SELECT id, username, email, created_at FROM users WHERE id = ?
        MySQL-->>PHP: Account record
        PHP->>Mongo: findOne({ user_id })
        Mongo-->>PHP: Profile document (name, age, bio, interests, ...)
        PHP-->>JS: HTTP 200 OK (Consolidated profile data)
        JS-->>User: Render profile dashboard and UI badges
    end

    Note over User, Mongo: Phase B: Update Profile (POST)
    User->>JS: Submits edited fields via modal form
    JS->>PHP: AJAX POST /php/profile.php (Payload + X-CSRF-Token)
    PHP->>PHP: Validate CSRF & Redis session
    PHP->>PHP: Sanitize inputs with htmlspecialchars()
    PHP->>Mongo: updateOne({ user_id }, { $set: updateFields }, { upsert: true })
    Mongo-->>PHP: Update acknowledged
    PHP-->>JS: HTTP 200 OK (Updated profile)
    JS-->>User: Refresh profile view & notify success
```

---

## 4. User Logout & Session Termination Flow

```mermaid
sequenceDiagram
    autonumber
    actor User as User (Browser)
    participant JS as js/profile.js
    participant PHP as php/logout.php
    participant Redis as Redis Session Cache

    User->>JS: Clicks "Sign Out" button
    JS->>PHP: AJAX POST /php/logout.php
    PHP->>Redis: DEL auth_session:{sessionId}
    Redis-->>PHP: 1 (Key deleted)
    PHP->>PHP: Clear cookie 'nura_auth_session' (expires in past)
    PHP-->>JS: HTTP 200 OK
    JS->>JS: Clear sessionStorage
    JS-->>User: Redirect to login.html?logout=true
```
