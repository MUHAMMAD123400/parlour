# Parlour POS — API reference

Base URL for all endpoints below: **`{base_url}/api`**

Replace `{base_url}` with your application URL (for example `https://example.com` or `http://localhost:8000`).

---

## Authentication

Most routes require a **Sanctum** bearer token.

| Header | Value |
|--------|--------|
| `Authorization` | `Bearer {token}` |
| `Accept` | `application/json` |
| `Content-Type` | `application/json` (for JSON bodies) |

Tokens are issued on login and expire after **1 hour** (see `LoginController`).

---

## Multi-tenant behaviour (companies & modules)

- Users may have `company_id` (company staff) or `null` (e.g. `super_admin`).
- Routes under **`company.module:{key}`** are allowed only if:
  - the user has role **`super_admin`**, or  
  - the user’s company has an **active** module whose `permission_module_key` matches `{key}` (e.g. `customer`, `billing`).
- **`permission_module_key`** on a module row must match the `module` column on Spatie permissions (seeded keys include: `module`, `company`, `user`, `role`, `permission`, `category`, `customer`, `product`, `service`, `discount`, `billing`).

**Company routes** (`/companies/*`) are **not** wrapped in `company.module`; access is enforced inside the controller (`super_admin` vs `company_admin` / own company).

**Tenant data from login (no `company_id` in request):** For **categories**, **customers**, **products**, **services**, **discounts** (including **GET/POST `/discounts/settings`** and discount CRUD), and **bills**, the API **does not** take `company_id` from the JSON body or query string to choose a company. The tenant is always the **authenticated user’s** `company_id` (the same field returned on **POST `/login`** in `user.company_id`). Company staff should **omit** `company_id` in these requests; new rows are created for that company and list endpoints are scoped to it. Users without a `company_id` (e.g. some **`super_admin`** accounts) get **403** on these routes because there is no company on the session user.

**Roles (Spatie `roles` table):** each row may have **`company_id`**. **`super_admin`** uses **`company_id` null** (global). **`company_admin`** and other tenant roles are stored **per company** (same `name` + `guard_name` is allowed when `company_id` differs). Listing and user role assignment resolve roles using the target user’s **`company_id`**.

---

## Standard list response (pagination)

List endpoints that paginate return JSON shaped like:

```json
{
  "data": [ /* items */ ],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 10,
    "total": 42
  },
  "totals": {}
}
```

Optional `totals` may contain extra aggregates (e.g. customers index).

---

# 1. Auth

## POST `/login`

**Auth:** none  

**Body (JSON):**

| Field | Type | Rules |
|-------|------|--------|
| `email` | string | required, email, must exist in `users` |
| `password` | string | required, min 6 characters |

**Example:**

```json
{
  "email": "superadmin@example.com",
  "password": "superadmin123"
}
```

**Success (200):** `user` (id, name, email, `company_id`, `company` with `modules` if applicable, `roles`, `permissions`), `token`, `expires_at`. For company staff, **`user.company_id`** is the tenant used automatically by the POS module APIs (you do not send `company_id` on those requests).

**Errors:** `401` invalid credentials, `403` inactive user (`status` ≠ `1`).

---

## POST `/logout`

**Auth:** `auth:sanctum`  

**Body:** none  

**Headers:** `Authorization: Bearer {token}`

**Success (200):** message confirming logout (current token revoked).

---

# 2. Modules

**Middleware:** `auth:sanctum`, `company.module:module`  

**Spatie:** `module.index`, `module.show`, `module.create`, `module.edit`, `module.delete` on the matching actions.

| Method | Path | Description |
|--------|------|-------------|
| GET | `/modules` | List modules (paginated) |
| POST | `/modules/store` | Create module |
| GET | `/modules/{id}/show` | Show one module |
| POST | `/modules/{id}/update` | Update module |
| DELETE | `/modules/{id}/delete` | Soft-delete module |

**Query (GET `/modules`):**

| Parameter | Description |
|-----------|-------------|
| `per_page` | Page size (default 10) |
| `search` | Search name, `permission_module_key`, description |
| `module_status` | `1` or `0` |

