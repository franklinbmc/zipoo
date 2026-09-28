# ZIPOO — CODEX KICKSTART v2
## Slow, Phase-by-Phase Build Plan

**Project:** Zipoo  
**Primary App Domain:** `https://app.zipoo.co.tz`  
**Public Website:** `https://zipoo.co.tz`  
**Initial Market:** Tanzania  
**Languages:** English + Kiswahili from Day 1  
**Core Product:** Offline-first business management SaaS  
**Development Style:** Small phases. One working feature at a time. Never build the whole system at once.

---

# 1. IMPORTANT INSTRUCTION TO CODEX

This project MUST be developed slowly and incrementally.

## NON-NEGOTIABLE RULE

**Work on ONE PHASE ONLY.**

Do not start a future phase unless I explicitly say:

```text
PROCEED TO PHASE X
```

If the current task is Phase 1, do not create authentication logic, SaaS admin logic, tenant logic, sales, inventory, sync engines, reports, or other future modules.

Finish the current phase, test it, summarize the work, then STOP.

---

# 2. DO NOT DESTROY WORKING CODE

Codex must NEVER:

- Delete the whole project.
- Reinitialize the project unnecessarily.
- Replace working files with a completely new implementation without checking them first.
- Delete the database.
- Drop working tables unless explicitly instructed.
- Rewrite unrelated modules.
- Remove working features because a new feature is being added.

Before changing an existing file:

1. Read it.
2. Understand what is already working.
3. Make the smallest necessary change.
4. Preserve unrelated functionality.

If a major refactor is required, explain why before doing it.

---

# 3. TOKEN-EFFICIENT DEVELOPMENT

Do not waste tokens by repeatedly explaining the entire project.

For each task:

1. Inspect relevant files.
2. State briefly what will change.
3. Make the changes.
4. Test.
5. Give a short result summary.
6. STOP.

Do not regenerate huge files when only a few lines need changing.

---

# 4. TECHNOLOGY STACK

## Backend

```text
PHP 8+
PHP JSON APIs
MySQL
PDO Database Connections
```

## Frontend / PWA

```text
HTML5
Tailwind CSS
JavaScript
AJAX / Fetch API
IndexedDB later for offline business data
Service Worker
Web App Manifest
```

## Development

```text
Windows
XAMPP
Apache
MySQL
```

## Production

```text
https://app.zipoo.co.tz
```

---

# 5. PRODUCT PRINCIPLE

Zipoo will eventually become an offline-first business management SaaS.

However, do not build offline synchronization, POS, inventory, reports, subscriptions, SMS, WhatsApp, Android, or other large features at the beginning.

The order is:

```text
PWA DESIGN
    ↓
REGISTRATION & LOGIN
    ↓
SAAS ADMIN
    ↓
TENANT / BUSINESS ACCOUNTS
    ↓
CORE BUSINESS MODULES
    ↓
OFFLINE-FIRST DATA
    ↓
SYNC ENGINE
    ↓
ANDROID APP
    ↓
ADVANCED FEATURES
```

---

# 6. LANGUAGE REQUIREMENT

English and Kiswahili are equally important.

Do not hard-code user-facing text throughout the UI.

From Phase 1 create:

```text
/locales/en.json
/locales/sw.json
```

Example English:

```json
{
  "common.login": "Login",
  "common.register": "Create Account",
  "common.continue": "Continue"
}
```

Example Kiswahili:

```json
{
  "common.login": "Ingia",
  "common.register": "Fungua Akaunti",
  "common.continue": "Endelea"
}
```

Users must be able to switch:

```text
English | Kiswahili
```

The selected language should persist on the device.

Use:

```text
en
sw
```

---

# 7. ZIPOO BRAND DIRECTION

The design should feel:

- Modern
- Clean
- Trustworthy
- Friendly
- Professional
- Fast
- Mobile-first
- Suitable for smartphone and PC use

Avoid:

- Cluttered dashboards
- Extremely large cards
- Excessive gradients
- Too many colors
- Heavy animations
- Old-fashioned admin templates

Suggested palette:

```text
Primary Navy:      #0D2B5B
Primary Blue:      #1477FF
Teal / Success:    #0BBF9A
Background:        #F6F8FC
Surface:           #FFFFFF
Primary Text:      #12233F
Muted Text:        #6B7280
Danger:            #DC2626
Warning:           #F59E0B
```

Keep colors centralized with CSS variables or Tailwind configuration.

---

# 8. RESPONSIVE DESIGN

Zipoo is one PWA that must work well on:

```text
Android Phone
iPhone
Tablet
Laptop
Desktop PC
```

Mobile is the primary design target.

Desktop should not simply stretch the mobile interface.

Do not build all future menu items during Phase 1.

