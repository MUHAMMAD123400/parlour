# Super Admin — IP Management API Documentation

> **Base URL:** `{base_url}/api/super-admin/ips`  
> **Authentication:** Bearer Token (`auth:sanctum`)  
> **Role Required:** `super_admin`

---

## 📌 Table of Contents
1. [Authentication & Headers](#authentication--headers)
2. [Endpoints Summary](#endpoints-summary)
3. [1. List IPs (with Search, Filter & Pagination)](#1-list-ips-with-search-filter--pagination)
4. [2. Add New IP](#2-add-new-ip)
5. [3. Show IP Details](#3-show-ip-details)
6. [4. Update IP](#4-update-ip)
7. [5. Delete IP](#5-delete-ip)
8. [6. Toggle IP Status](#6-toggle-ip-status)
9. [Field Definitions & Validation Rules](#field-definitions--validation-rules)
10. [Error Responses](#error-responses)

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
| `GET` | `/api/super-admin/ips` | List all whitelisted/managed IPs with pagination, search & status filter |
| `POST` | `/api/super-admin/ips/store` | Add a new IP address to the system |
| `GET` | `/api/super-admin/ips/{id}/show` | Get single IP details by ID |
| `POST` | `/api/super-admin/ips/{id}/update` | Update an existing IP address record |
| `DELETE` | `/api/super-admin/ips/{id}/delete` | Delete an IP address from the system |
| `POST` | `/api/super-admin/ips/{id}/toggle-status` | Quick toggle status between Active (`1`) and Inactive (`0`) |

---

## 1. List IPs (with Search, Filter & Pagination)

Retrieve a paginated list of IP addresses with optional search and status filtering.

- **Method:** `GET`
- **URL:** `/api/super-admin/ips`

### Query Parameters

| Parameter | Type | Required | Description | Example |
|---|---|---|---|---|
| `page` | `integer` | No | Page number (default: `1`) | `1` |
| `per_page` | `integer` | No | Number of records per page (default: `10`) | `15` |
| `search` | `string` | No | Search across `ip`, `title`, or `description` | `192.168` or `Office` |
| `status` | `integer` / `string` | No | Filter by active status: `1` (active) or `0` (inactive) | `1` |

### Example Request
```http
GET /api/super-admin/ips?page=1&per_page=10&status=1&search=HeadOffice HTTP/1.1
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
      "ip": "192.168.1.100",
      "title": "Head Office Network",
      "description": "Main office fixed IP for portal access",
      "status": 1,
      "created_at": "2026-09-11T15:00:00.000000Z",
      "updated_at": "2026-09-11T15:00:00.000000Z"
    },
    {
      "id": 2,
      "ip": "203.0.113.45",
      "title": "Branch Office 1",
      "description": "Branch office router IP",
      "status": 1,
      "created_at": "2026-09-11T15:05:00.000000Z",
      "updated_at": "2026-09-11T15:05:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 10,
    "total": 2
  },
  "totals": []
}
```

---

## 2. Add New IP

Create and whitelist a new IP address.

- **Method:** `POST`
- **URL:** `/api/super-admin/ips/store`

### Request Body (Payload)

| Field | Type | Required | Description | Example |
|---|---|---|---|---|
| `ip` | `string` | **Yes** | Valid IPv4 or IPv6 address. Must be unique in `ips` table. | `"192.168.1.105"` |
| `title` | `string` | No | Label or name for the IP address (max 255 chars). | `"Branch 2 - Lahore"` |
| `description` | `string` | No | Additional notes or details. | `"Dedicated static IP for Branch 2"` |
| `status` | `integer` / `string` | No | Status: `1` (Active) or `0` (Inactive). Defaults to `1`. | `1` |

### Example Request
```http
POST /api/super-admin/ips/store HTTP/1.1
Host: example.com
Authorization: Bearer 1|abcdef1234567890
Content-Type: application/json
Accept: application/json

{
  "ip": "192.168.1.105",
  "title": "Branch 2 - Lahore",
  "description": "Dedicated static IP for Branch 2",
  "status": 1
}
```

### Example Response (`201 Created`)
```json
{
  "success": true,
  "message": "IP address added successfully.",
  "data": {
    "id": 3,
    "ip": "192.168.1.105",
    "title": "Branch 2 - Lahore",
    "description": "Dedicated static IP for Branch 2",
    "status": 1,
    "created_at": "2026-09-11T15:10:00.000000Z",
    "updated_at": "2026-09-11T15:10:00.000000Z"
  }
}
```

---

## 3. Show IP Details

Retrieve details of a single IP address by its ID.

- **Method:** `GET`
- **URL:** `/api/super-admin/ips/{id}/show`

### URL Parameters

| Parameter | Type | Required | Description | Example |
|---|---|---|---|---|
| `id` | `integer` | **Yes** | IP Record ID | `3` |

### Example Request
```http
GET /api/super-admin/ips/3/show HTTP/1.1
Host: example.com
Authorization: Bearer 1|abcdef1234567890
Accept: application/json
```

### Example Response (`200 OK`)
```json
{
  "success": true,
  "message": "IP address fetched successfully.",
  "data": {
    "id": 3,
    "ip": "192.168.1.105",
    "title": "Branch 2 - Lahore",
    "description": "Dedicated static IP for Branch 2",
    "status": 1,
    "created_at": "2026-09-11T15:10:00.000000Z",
    "updated_at": "2026-09-11T15:10:00.000000Z"
  }
}
```

---

## 4. Update IP

Update an existing IP record.

- **Method:** `POST`
- **URL:** `/api/super-admin/ips/{id}/update`

### URL Parameters

| Parameter | Type | Required | Description | Example |
|---|---|---|---|---|
| `id` | `integer` | **Yes** | IP Record ID | `3` |

### Request Body (Payload)

| Field | Type | Required | Description | Example |
|---|---|---|---|---|
| `ip` | `string` | **Yes** | Valid IPv4/IPv6 address. Must be unique (ignores current record ID). | `"192.168.1.106"` |
| `title` | `string` | No | Label or name (max 255 chars). | `"Branch 2 - Updated"` |
| `description` | `string` | No | Detailed notes. | `"Changed ISP router IP"` |
| `status` | `integer` / `string` | No | Status: `1` (Active) or `0` (Inactive). | `1` |

### Example Request
```http
POST /api/super-admin/ips/3/update HTTP/1.1
Host: example.com
Authorization: Bearer 1|abcdef1234567890
Content-Type: application/json
Accept: application/json

{
  "ip": "192.168.1.106",
  "title": "Branch 2 - Updated",
  "description": "Changed ISP router IP",
  "status": 1
}
```

### Example Response (`200 OK`)
```json
{
  "success": true,
  "message": "IP address updated successfully.",
  "data": {
    "id": 3,
    "ip": "192.168.1.106",
    "title": "Branch 2 - Updated",
    "description": "Changed ISP router IP",
    "status": 1,
    "created_at": "2026-09-11T15:10:00.000000Z",
    "updated_at": "2026-09-11T15:15:00.000000Z"
  }
}
```

---

## 5. Delete IP

Delete an IP address record.

- **Method:** `DELETE`
- **URL:** `/api/super-admin/ips/{id}/delete`

### URL Parameters

| Parameter | Type | Required | Description | Example |
|---|---|---|---|---|
| `id` | `integer` | **Yes** | IP Record ID | `3` |

### Example Request
```http
DELETE /api/super-admin/ips/3/delete HTTP/1.1
Host: example.com
Authorization: Bearer 1|abcdef1234567890
Accept: application/json
```

### Example Response (`200 OK`)
```json
{
  "success": true,
  "message": "IP address deleted successfully."
}
```

---

## 6. Toggle IP Status

Quickly toggle an IP status between Active (`1`) and Inactive (`0`).

- **Method:** `POST`
- **URL:** `/api/super-admin/ips/{id}/toggle-status`

### URL Parameters

| Parameter | Type | Required | Description | Example |
|---|---|---|---|---|
| `id` | `integer` | **Yes** | IP Record ID | `1` |

### Example Request
```http
POST /api/super-admin/ips/1/toggle-status HTTP/1.1
Host: example.com
Authorization: Bearer 1|abcdef1234567890
Accept: application/json
```

### Example Response (`200 OK`)
```json
{
  "success": true,
  "message": "IP status updated successfully.",
  "data": {
    "id": 1,
    "ip": "192.168.1.100",
    "title": "Head Office Network",
    "description": "Main office fixed IP for portal access",
    "status": 0,
    "created_at": "2026-09-11T15:00:00.000000Z",
    "updated_at": "2026-09-11T15:20:00.000000Z"
  }
}
```

---

## Field Definitions & Validation Rules

| Column | Type | Nullable | Validation Rules | Description |
|---|---|---|---|---|
| `id` | `bigint unsigned` | No | Auto-increment primary key | Unique record ID |
| `ip` | `string(45)` | No | `required \| ip \| unique:ips,ip` | Valid IPv4/IPv6 address, unique |
| `title` | `string(255)` | Yes | `nullable \| string \| max:255` | Human-friendly name/label |
| `description` | `text` | Yes | `nullable \| string` | Optional notes/details |
| `status` | `tinyint` | No | `nullable \| in:0,1` (default: `1`) | `1` = Active, `0` = Inactive |
| `created_at` | `timestamp` | Yes | Auto-managed | Created timestamp |
| `updated_at` | `timestamp` | Yes | Auto-managed | Last updated timestamp |

---

## Error Responses

### 422 Unprocessable Entity (Validation Error)
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "ip": [
      "The ip field must be a valid IP address.",
      "The ip has already been taken."
    ]
  }
}
```

### 404 Not Found (Resource Not Found)
```json
{
  "message": "Resource not found",
  "error": "No query results for model [App\\Models\\Ip] 999"
}
```

### 401 Unauthorized (Missing / Invalid Token)
```json
{
  "message": "Unauthenticated."
}
```

### 403 Forbidden (Non-SuperAdmin Access)
```json
{
  "message": "Unauthorized. Super Admin access only."
}
```