**POST `/modules/store` body:**

```json
{
  "module_name": "Customers",
  "permission_module_key": "customer",
  "module_description": "Optional text",
  "module_status": "1",
  "module_icon": "optional-icon-key"
}
```

| Field | Rules |
|-------|--------|
| `module_name` | required, string, max 255 |
| `permission_module_key` | required, unique, max 64, regex `^[a-z0-9_]+$` |
| `module_description` | optional, string |
| `module_status` | optional, `1` or `0` (default `1`) |
| `module_icon` | optional, string, max 255 |

**POST `/modules/{id}/update` body:** same fields as store, but `module_status` is **required** `1` or `0`.

---

# 3. Companies

**Middleware:** `auth:sanctum` only (no `company.module`).

| Method | Path | Who |
|--------|------|-----|
| GET | `/companies` | `super_admin` (all, paginated) or `company_admin` (own company only, wrapped as one-item list) |
| POST | `/companies/store` | **`super_admin` only** — creates company, assigns modules, creates first `company_admin` user |
| GET | `/companies/{id}/show` | `super_admin` or `company_admin` for **own** `id` |
| POST | `/companies/{id}/update` | Same; `company_admin` **cannot** send `module_ids` |
| DELETE | `/companies/{id}/delete` | **`super_admin` only** |

**Query (GET `/companies` for super_admin):**

| Parameter | Description |
|-----------|-------------|
| `per_page` | Page size |
| `search` | `company_name`, `company_email` |

---

## POST `/companies/store`

**Body:**

```json
{
  "company_name": "Glow Parlour",
  "company_email": "info@glow.test",
  "company_phone": "+923001234567",
  "company_address": "Street 1",
  "company_city": "Lahore",
  "company_state": "Punjab",
  "company_zip": "54000",
  "company_country": "PK",
  "company_logo": "https://...",
  "company_website": "https://glow.test",
  "company_status": "1",
  "company_notes": "Internal notes",
  "company_description": "Public description",
  "module_ids": [1, 2, 6, 7],
  "admin": {
    "name": "Owner Name",
    "email": "owner@glow.test",
    "password": "secret123",
    "status": "1"
  }
}
```

| Field | Rules |
|-------|--------|
| `company_name` | required |
| `company_email`, `company_phone`, address fields, `company_logo`, `company_website`, `company_notes`, `company_description` | optional strings (see controller max lengths) |
| `company_status` | optional, `1` or `0` |
| `module_ids` | **required** array, min 1, each id must exist in `modules` and each selected module must have non-empty `permission_module_key` |
| `admin.name` | required |
| `admin.email` | required, unique in `users` |
| `admin.password` | required, min 6 |
| `admin.status` | optional, `1` or `0` |

Creates the company, syncs `company_modules`, creates the admin user with role `company_admin`, and assigns **direct** Spatie permissions for all permissions whose `module` is in the company’s active module keys.

---

## POST `/companies/{id}/update`

**super_admin** — full company fields + optional `module_ids` (array, min 1). Updating `module_ids` refreshes permissions for all `company_admin` users of that company.

**company_admin** — same profile fields as super_admin **except** `module_ids` is not accepted.

Shared validated fields:

| Field | Rules |
|-------|--------|
| `company_name` | required |
| `company_email` | optional, email |
| `company_phone`, `company_address`, `company_city`, `company_state`, `company_zip`, `company_country`, `company_logo`, `company_website`, `company_notes`, `company_description` | optional |
| `company_status` | required, `1` or `0` |
| `module_ids` | **super_admin only**, optional when present must be non-empty array of module ids |

---

# 4. Users

**Middleware:** `auth:sanctum`, `company.module:user`  

**Spatie:** `user.index`, `user.show`, `user.create`, `user.edit`, `user.delete` (and `user.edit` for `assignPermissions`).