Only create the design system and app shell required by the current phase.

---

# 9. INITIAL PROJECT STRUCTURE

Start simple.

```text
zipoo/
├── index.html
├── manifest.json
├── service-worker.js
├── assets/
│   ├── css/
│   ├── js/
│   ├── images/
│   └── icons/
├── locales/
│   ├── en.json
│   └── sw.json
├── pages/
│   ├── login.html
│   └── register.html
└── README.md
```

Backend folders should only be added when Phase 2 begins.

Do not create lots of empty folders.

---

# 10. PHASE 1 — PWA DESIGN, LOOK & FEEL

## GOAL

Create the visual identity and PWA shell for Zipoo.

**NO database.**  
**NO login backend.**  
**NO registration backend.**  
**NO tenant backend.**  
**NO SaaS admin.**  
**NO business modules.**

This phase is UI/UX foundation only.

## Deliverables

### A. PWA Foundation

Create:

```text
index.html
manifest.json
service-worker.js
```

The PWA should:

- Have a valid manifest.
- Use Zipoo branding.
- Have install-ready metadata.
- Have application icons/placeholders.
- Load the application shell.
- Have a simple offline fallback shell.
- Be responsive.

Do not build complex offline data storage yet.

### B. Design System

Define:

- Brand colors
- Typography
- Buttons
- Inputs
- Select boxes
- Cards
- Modals
- Toasts
- Badges
- Empty states
- Loading indicators
- Online/offline indicator
- Mobile header
- Desktop header/sidebar concept

Create reusable styles/components.

### C. Welcome Screen

English:

```text
Run your business anywhere.

Sales, stock, customers and business insights —
simple, fast and ready for your business.
```

Kiswahili:

```text
Simamia biashara yako popote.

Mauzo, stock, wateja na taarifa muhimu za biashara —
kwa urahisi na haraka.
```

Buttons:

```text
Create Account
Login
```

Kiswahili:

```text
Fungua Akaunti
Ingia
```

### D. Login UI

Design only.

Fields:

```text
Phone Number / Email
Password
Remember Me
```

Actions:

```text
Login
Forgot Password?
Create Account
```

No backend authentication yet.

### E. Registration UI

Design only.

Visual steps:

```text
1. Personal Information
2. Business Information
3. Account Security
```

No database yet.

### F. Language Switcher

English/Kiswahili switching must work in Phase 1.

No page reload required.

Remember language preference locally.

### G. Responsive Testing

Verify:

```text
360px
390px
768px
1024px
1440px
```

No horizontal scrolling.

## Phase 1 Acceptance Criteria

Phase 1 is complete only when:

- PWA loads correctly.
- UI looks professional.
- Mobile layout is polished.
- Desktop layout is polished.
- Login screen looks complete.
- Registration screen looks complete.
- English/Kiswahili switch works.
- Manifest works.
- Service worker registers.
- Basic app shell can load offline.
- No browser console errors.
- No backend authentication exists yet.

After completing Phase 1:

**STOP.**

Return only a concise summary:

```text
PHASE 1 COMPLETE

Files created:
...

Files modified:
...

Tests performed:
...

Known issues:
...

Next proposed phase:
Phase 2 — Registration & Login Backend
```

Do not begin Phase 2.

---

# 11. PHASE 2 — REGISTRATION & LOGIN SYSTEM

Only start after:

```text
PROCEED TO PHASE 2
```

## GOAL

Make registration and login real.

Introduce PHP, MySQL and PDO.

Create only:

```text
tbl_users
tbl_businesses
tbl_business_users
tbl_sessions
tbl_password_resets
```

Do not create sales, stock, invoice, expense or report tables yet.

## Registration

Create:

```text
User
Business
Business/User relationship
```

Initial business owner role:

```text
OWNER
```

Fields:

### Personal

```text
Full Name
Phone Number
Email
```

### Business

```text
Business Name
Business Type
Region
District
```

### Security

```text
Password
Confirm Password
```

Normalize Tanzanian phone numbers.

Example:

```text
0712345678
```

to:

```text
255712345678
```

## Login

Allow:

```text
Phone Number
OR
Email
```

plus:

```text
Password
```

Use:

```php
password_hash()
password_verify()
```

Never store plaintext passwords.

Use toasts, inline validation and translated errors.

Do not use:

```javascript
alert()
```

After Phase 2: test and STOP.

---

# 12. PHASE 3 — SAAS ADMIN

Only start after:

```text
PROCEED TO PHASE 3
```

## GOAL

Create Zipoo's internal platform administration.

Initial dashboard:

```text
Total Businesses
Active Businesses
Inactive Businesses
Total Users
New Registrations
```

Initial modules:

```text
Dashboard
Businesses
Users
Plans
Subscriptions
System Settings
Audit Logs
```

