# Company Module - Recent Changes & Updates

Is document mein **Company Module** ke andar hone wali tamam nayi changes, updates aur additions ki mukammal tafseelat shamil hain.

---

## 1. Database Migrations (Naye Columns)
**Migration File:** `database/migrations/2026_09_08_234142_add_business_and_tax_details_to_companies_table.php`

`companies` table mein 4 naye business aur tax details ke columns add kiye gaye hain:
- **`company_type`** *(string, nullable)*: Company ki category/type (e.g. Salon, Spa, Parlour, etc.).
- **`ntn`** *(string, nullable)*: National Tax Number.
- **`strn`** *(string, nullable)*: Sales Tax Registration Number.
- **`license_number`** *(string, nullable)*: Business license ya registration number.

---

## 2. Model Updates (`app/Models/Company.php`)
- Naye columns ko `$fillable` array mein add kiya gaya hai taake mass assignment kaam kare:
  ```php
  'company_type',
  'ntn',
  'strn',
  'license_number',
  ```

---

## 3. Controller Updates (`app/Http/Controllers/Api/CompanyController.php`)

### A. Search Filter Enhancement (`index` method)
- Search filter mein `ntn` ko shamil kiya gaya hai. Ab company search karte waqt **Name**, **Email**, **Phone**, aur **NTN** charon fields se search hogi:
  ```php
  $q->where('company_name', 'like', '%' . $s . '%')
    ->orWhere('company_email', 'like', '%' . $s . '%')
    ->orWhere('ntn', 'like', '%' . $s . '%')
    ->orWhere('company_phone', 'like', '%' . $s . '%');
  ```

### B. Response Transformation (Formatted Fields)
API response mein frontend ke liye extra helper attributes add kiye gaye hain:
- **`logo_url`**: Company logo ka complete full URL (e.g. `http://example.com/company_logos/1_salon.png`).
- **`status_label`**: Status code `'1'` ya `'0'` ko `'Active'` / `'Inactive'` text mein convert karta hai.
- **`created_at_formatted`**: Readable date format (e.g. `09-Sep-2026, 2:25 pm`).
- **`updated_at_formatted`**: Readable date format (e.g. `09-Sep-2026, 2:25 pm`).

### C. Logo File Upload System (`handleLogoUpload`)
- Naya method `handleLogoUpload()` banaya gaya hai jo:
  - Image ko `public/company_logos` directory mein store karta hai.
  - File ka naam automatically sanitize karta hai: `{company_id}_{company_name_slug}.{extension}`.
  - Purana logo agar mojood ho toh use disk se delete (unlink) karta hai taake storage clean rahe.

### D. Flexible Request Validation & Data Handling (`store` & `update`)
- **Naye Fields Validation**: `company_type`, `ntn`, `strn`, `license_number` ko validation rules mein shamil kiya gaya.
- **Flexible Formats for Status**:
  - `company_status` ab `'Active'`, `'Inactive'`, `'1'`, ya `'0'` sab accept karta hai aur auto-normalize karta hai.
- **Admin Inputs Flexibility**:
  - Nested (`admin.name`, `admin.email`, `admin.password`, `admin.status`) aur flat (`admin_name`, `admin_email`, `admin_password`, `admin_status`) dono formats accept karta hai.
- **Subscription & License Date Inputs**:
  - `billing_cycle`, `subscription_type`, `type` teeno fields support karta hai.
  - License Dates (`license_start` / `start_date` aur `license_expiry` / `expire_date` / `end_date`) dono naming conventions support karta hai.
  - `payment_status` (`pending`, `paid`, `failed`, `refunded`) support karta hai.

### E. Detailed `show` Method Response
- `show($id)` API ab company ke sath sath uske primary **Admin User ki details** (`id`, `name`, `email`, `status`) bhi return karti hai.

---

## 4. Summary Table

| Feature / Area | Pehle (Old) | Ab (New / Updated) |
| :--- | :--- | :--- |
| **Tax / License Fields** | Nahi thay | `company_type`, `ntn`, `strn`, `license_number` added |
| **Search Function** | Name, Email, Phone | Name, Email, Phone, **NTN** |
| **Logo Upload** | Basic path | Dynamic upload, renaming (`{id}_{slug}`), old file auto-delete |
| **Response Format** | Raw DB fields | Added `logo_url`, `status_label`, `created_at_formatted`, etc. |
| **Admin Creation** | Sirf nested `admin.*` | Both `admin.*` aur `admin_*` supported |
| **Subscription Dates** | Fixed auto dates | Custom `start_date`, `end_date`, `license_expiry` support |