| Method | Path |
|--------|------|
| GET | `/users` |
| POST | `/users/store` |
| GET | `/users/{id}/show` |
| POST | `/users/{id}/update` |
| DELETE | `/users/{id}/delete` |
| POST | `/users/{id}/update-role` |
| POST | `/users/{id}/assign-permissions` |

**Query (GET `/users`):**

| Parameter | Description |
|-----------|-------------|
| `per_page` | Page size |
| `search` | name, email |
| `status` | `1` or `0` |
| `company_id` | **super_admin only** — optional filter by company when listing users |

**Company staff:** listing is limited to users in the **same company** as the logged-in user (`user.company_id` from login); do not send `company_id`. **`super_admin`** may omit `company_id` to list across companies (subject to controller rules) or pass it to narrow results.

---

## POST `/users/store`

```json
{
  "name": "Staff User",
  "email": "staff@company.test",
  "password": "password123",
  "role": "cashier",
  "status": "1"
}
```

| Field | Rules |
|-------|--------|
| `name` | required, max 30 |
| `email` | required, unique |
| `password` | required |
| `role` | optional, must exist `roles.name` with `guard_name=api` |
| `status` | required, `1` or `0` |
| `company_id` | optional; **super_admin only** — assign the new user to a company. **Company staff:** omit; the new user’s company is taken from **your** logged-in `company_id` automatically |

Non–super-admin cannot assign roles `super_admin` or `company_admin`.

---

## POST `/users/{id}/update`

```json
{
  "name": "Staff User",
  "email": "staff@company.test",
  "password": null,
  "role": "cashier",
  "status": "1",
  "photo": "optional-url-or-path"
}
```

| Field | Rules |
|-------|--------|
| `name` | required |
| `email` | required, unique except self |
| `password` | optional; omit or null to keep |
| `role` | optional; empty clears roles |
| `status` | required, `1` or `0` |
| `photo` | optional string |
| `company_id` | **super_admin only** — may change the user’s company. **Company staff:** omit; company is unchanged from the logged-in context rules in the controller |

---

## POST `/users/{id}/update-role`

Replaces all roles for the user (full sync).

```json
{
  "role_names": ["cashier", "manager"]
}
```

Backward-compatible key:

```json
{
  "roles": ["cashier"]
}
```

| Field | Rules |
|-------|--------|
| `role_names` or `roles` | optional array; each value exists `roles.name` + `guard_name=api` |

**Who:** `super_admin` or `company_admin` (same company only). `company_admin` cannot assign `super_admin` or `company_admin`.

---

## POST `/users/{id}/assign-permissions`

Replaces **direct** permissions on the user (Spatie); role-based permissions still apply separately.

```json
{
  "permissions": [
    "customer.index",
    "customer.show",
    "billing.create"
  ]
}
```

| Field | Rules |
|-------|--------|
| `permissions` | required array; each exists `permissions.name` with `guard_name=api` |

**Who:** `super_admin` (any valid permission) or `company_admin` (same company; each permission must belong to one of the company’s licensed modules).

---

# 5. Roles

**Middleware:** `auth:sanctum`, `company.module:role`  

**Spatie:** `role.*` on CRUD.

| Method | Path | Notes |
|--------|------|--------|
| GET | `/roles` | List (paginated); **company staff** see roles for their company only; **`super_admin`** sees all API-guard roles; non–super-admin sees permissions filtered to company module keys |
| POST | `/roles/store` | **`super_admin` only** |
| GET | `/roles/{id}/show` | Non–super-admin: same company only; permissions filtered to company modules |
| POST | `/roles/{id}/update` | **`super_admin` only** |
| DELETE | `/roles/{id}/delete` | **`super_admin` only** |
| POST | `/roles/{id}/assign-permissions` | **`super_admin` only** (middleware `role:super_admin`) |

**Query (GET `/roles`):** `per_page`, `search` (role name). **`super_admin`** sees all API-guard roles; **company staff** see only roles for **their** logged-in `company_id` (no `company_id` query parameter).