Admin can:

- View businesses
- Search businesses
- View business profile
- Activate/deactivate business
- View owner
- View registration date
- View plan
- View basic account status

Do not build complicated billing integrations yet.

Tenant users must never access SaaS Admin.

Finish and STOP.

---

# 13. PHASE 4 — TENANT ACCOUNT FOUNDATION

Only start after:

```text
PROCEED TO PHASE 4
```

## GOAL

Create the logged-in business workspace.

Do not build POS yet.

Create:

```text
Dashboard
Business Profile
Team / Users
Settings
Logout
```

Add:

- Mobile navigation
- Desktop sidebar
- Top bar
- Language selector
- User menu

Every tenant-owned record must later be scoped using:

```text
business_id
```

Business A must never see Business B.

Business settings:

```text
Business Name
Logo
Phone
Email
Address
Region
District
TIN
VRN
Currency
Timezone
Default Language
```

Defaults:

```text
Currency: TZS
Timezone: Africa/Dar_es_Salaam
Languages: English / Kiswahili
```

Finish and STOP.

---

# 14. LATER PHASES

Each one is separate and requires explicit approval.

```text
PHASE 5  — Team, Roles & Permissions
PHASE 6  — Customers
PHASE 7  — Products & Categories
PHASE 8  — Stock Foundation
PHASE 9  — Sales / POS
PHASE 10 — Expenses
PHASE 11 — Customer Credit / Debt
PHASE 12 — Reports
PHASE 13 — Offline-First Business Data
PHASE 14 — Sync Engine
PHASE 15 — Subscriptions & Billing
PHASE 16 — SMS & WhatsApp
PHASE 17 — Native Android App
```

Never combine these phases unless explicitly instructed.

---

# 15. UI FEEDBACK RULES

Use:

```text
Toast
Modal
Inline Validation
Loading State
Empty State
Confirmation Dialog
```

Do not use browser alerts.

Destructive actions:

English:

```text
Are you sure?
Cancel | Continue
```

Kiswahili:

```text
Una uhakika?
Ghairi | Endelea
```

---

# 16. DATE / TIME / CURRENCY

User-facing dates:

```text
dd/mm/yyyy
```

Timezone:

```text
Africa/Dar_es_Salaam
```

Default currency:

```text
TZS
```

Example:

```text
TZS 250,000
```

---

# 17. GIT / CHECKPOINT RULE

At the end of every successful phase, create a clean checkpoint.

Suggested commit messages:

```text
phase-1: zipoo pwa design foundation
phase-2: registration and authentication
phase-3: saas admin foundation
phase-4: tenant workspace foundation
```

Never start a major next phase with broken uncommitted code.

---

# 18. TEST BEFORE CLAIMING SUCCESS

Codex must not claim a phase is complete without testing.

At minimum check:

- Browser console
- Responsive layout
- Manifest
- Service worker
- Translation rendering
- PHP syntax when backend begins
- API responses when backend begins
- Validation
- Authentication/authorization when applicable

If something requires manual testing, say exactly what remains.

---

# 19. CURRENT ACTIVE PHASE

## ONLY PHASE 1 IS AUTHORIZED.

Immediate job:

> Create the Zipoo PWA design system, look and feel, welcome page, login UI, registration UI, English/Kiswahili switching, manifest and basic service worker.

Do not create:

- MySQL database
- PHP registration backend
- Login API
- SaaS admin
- Tenant dashboard
- Products
- Customers
- Stock
- Sales
- Expenses
- Reports
- Offline business database
- Sync engine
- Android app

Those come later.

---

# 20. FIRST MESSAGE TO CODEX

After placing this file in the project root, send Codex:

```text
Read ZIPOO_KICKSTART_V2.md completely.

We are restarting Zipoo from scratch using a strict phase-by-phase approach.

ONLY PHASE 1 is authorized.

First inspect the current project directory. Do not delete or overwrite anything blindly.

Then implement only:
1. Zipoo PWA foundation
2. Design system / branding
3. Responsive welcome page
4. Login UI only
5. Registration UI only
6. English/Kiswahili switching
7. manifest.json
8. basic service worker / app shell
9. responsive mobile + desktop styling

Do not create a database or authentication backend yet.

When Phase 1 is complete, test it, summarize the files changed and STOP. Do not start Phase 2 until I explicitly authorize it.
```

---

# 21. FINAL PRINCIPLE

The success of Zipoo does not depend on how much code is produced.

It depends on building small parts that work correctly.

> **One phase. One working result. Test it. Keep it. Then move forward.**

Kiswahili:

> **Hatua moja. Kitu kimoja kifanye kazi vizuri. Tukijaribu, tukikubali, ndipo tuende hatua inayofuata.**
