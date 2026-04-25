# Tenant-scoped API updates (auto `company_id`)

**Base URL:** `{base_url}/api`  
**Auth:** `Authorization: Bearer {token}` (Sanctum), `Accept: application/json`

Company context for these routes comes from the **logged-in user’s** `company_id` (returned on **POST `/login`** as `user.company_id`). **Do not** send `company_id` in the JSON body or query to pick a tenant.

---

## Categories

| Method | Path | Body |
|--------|------|------|
| GET | `/categories` | none |
| POST | `/categories/store` | see below |
| POST | `/categories/{id}/update` | see below |

**POST `/categories/store`**

```json
{
  "category_name": "Hair",
  "color": "#FF5500",
  "status": "1",
  "description": "Optional, max 300 chars"
}
```

**POST `/categories/{id}/update`**

```json
{
  "category_name": "Hair",
  "color": "#FF5500",
  "status": "1",
  "description": "Optional, max 300 chars"
}
```

**GET `/categories` query (optional):** `per_page`, `search`, `status` (`1` / `0`).

---

## Customers

| Method | Path | Body |
|--------|------|------|
| GET | `/customers` | none |
| POST | `/customers/store` | see below |
| POST | `/customers/{id}/update` | see below |

**POST `/customers/store`**

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

**POST `/customers/{id}/update`**

Same fields as store.

**GET `/customers` query (optional):** `per_page`, `search`, `tags`.

---

## Products

| Method | Path | Body |
|--------|------|------|
| GET | `/products` | none |
| POST | `/products/store` | see below |
| POST | `/products/{id}/update` | see below |

**POST `/products/store`**

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

**POST `/products/{id}/update`**

Same fields as store.

**GET `/products` query (optional):** `per_page`, `search`, `category_id`, `brand`, `stock_status` (`in_stock` | `low_stock` | `out_of_stock` | `all`).

---

## Services

| Method | Path | Body |
|--------|------|------|
| GET | `/services` | none |
| POST | `/services/store` | see below |
| POST | `/services/{id}/update` | see below |

**POST `/services/store`**

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

**POST `/services/{id}/update`**

Same fields as store.

**GET `/services` query (optional):** `per_page`, `search`, `category_id`.

---

## Discounts

| Method | Path | Body |
|--------|------|------|
| GET | `/discounts/settings` | none |
| POST | `/discounts/settings` | see below |
| GET | `/discounts` | none |
| POST | `/discounts/store` | see below |
| POST | `/discounts/{id}/update` | see below |

**POST `/discounts/settings`**

```json
{
  "staff_discount_limit": 10,
  "require_discount_reason": true
}
```

**POST `/discounts/store`**

`categories` / `services` may be JSON arrays or JSON strings (decoded when valid).

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

**POST `/discounts/{id}/update`**

Same shape as store (validation rules aligned with update in app).

**GET `/discounts` query (optional):** `per_page`, `search`, `discount_type`, `applies_to`, `status`, `active_only`, `scheduled_only`.

---

## Bills (billing)

| Method | Path | Body |
|--------|------|------|
| GET | `/bills` | none |
| POST | `/bills/store` | see below |

**POST `/bills/store`**

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

**GET `/bills` query (optional):** `per_page`, `search`, `customer_id`, `user_id`, `payment_method`, `date_from`, `date_to`.

---

## Related listing behaviour (no tenant query param)

| Method | Path | Notes |
|--------|------|--------|
| GET | `/roles` | **`super_admin`:** all API-guard roles. **Company user:** roles for logged-in `company_id` only. No `company_id` query filter. |

Full rules and errors: **`API_REFERENCE.md`**.