**`company_id` on roles:** Each row has optional **`company_id`** (FK to `companies`). **`super_admin`** is a **global** role (`company_id` **null**). **`company_admin`** and other tenant roles are **per company** (same role **name** can exist once per company with `guard_name=api`). List/show for company staff only returns roles for **their** `company_id`.

---

## POST `/roles/store` / POST `/roles/{id}/update`

```json
{
  "company_id": 1,
  "name": "cashier",
  "description": "Optional",
  "permission_names": ["customer.index", "billing.create"]
}
```

| Field | Rules |
|-------|--------|
| `company_id` | optional; **omit or null** for a **global** role (e.g. `super_admin`-style); otherwise must exist in `companies` |
| `name` | required; unique per **`(company_id, name, guard_name)`** with `guard_name=api` |
| `description` | optional |
| `permission_names` | optional array; exists `permissions.name` |

---

## POST `/roles/{id}/assign-permissions`

Full replacement of permissions on the role.

```json
{
  "permissions": ["customer.index", "customer.show"]
}
```

| Field | Rules |
|-------|--------|
| `permissions` | required array; each exists `permissions.name` with `guard_name=api` |

---

# 6. Permissions

**Middleware:** `auth:sanctum`, `company.module:permission`  

**Spatie:** `permission.*` on CRUD.

| Method | Path | Notes |
|--------|------|--------|
| GET | `/permissions` | Paginated; non–super-admin scoped to company module keys |
| GET | `/permissions/all` | All allowed rows (no pagination), same scope |
| POST | `/permissions/store` | **`super_admin` only** |
| GET | `/permissions/{id}/show` | Scoped for non–super-admin |
| POST | `/permissions/{id}/update` | **`super_admin` only** |
| DELETE | `/permissions/{id}/delete` | **`super_admin` only** |

**Query (GET `/permissions`):** `per_page`, `search`, `type`, `module`, `group_type`.

---

## POST `/permissions/store` / POST `/permissions/{id}/update`

```json
{
  "name": "custom.action",
  "title": "Custom action",
  "description": "Optional",
  "type": "action",
  "module": "customer",
  "group_type": "api"
}
```

| Field | Rules |
|-------|--------|
| `name` | required, unique with `guard_name=api` |
| `title` | required |
| `description`, `type`, `module`, `group_type` | optional strings |

---

# 7. Categories

**Middleware:** `auth:sanctum`, `company.module:category`  

**Spatie:** `category.*`

| Method | Path |
|--------|------|
| GET | `/categories` |
| POST | `/categories/store` |
| GET | `/categories/{id}/show` |
| POST | `/categories/{id}/update` |
| DELETE | `/categories/{id}/delete` |

**Query (GET):** `per_page`, `search`, `status` (`1` / `0`). Listing is scoped to the **logged-in user’s company** (`user.company_id` from login); do not send `company_id`.

**POST `/categories/store`:**

```json
{
  "category_name": "Hair",
  "color": "#FF5500",
  "status": "1",
  "description": "Optional, max 300 chars"
}
```

| Field | Rules |
|-------|--------|
| `category_name` | required, unique within the authenticated user’s company |
| `color` | required |
| `status` | optional on store (`1`/`0`, defaults to `1`) |
| `description` | optional, max 300 |

**POST `/categories/{id}/update`:** same fields as store except `status` is required. Company is not sent in the body; it remains the logged-in user’s company.

---

# 8. Customers

**Middleware:** `auth:sanctum`, `company.module:customer`  

**Spatie:** `customer.*`

| Method | Path |
|--------|------|
| GET | `/customers` |
| POST | `/customers/store` |
| GET | `/customers/{id}/show` |
| GET | `/customers/{id}/visit-history` |
| GET | `/customers/{id}/spending-analysis` |
| POST | `/customers/{id}/update` |
| DELETE | `/customers/{id}/delete` |

**Query (GET `/customers`):** `per_page`, `search`, `tags` (array or single; JSON filter). Results and aggregates are scoped to the **logged-in user’s company**.

**POST `/customers/store`:**

