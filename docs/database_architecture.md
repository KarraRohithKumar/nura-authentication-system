# NURA AI LABS - Database Architecture & Schema Specification

This document details the schema models, storage engines, indexing strategies, and lifecycle policies across MySQL, Redis, and MongoDB.

---

## 1. Storage Responsibility Matrix

| Data Dimension | Database Engine | Format | Justification |
| :--- | :--- | :--- | :--- |
| **Authentication & Credentials** | **MySQL 8.0** | Relational / ACID Tables | Strict uniqueness constraints on `username` and `email`, relational integrity, deterministic indexing for authentication lookups. |
| **Session States & Tokens** | **Redis** | In-Memory Key-Value Strings | Sub-millisecond lookup latency, automatic TTL key eviction (3600s), zero database lock contention on high request volume. |
| **User Profile & Research Metadata** | **MongoDB** | BSON Documents | Schema flexibility for dynamic researcher profiles (varying attributes, nested bio, array of interests/skills) without DB migrations. |

---

## 2. MySQL Schema Specification

**Database**: `nura_auth`  
**Storage Engine**: `InnoDB`  
**Character Set**: `utf8mb4`  
**Collation**: `utf8mb4_unicode_ci`  

### Table: `users`

```sql
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(100) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`),
  UNIQUE KEY `uq_email` (`email`),
  INDEX `idx_users_username` (`username`),
  INDEX `idx_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### Field Specifications:
- `id`: Auto-incrementing unsigned 32-bit primary identifier.
- `username`: Unique username (3–30 characters), case-insensitive collation.
- `email`: Normalized unique lowercase email address.
- `password_hash`: 60 to 96 character BCrypt/Argon2 hash generated via `password_hash($pwd, PASSWORD_DEFAULT, ['cost' => 12])`.
- `created_at` / `updated_at`: Automated UTC timestamps for audit tracking.

---

## 3. Redis Key-Value Session Specification

**Instance**: `127.0.0.1:6379`  
**Default TTL**: `3600` seconds (1 hour sliding or fixed expiration)  

### Key Format:
`auth_session:{64_char_hex_token}`

Example: `auth_session:c3d8a4f107b34e69b59d4ec624856094b9f2910d65b16e8736d76efec1234567`

### Value Structure (Serialized JSON String):
```json
{
  "user_id": 1,
  "username": "rohith_ai",
  "email": "rohith@example.com",
  "created_at": "2026-10-11T20:30:00+00:00",
  "ip_address": "127.0.0.1",
  "user_agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) ..."
}
```

#### Commands Used:
- **Set with TTL**: `SETEX auth_session:{token} 3600 {payload}`
- **Lookup**: `GET auth_session:{token}`
- **Destroy**: `DEL auth_session:{token}`
- **Touch TTL**: `EXPIRE auth_session:{token} 3600`

---

## 4. MongoDB Profile Document Specification

**Database**: `nura_profiles`  
**Collection**: `profiles`  

### Sample Document Structure:
```json
{
  "_id": { "$oid": "67098e94fa0e2c6e3b52d9a1" },
  "user_id": 1,
  "name": "Rohith Kumar",
  "age": 24,
  "phone": "+91 9876543210",
  "bio": "AI Systems Engineer specializing in heterogeneous distributed databases and neural architectures.",
  "organization": "NURA AI Research Labs",
  "role": "Lead Architect",
  "location": "Bengaluru, India",
  "interests": [
    "Artificial Intelligence",
    "Distributed Systems",
    "Redis Caching",
    "MongoDB NoSQL",
    "PHP Security"
  ],
  "created_at": "2026-10-11T20:30:00+00:00",
  "updated_at": "2026-10-11T20:45:12+00:00"
}
```

#### Index Recommendations:
```javascript
db.profiles.createIndex({ "user_id": 1 }, { unique: true });
```
Ensures 1-to-1 correspondence between MySQL `users.id` and the MongoDB profile document.
