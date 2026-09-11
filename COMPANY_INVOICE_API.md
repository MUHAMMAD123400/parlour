# Company Invoice API Documentation

> **Base URL:** `{base_url}/api/super-admin/invoices`  
> **Authentication:** Bearer Token (`auth:sanctum`)  
> **Role Required:** `super_admin`

---

## 📌 Table of Contents
1. [Authentication & Headers](#authentication--headers)
2. [Endpoints Summary](#endpoints-summary)
3. [1. List Invoices with Filter & KPI](#1-list-invoices-with-filter--kpi)
4. [2. Get KPI Summary](#2-get-kpi-summary)
5. [3. Create Invoice](#3-create-invoice)
6. [4. Show Invoice Details](#4-show-invoice-details)
7. [5. Update Invoice](#5-update-invoice)
8. [6. Delete Invoice](#6-delete-invoice)
9. [7. Update Payment Status](#7-update-payment-status)
10. [Field Definitions & Business Logic](#field-definitions--business-logic)

---

## Authentication & Headers

All endpoints require a valid Super Admin bearer token.

| Header | Value | Required |
|--------|-------|----------|
| `Authorization` | `Bearer {super_admin_token}` | **Yes** |
| `Accept` | `application/json` | **Yes** |
| `Content-Type` | `application/json` | **Yes** (for POST requests) |

---

## Endpoints Summary

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/super-admin/invoices` | List invoices with pagination, search, filters & summary KPI |
| `GET` | `/api/super-admin/invoices/summary` | Get invoice KPI summary totals (total, paid, unpaid, collected) |
| `POST` | `/api/super-admin/invoices/store` | Create a new company invoice |
| `GET` | `/api/super-admin/invoices/{id}/show` | Get single invoice details |
| `POST` | `/api/super-admin/invoices/{id}/update` | Update an existing invoice |
| `DELETE` | `/api/super-admin/invoices/{id}/delete` | Delete (soft-delete) an invoice |
| `POST` | `/api/super-admin/invoices/{id}/payment-status` | Quick update payment status |

---

## 1. List Invoices with Filter & KPI

Retrieve a paginated list of company invoices with comprehensive search, filtering, and sorting options.

- **Method:** `GET`
- **URL:** `/api/super-admin/invoices`

### Query Parameters

| Parameter | Type | Required | Description | Example |
|---|---|---|---|---|
| `page` | `integer` | No | Page number (default: `1`) | `1` |
| `per_page` | `integer` | No | Items per page (default: `10`) | `15` |
| `search` | `string` | No | Searches `invoice_number`, company name/phone/email, or plan name | `INV-1001` or `Beauty Salon` |
| `company_id` | `integer` / `string` / `array` | No | Filter by single company ID or comma-separated/array | `1` or `1,2,3` |
| `plan_id` | `integer` / `string` / `array` | No | Filter by plan ID or comma-separated/array | `2` or `1,2` |
| `billing_cycle` | `string` / `array` | No | Filter by billing cycle (`monthly`, `quarterly`, `yearly`) | `monthly` or `monthly,yearly` |
| `payment_status` | `string` / `array` | No | Filter by payment status (`pending`, `paid`, `overdue`, `cancelled`) | `paid` or `pending,overdue` |
| `from_date` / `start_date` / `date_from` | `string` (date) | No | Invoice date start filter (`YYYY-MM-DD`) | `2026-01-01` |
| `to_date` / `end_date` / `date_to` | `string` (date) | No | Invoice date end filter (`YYYY-MM-DD`) | `2026-12-31` |
| `min_amount` | `numeric` | No | Minimum `total_amount` | `50` |
| `max_amount` | `numeric` | No | Maximum `total_amount` | `500` |
| `sort_by` | `string` | No | Column: `id`, `date`, `amount`, `total_amount`, `created_at`, `invoice_number` | `created_at` |
| `sort_dir` | `string` | No | Direction: `asc` or `desc` (default: `desc`) | `desc` |

### Example Request
```http
GET /api/super-admin/invoices?page=1&per_page=10&payment_status=pending,overdue&search=Glow HTTP/1.1
Host: example.com
Authorization: Bearer 1|abcdef1234567890
Accept: application/json
```

### Example Response (`200 OK`)
```json
{
  "data": [
    {
      "id": 1,
      "invoice_number": "INV-1001",
      "company_id": 3,
      "plan_id": 2,
      "billing_cycle": "monthly",
      "payment_status": "pending",
      "date": "2026-09-01",
      "amount": "150.00",
      "discount": "20.00",
      "total_amount": "130.00",
      "notes": "First month onboarding discount applied.",
      "created_at": "2026-09-01T10:00:00.000000Z",
      "updated_at": "2026-09-01T10:00:00.000000Z",
      "deleted_at": null,
      "date_formatted": "Sep 01, 2026",
      "status_label": "Pending",
      "cycle_label": "Monthly",
      "company": {
        "id": 3,
        "company_name": "Glow Parlour & Spa",
        "company_phone": "+923001234567",
        "company_email": "info@glowspa.com",
        "company_logo": "companies/logos/glow.png"
      },
      "plan": {
        "id": 2,
        "name": "Professional Plan",
        "monthly": "150.00",
        "quarterly": "420.00",
        "yearly": "1500.00"
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 10,
    "total": 1
  },
  "totals": {
    "total_invoices": 25,
    "paid": 18,
    "unpaid": 7,
    "collected": 34500
  }
}
```

---

## 2. Get KPI Summary

Fetch high-level statistics for invoice metrics / cards.

- **Method:** `GET`
- **URL:** `/api/super-admin/invoices/summary`

### Example Request
```http
GET /api/super-admin/invoices/summary HTTP/1.1
Host: example.com
Authorization: Bearer 1|abcdef1234567890
Accept: application/json
```

### Example Response (`200 OK`)
```json
{
  "success": true,
  "data": {
    "total_invoices": 25,
    "paid": 18,
    "unpaid": 7,
    "collected": 34500
  }
}
```

---

## 3. Create Invoice

Create a new company invoice. The `invoice_number` is auto-generated (e.g. `INV-1001`, `INV-1002`). If `amount` is omitted or `0`, the amount is automatically fetched from the chosen plan and billing cycle.

- **Method:** `POST`
- **URL:** `/api/super-admin/invoices/store`

### Request Payload (`application/json`)

| Field | Type | Required | Validation Rules | Description |
|---|---|---|---|---|
| `company_id` | `integer` | **Yes** | `required\|integer\|exists:companies,id` | ID of the target company |
| `plan_id` | `integer` | **Yes** | `required\|integer\|exists:plans,id` | ID of the subscription plan |
| `billing_cycle` | `string` | **Yes** | `required\|in:monthly,quarterly,yearly` | Billing frequency |
| `payment_status` | `string` | No | `nullable\|in:pending,paid,overdue,cancelled` | Default: `pending` |
| `date` | `string` | **Yes** | `required\|date` | Invoice date (`YYYY-MM-DD`) |
| `amount` | `numeric` | No | `nullable\|numeric\|min:0` | Base amount (defaults to plan rate if not sent) |
| `discount` | `numeric` | No | `nullable\|numeric\|min:0` | Discount amount (default: `0`) |
| `notes` | `string` | No | `nullable\|string` | Optional invoice remarks or memo |

### Example Request Payload
```json
{
  "company_id": 3,
  "plan_id": 2,
  "billing_cycle": "monthly",
  "payment_status": "pending",
  "date": "2026-09-11",
  "amount": 150.00,
  "discount": 15.00,
  "notes": "Subscription renewal with 10% promotional discount"
}
```

### Example Response (`201 Created`)
```json
{
  "success": true,
  "message": "Invoice created successfully.",
  "data": {
    "id": 4,
    "invoice_number": "INV-1004",
    "company_id": 3,
    "plan_id": 2,
    "billing_cycle": "monthly",
    "payment_status": "pending",
    "date": "2026-09-11",
    "amount": 150,
    "discount": 15,
    "total_amount": 135,
    "notes": "Subscription renewal with 10% promotional discount",
    "created_at": "2026-09-11T03:10:00.000000Z",
    "updated_at": "2026-09-11T03:10:00.000000Z",
    "deleted_at": null,
    "date_formatted": "Sep 11, 2026",
    "status_label": "Pending",
    "cycle_label": "Monthly",
    "company": {
      "id": 3,
      "company_name": "Glow Parlour & Spa",
      "company_phone": "+923001234567",
      "company_email": "info@glowspa.com",
      "company_logo": "companies/logos/glow.png"
    },
    "plan": {
      "id": 2,
      "name": "Professional Plan",
      "monthly": "150.00",
      "quarterly": "420.00",
      "yearly": "1500.00"
    }
  }
}
```

---

## 4. Show Invoice Details

Fetch single invoice details with company address details and plan info.

- **Method:** `GET`
- **URL:** `/api/super-admin/invoices/{id}/show`

### URL Parameters

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | `integer` | **Yes** | Invoice ID |

### Example Request
```http
GET /api/super-admin/invoices/4/show HTTP/1.1
Host: example.com
Authorization: Bearer 1|abcdef1234567890
Accept: application/json
```

### Example Response (`200 OK`)
```json
{
  "success": true,
  "data": {
    "id": 4,
    "invoice_number": "INV-1004",
    "company_id": 3,
    "plan_id": 2,
    "billing_cycle": "monthly",
    "payment_status": "pending",
    "date": "2026-09-11",
    "amount": "150.00",
    "discount": "15.00",
    "total_amount": "135.00",
    "notes": "Subscription renewal with 10% promotional discount",
    "created_at": "2026-09-11T03:10:00.000000Z",
    "updated_at": "2026-09-11T03:10:00.000000Z",
    "deleted_at": null,
    "date_formatted": "Sep 11, 2026",
    "status_label": "Pending",
    "cycle_label": "Monthly",
    "company": {
      "id": 3,
      "company_name": "Glow Parlour & Spa",
      "company_phone": "+923001234567",
      "company_email": "info@glowspa.com",
      "company_logo": "companies/logos/glow.png",
      "company_address": "Shop 4, Main Boulevard, Gulberg III",
      "company_city": "Lahore",
      "company_state": "Punjab"
    },
    "plan": {
      "id": 2,
      "name": "Professional Plan",
      "monthly": "150.00",
      "quarterly": "420.00",
      "yearly": "1500.00"
    }
  }
}
```

---

## 5. Update Invoice

Update an existing company invoice. You can update specific fields or all fields.

- **Method:** `POST`
- **URL:** `/api/super-admin/invoices/{id}/update`

### URL Parameters

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | `integer` | **Yes** | Invoice ID |

### Request Payload (`application/json`)

| Field | Type | Required | Validation Rules | Description |
|---|---|---|---|---|
| `company_id` | `integer` | No | `sometimes\|required\|integer\|exists:companies,id` | Company ID |
| `plan_id` | `integer` | No | `sometimes\|required\|integer\|exists:plans,id` | Plan ID |
| `billing_cycle` | `string` | No | `sometimes\|required\|in:monthly,quarterly,yearly` | Billing frequency |
| `payment_status` | `string` | No | `sometimes\|required\|in:pending,paid,overdue,cancelled` | Payment status |
| `date` | `string` | No | `sometimes\|required\|date` | Invoice date (`YYYY-MM-DD`) |
| `amount` | `numeric` | No | `nullable\|numeric\|min:0` | Base amount |
| `discount` | `numeric` | No | `nullable\|numeric\|min:0` | Discount amount |
| `notes` | `string` | No | `nullable\|string` | Optional invoice notes |

### Example Request Payload
```json
{
  "payment_status": "paid",
  "discount": 20.00,
  "notes": "Paid via Bank Transfer - Slip Ref #778899"
}
```

### Example Response (`200 OK`)
```json
{
  "success": true,
  "message": "Invoice updated successfully.",
  "data": {
    "id": 4,
    "invoice_number": "INV-1004",
    "company_id": 3,
    "plan_id": 2,
    "billing_cycle": "monthly",
    "payment_status": "paid",
    "date": "2026-09-11",
    "amount": "150.00",
    "discount": "20.00",
    "total_amount": "130.00",
    "notes": "Paid via Bank Transfer - Slip Ref #778899",
    "created_at": "2026-09-11T03:10:00.000000Z",
    "updated_at": "2026-09-11T03:15:00.000000Z",
    "deleted_at": null,
    "date_formatted": "Sep 11, 2026",
    "status_label": "Paid",
    "cycle_label": "Monthly",
    "company": {
      "id": 3,
      "company_name": "Glow Parlour & Spa",
      "company_phone": "+923001234567",
      "company_email": "info@glowspa.com",
      "company_logo": "companies/logos/glow.png"
    },
    "plan": {
      "id": 2,
      "name": "Professional Plan",
      "monthly": "150.00",
      "quarterly": "420.00",
      "yearly": "1500.00"
    }
  }
}
```

---

## 6. Delete Invoice

Soft delete an invoice record.

- **Method:** `DELETE`
- **URL:** `/api/super-admin/invoices/{id}/delete`

### URL Parameters

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | `integer` | **Yes** | Invoice ID |

### Example Request
```http
DELETE /api/super-admin/invoices/4/delete HTTP/1.1
Host: example.com
Authorization: Bearer 1|abcdef1234567890
Accept: application/json
```

### Example Response (`200 OK`)
```json
{
  "success": true,
  "message": "Invoice deleted successfully."
}
```

---

## 7. Update Payment Status

Dedicated endpoint to rapidly switch invoice payment status (e.g. from quick action buttons on frontend tables/modals).

- **Method:** `POST`
- **URL:** `/api/super-admin/invoices/{id}/payment-status`

### URL Parameters

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | `integer` | **Yes** | Invoice ID |

### Request Payload (`application/json`)

| Field | Type | Required | Validation Rules | Description |
|---|---|---|---|---|
| `payment_status` | `string` | **Yes** | `required\|in:pending,paid,overdue,cancelled` | New status to set |

### Example Request Payload
```json
{
  "payment_status": "paid"
}
```

### Example Response (`200 OK`)
```json
{
  "success": true,
  "message": "Payment status updated successfully.",
  "data": {
    "id": 4,
    "invoice_number": "INV-1004",
    "company_id": 3,
    "plan_id": 2,
    "billing_cycle": "monthly",
    "payment_status": "paid",
    "date": "2026-09-11",
    "amount": "150.00",
    "discount": "20.00",
    "total_amount": "130.00",
    "notes": "Paid via Bank Transfer - Slip Ref #778899",
    "created_at": "2026-09-11T03:10:00.000000Z",
    "updated_at": "2026-09-11T03:20:00.000000Z",
    "deleted_at": null,
    "date_formatted": "Sep 11, 2026",
    "status_label": "Paid",
    "cycle_label": "Monthly",
    "company": {
      "id": 3,
      "company_name": "Glow Parlour & Spa",
      "company_phone": "+923001234567",
      "company_email": "info@glowspa.com",
      "company_logo": "companies/logos/glow.png"
    },
    "plan": {
      "id": 2,
      "name": "Professional Plan",
      "monthly": "150.00",
      "quarterly": "420.00",
      "yearly": "1500.00"
    }
  }
}
```

---

## Field Definitions & Business Logic

### `billing_cycle`
- `monthly`: Uses plan's `monthly` rate.
- `quarterly`: Uses plan's `quarterly` rate.
- `yearly`: Uses plan's `yearly` rate.

### `payment_status`
- `pending`: Invoice issued but not yet paid (counted in `unpaid` KPI).
- `overdue`: Payment overdue (counted in `unpaid` KPI).
- `paid`: Payment collected (counted in `paid` & `collected` sum KPI).
- `cancelled`: Invoice voided/cancelled.

### Calculation Logic
- `amount`: Base price (if not provided, retrieved from `Plan` based on `billing_cycle`).
- `discount`: Deducted from `amount`.
- `total_amount`: Calculated as `max(0, amount - discount)`.
- `invoice_number`: Auto-generated starting with prefix `INV-1001`, `INV-1002`, etc.