```json
{
  "name": "Aisha Khan",
  "phone": "+923001234567",
  "email": "aisha@example.com",
  "address": "Optional",
  "date_of_birth": "1995-08-21",
  "tags": ["Regular", "VIP"],
  "notes": "Optional"
}
```

| Field | Rules |
|-------|--------|
| `name`, `phone` | required |
| `email` | optional, unique per **company** (same company as logged-in user) |
| `address`, `date_of_birth`, `tags`, `notes` | optional |

The customer’s `company_id` is set from the **authenticated user**; do not send `company_id` in the body.

**POST `/customers/{id}/update`:** same shape; `email` unique except current customer.

**GET `/customers/{id}/visit-history` query:** `per_page`, `date_from`, `date_to` (date strings).

**GET `/customers/{id}/spending-analysis`:** no body.

---

# 9. Products

**Middleware:** `auth:sanctum`, `company.module:product`  

**Spatie:** `product.*`

| Method | Path |
|--------|------|
| GET | `/products` |
| POST | `/products/store` |
| GET | `/products/{id}/show` |
| POST | `/products/{id}/update` |
| DELETE | `/products/{id}/delete` |

**Query (GET):** `per_page`, `search`, `category_id`, `brand`, `stock_status` (`in_stock` | `low_stock` | `out_of_stock` | `all`). Listing is scoped to the **logged-in user’s company**.

**POST `/products/store` & `/products/{id}/update`:**

```json
{
  "product_name": "Shampoo",
  "brand": "Optional",
  "category_id": 1,
  "description": "Optional",
  "quantity_in_stock": 10,
  "unit": "pcs",
  "purchase_price": 100.5,
  "selling_price": 150,
  "minimum_stock_alert": 2,
  "notes": "Optional"
}
```

| Field | Rules |
|-------|--------|
| `product_name`, `category_id`, `quantity_in_stock`, `purchase_price` | required |
| `category_id` | must belong to the **same company** as the logged-in user |
| `selling_price`, `minimum_stock_alert`, `brand`, `unit`, `description`, `notes` | optional |

`company_id` on the product is set from the **authenticated user**; do not send `company_id` in the body.

---

# 10. Services

**Middleware:** `auth:sanctum`, `company.module:service`  

**Spatie:** `service.*`

| Method | Path |
|--------|------|
| GET | `/services` |
| POST | `/services/store` |
| GET | `/services/{id}/show` |
| POST | `/services/{id}/update` |
| DELETE | `/services/{id}/delete` |

**Query (GET):** `per_page`, `search`, `category_id`. Listing is scoped to the **logged-in user’s company**.

**POST `/services/store` & `/services/{id}/update`:**

```json
{
  "service_name": "Haircut",
  "category_id": 1,
  "status": "1",
  "price": 500,
  "duration": 45,
  "description": "Optional"
}
```

| Field | Rules |
|-------|--------|
| `service_name`, `category_id`, `price`, `duration` | required |
| `category_id` | must belong to the **same company** as the logged-in user |
| `status` | optional, `1` or `0` |
| `description` | optional |

`company_id` on the service is set from the **authenticated user**; do not send `company_id` in the body.

---

# 11. Discounts

**Middleware:** `auth:sanctum`, `company.module:discount`  

**Spatie:** `discount.*`

| Method | Path |
|--------|------|
| GET | `/discounts/settings` |
| POST | `/discounts/settings` |
| GET | `/discounts` |
| POST | `/discounts/store` |
| GET | `/discounts/{id}/show` |
| POST | `/discounts/{id}/update` |
| DELETE | `/discounts/{id}/delete` |

**Query (GET `/discounts`):** `per_page`, `search`, `discount_type`, `applies_to`, `status`, `active_only`, `scheduled_only`. Listing is scoped to the **logged-in user’s company**.

**GET `/discounts/settings`:** no body; settings are loaded for the **authenticated user’s company** (no `company_id` query parameter).

**POST `/discounts/settings`:**

```json
{
  "staff_discount_limit": 10,
  "require_discount_reason": true
}
```

| Field | Rules |
|-------|--------|
| `staff_discount_limit` | required, integer 0–50 |
| `require_discount_reason` | required (boolean-like accepted) |

Settings are stored for the **logged-in user’s company**; do not send `company_id`.

---

## POST `/discounts/store` / POST `/discounts/{id}/update`

`categories` and `services` may be sent as JSON arrays or as JSON **strings** (multipart-friendly); the API decodes string values when valid JSON.

```json
{
  "offer_name": "Summer 10%",
  "description": "Optional",
  "discount_type": "percentage",
  "discount_value": 10,
  "applies_to": "specific_services",
  "categories": [1, 2],
  "services": [3, 4],
  "valid_from": "2026-04-01",
  "valid_to": "2026-06-30",
  "auto_apply": false,
  "status": "1"
}
```

| Field | Rules |
|-------|--------|
| `offer_name` | required |
| `discount_type` | `percentage` or `fixed` |
| `discount_value` | required, numeric ≥ 0 |
| `applies_to` | `all_services` \| `specific_categories` \| `specific_services` |
| `categories` | required (non-empty) if `applies_to` = `specific_categories`; array of **category ids** |
| `services` | required (non-empty) if `applies_to` = `specific_services`; array of **service ids** |
| `valid_from`, `valid_to` | required dates; `valid_to` ≥ `valid_from` |
| `auto_apply`, `status` | optional |

The discount’s `company_id` is set from the **authenticated user**; do not send `company_id` in the body.

---

# 12. Bills (billing)

**Middleware:** `auth:sanctum`, `company.module:billing`  

**Spatie:** `billing.*`

| Method | Path |
|--------|------|
| GET | `/bills` |
| POST | `/bills/store` |
| GET | `/bills/{id}/show` |
| DELETE | `/bills/{id}/delete` |

**Query (GET):** `per_page`, `search`, `customer_id`, `user_id`, `payment_method`, `date_from`, `date_to`. Listing is scoped to the **logged-in user’s company**.

**POST `/bills/store`:**

```json
{
  "customer_id": 1,
  "items": [
    { "service_id": 2, "quantity": 1 },
    { "service_id": 3, "quantity": 2 }
  ],
  "subtotal": 1500,
  "discount_amount": 0,
  "discount_type": "none",
  "discount_id": 5,
  "total": 1500,
  "payment_method": "cash",
  "paid_amount": 2000,
  "notes": "Optional"
}
```

| Field | Rules |
|-------|--------|
| `customer_id` | required; must belong to the **same company** as the logged-in user |
| `items` | required array, min 1 |
| `items.*.service_id` | required; must belong to the **same company** as the logged-in user |
| `items.*.quantity` | required, integer ≥ 1 |
| `subtotal`, `total`, `paid_amount` | required, numeric ≥ 0 |
| `payment_method` | `cash`, `card`, or `online` |
| `discount_amount` | optional, numeric ≥ 0 |
| `discount_type` | optional: `none`, `percentage`, `fixed` |
| `discount_id` | optional; must belong to the **same company** as the logged-in user |
| `notes` | optional |

The bill’s `company_id` is set from the **authenticated user**; do not send `company_id` in the body. Bill lines snapshot service price, category, etc. Authenticated user is stored as `user_id`.

---

# 13. Error responses

- **401** — Unauthenticated (missing/invalid token).
- **403** — Forbidden (Spatie permission, inactive user, company module not enabled, or controller business rules). JSON often includes `message` and sometimes `error` (e.g. `forbidden`, `module_not_enabled`).
- **422** — Validation errors (Laravel validation) or custom validation messages.
- **404** — Resource not found.

Permission denied from Spatie middleware may return:

```json
{
  "message": "You do not have permission to perform this action.",
  "error": "forbidden"
}
```

---

# 14. Related documentation

- Role/permission **sync** payloads for users and roles (conceptual overlap with sections 4–5): see `ROLE_PERMISSION_API.md` in this repository.

---

*Generated to match the codebase in this project. If routes or validation change, update this file alongside `routes/api.php` and the relevant controllers.*
