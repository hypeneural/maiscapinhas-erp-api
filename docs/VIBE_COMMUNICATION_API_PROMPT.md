# Vibe Coding Prompt - Internal Communication API (Laravel 12)

You are a senior Laravel 12 API engineer focused on RBAC multi-store, performance, and relational modeling. Build the "Sistema de Comunicacao Interna" module as API-only. Use the existing stack and patterns exactly as described below.

## 0) Design goals (robust + maintainable + scalable)
- Keep business rules in a service layer, not in controllers.
- Avoid N+1 and avoid creating receipts in bulk.
- Make it easy to add new features (icon/image, actions, attachments, new scopes).
- Separate UI hints from core logic (use optional fields or meta_json).
- Use consistent enums/consts and shared query builders.

## 1) Current stack and patterns (must follow)
- Laravel 12
- Auth: Sanctum (Bearer token)
- DB: MariaDB/MySQL
- Routes: `routes/api_v1.php` (all routes prefixed with `/api/v1`)
- JSON responses: `app/Http/Traits/ApiResponse` (`data` + `meta` with `request_id` + `timestamp`; paginated uses `meta.pagination`)
- Validation: FormRequest classes
- Authorization: Policies/Gates
- Controllers in `app/Http/Controllers/Api/V1`

## 2) Existing core schema and RBAC (use as base)

### users table
Columns:
- id
- name
- email (unique)
- email_verified_at (nullable)
- password
- remember_token
- active (boolean, default true)
- is_super_admin (boolean, default false)
- birth_date (nullable)
- hire_date (nullable)
- whatsapp (nullable)
- avatar_url (nullable)
- instagram (nullable)
- cpf (nullable)
- pix_key (nullable)
- created_at, updated_at

Notes:
- User model uses Sanctum + Spatie `HasRoles` (roles/permissions tables exist).
- Use `is_super_admin` as global override for access checks.

### stores table
Columns:
- id
- name
- codigo (nullable, unique)
- city
- active (boolean, default true)
- photo_url (nullable)
- address (nullable)
- neighborhood (nullable)
- state (nullable)
- zip_code (nullable)
- latitude (nullable)
- longitude (nullable)
- phone (nullable)
- whatsapp (nullable)
- instagram (nullable)
- opening_hours (json, nullable)
- cnpj (nullable)
- troco_padrao (decimal, default 500.00)
- created_at, updated_at

### store_users table (store-scoped roles)
Columns:
- id
- store_id (FK stores.id)
- user_id (FK users.id)
- role enum: admin, gerente, conferente, vendedor
- created_at, updated_at

Constraints:
- unique(store_id, user_id)
- index(role)

Relationships:
- User hasMany StoreUser; User belongsToMany Store via store_users (pivot role)
- Store hasMany StoreUser; Store belongsToMany User via store_users

RBAC for this module should be store-scoped using store_users.role and is_super_admin.

## 3) Product requirements (Mais Capinhas)
- Dashboard shows a rotating banner carousel with icon or image + short text.
- Messages can be scheduled (starts_at/expires_at).
- "Advertencia" is red and very visible, with a big "RECEBIDO" button.
- Important message can open a modal ("Voce tem uma mensagem importante").
- Types: recado or advertencia.
- Scopes: global, store, user, role.
- Only admin can create global messages.
- When user clicks "Ler agora", mark as seen and show details.
- User has a personal history (inbox) with seen/ack status.

## 4) Data model (v1, robust)

### A) announcements
Core columns:
- id (bigint PK)
- title (varchar 120) short banner title
- message (text) full content
- excerpt (varchar 200, nullable) short preview (optional; can be auto-generated)
- type enum: recado, advertencia
- severity enum: info, warning, danger
- display_mode enum: banner, modal, both (default banner)
- icon (varchar 50, nullable) optional icon name for UI
- image_url (varchar 500, nullable) optional banner image URL
- image_alt (varchar 120, nullable)
- cta_label (varchar 80, nullable) e.g. "Ler agora"
- cta_url (varchar 500, nullable)
- scope enum: global, store, user, role
- require_ack boolean default false
- status enum: draft, scheduled, active, expired, archived default draft
- starts_at datetime nullable
- expires_at datetime nullable
- repeat_every_minutes int nullable
- priority int default 0 (higher shows first)
- pinned_until datetime nullable (forces top of list while valid)
- meta_json json nullable (future UI hints without migrations)
- created_by_user_id FK users.id
- published_by_user_id FK users.id nullable
- published_at datetime nullable
- archived_by_user_id FK users.id nullable
- archived_at datetime nullable
- created_at, updated_at, deleted_at (soft delete optional)

Indexes:
- (status, starts_at, expires_at)
- (scope)
- (severity, require_ack)
- (priority, pinned_until)
- (published_at)

Notes:
- Store dates in UTC and compare in app timezone consistently.
- status is server-controlled; clients never set active/expired directly.

### B) announcement_targets (segmentation)
Columns:
- id
- announcement_id FK announcements.id (cascade)
- target_type enum: store, user, role
- target_id string(64)
  - store: store_id as string
  - user: user_id as string
  - role: store_users.role value (admin, gerente, conferente, vendedor)
- created_at

Indexes:
- announcement_id
- (target_type, target_id)

Rule:
- scope=global does not require targets. All other scopes require targets.

### C) announcement_receipts (per user control)
Columns:
- id
- announcement_id FK announcements.id (cascade)
- user_id FK users.id (cascade)
- store_id FK stores.id (nullable)
- delivered_at datetime nullable
- seen_at datetime nullable (when user opens details, "Ler agora")
- acknowledged_at datetime nullable (when user clicks "RECEBIDO")
- dismissed_at datetime nullable (optional, hide non-ack items)
- last_shown_at datetime nullable
- show_count int default 0
- snooze_until datetime nullable (optional)
- created_at, updated_at

Unique:
- unique(announcement_id, user_id)

Indexes:
- user_id
- announcement_id
- acknowledged_at
- last_shown_at
- dismissed_at

### Optional extension (v1.1+)
If you need multiple attachments or richer UI:
- announcement_assets (id, announcement_id, type: image|file, url, thumbnail_url, title, sort_order, created_at)
- announcement_actions (id, announcement_id, label, url, style, sort_order, created_at)

## 5) Eloquent models and relationships
Model Announcement:
- hasMany targets()
- hasMany receipts()
- belongsTo createdBy(User)
- scopeActiveNow($q, $now): starts_at <= now AND (expires_at is null OR expires_at > now) AND status in (active, scheduled)
- scopeOrdered($q): order by pinned_until desc nulls last, priority desc, starts_at desc

Model AnnouncementTarget:
- belongsTo announcement()

Model AnnouncementReceipt:
- belongsTo announcement()
- belongsTo user()
- belongsTo store()

## 6) Eligibility and internal logic (core rules)

R1) Status + schedule
- Eligible if:
  - starts_at is null OR starts_at <= now
  - expires_at is null OR expires_at > now
  - status not in (archived, draft)
- publish endpoint:
  - if starts_at in future => status=scheduled
  - else => status=active
- expired can be derived when expires_at < now (optional job to update).

R2) Targeting by scope
- scope=global: all users (no targets required).
- scope=store: users in stores listed in announcement_targets.
- scope=user: users listed in announcement_targets.
- scope=role: users with store_users.role in announcement_targets.
  - If store_id is provided, filter role in that store only.

R3) Display rules
- display_mode=banner: show in carousel only.
- display_mode=modal: show as modal only (important message).
- display_mode=both: show in both.
- type=advertencia should default severity=danger and display_mode=modal or both.

R4) Periodicity
- If require_ack=true and acknowledged_at is null:
  - show when last_shown_at is null OR repeat_every_minutes is null OR
    now - last_shown_at >= repeat_every_minutes.
- If require_ack=false:
  - show while eligible unless dismissed_at is set or snooze_until > now.

R5) Receipt lifecycle
- On GET /me/announcements/active:
  - If receipt does not exist, create with delivered_at=now.
  - If item is shown now, update last_shown_at=now and increment show_count.
  - Do NOT set seen_at here.
- POST /announcements/{id}/seen:
  - create receipt if missing
  - set seen_at=now if null
- POST /announcements/{id}/ack:
  - set acknowledged_at=now (even if require_ack=false; optional)
  - after ack, it should not appear as pending
- POST /announcements/{id}/dismiss (optional):
  - set dismissed_at=now (only for require_ack=false)

R6) RBAC (multi-store)
- Admin/super_admin: can create global/store/user/role.
- Gerente: only for stores they manage; cannot create global.
- Regular user: read/seen/ack only for eligible announcements.
- Policies enforce create/update/delete/publish/archive + user eligibility.

R7) Performance
- Use EXISTS joins for targets.
- Eager load receipts for current user only.
- Avoid N+1.
- Do not pre-create receipts in bulk.
- Consider caching active announcement ids per store for short TTL (optional).

## 7) API endpoints (Laravel 12, /api/v1)
All responses use ApiResponse trait.

### Home/Dashboard
1) GET /me/announcements/active?store_id={storeId}
Return:
- critical: severity=danger + require_ack=true + unacknowledged (created_at desc)
- banners: other eligible announcements (exclude critical to avoid duplicates)
Each item:
  id, title, excerpt, type, severity, display_mode, require_ack,
  icon, image_url, cta_label, cta_url,
  starts_at, expires_at,
  receipt: seen_at, acknowledged_at, dismissed_at, last_shown_at, show_count

2) GET /announcements/{id}
- return full message + metadata + current user receipt

3) POST /announcements/{id}/seen
Body: { store_id?: number }
- marks seen_at

4) POST /announcements/{id}/ack
Body: { store_id?: number }
- marks acknowledged_at

5) POST /announcements/{id}/dismiss (optional)
Body: { store_id?: number }
- marks dismissed_at (only for require_ack=false)

### Central listing (user history)
6) GET /me/announcements
Query:
- status=active|expired|all
- only_unacknowledged=1
- only_unseen=1
- severity=info|warning|danger
- type=recado|advertencia
- scope=global|store|user|role
- store_id=XX
- per_page, page
- sort=starts_at_desc|created_at_desc|severity_desc|priority_desc

### Admin/Manager CRUD
7) POST /announcements
Body:
{
  "title": "...",
  "message": "...",
  "excerpt": "...",
  "type": "recado|advertencia",
  "severity": "info|warning|danger",
  "display_mode": "banner|modal|both",
  "icon": "megaphone",
  "image_url": "https://...",
  "cta_label": "Ler agora",
  "cta_url": "https://...",
  "scope": "global|store|user|role",
  "require_ack": true,
  "starts_at": "YYYY-MM-DD HH:MM:SS",
  "expires_at": "YYYY-MM-DD HH:MM:SS",
  "repeat_every_minutes": 240,
  "priority": 10,
  "pinned_until": "YYYY-MM-DD HH:MM:SS",
  "targets": [
    { "target_type": "store", "target_id": "3" }
  ]
}

Rules:
- if scope != global, targets required
- only admin can set scope=global
- if type=advertencia, severity must be danger (auto-adjust or validate)
- if display_mode=modal, require_ack should default true
- repeat_every_minutes only allowed when require_ack=true

8) PUT /announcements/{id}
- editable only when draft/scheduled (or allow active with audit)

9) POST /announcements/{id}/publish
- status -> active or scheduled if starts_at in future
- set published_at + published_by_user_id

10) POST /announcements/{id}/archive
- status -> archived
- set archived_at + archived_by_user_id

11) GET /announcements (admin list)
- filters: status, severity, type, scope, created_by, date range, store_id, role

### Optional media endpoints (if you support upload)
12) POST /announcements/{id}/image (multipart/form-data)
- upload banner image, store URL in image_url

## 8) FormRequests
- CreateAnnouncementRequest (fields + conditional rules)
- UpdateAnnouncementRequest (similar + status rules)
- SeenAnnouncementRequest (store_id optional, validate membership)
- AckAnnouncementRequest (store_id optional, validate membership)
- DismissAnnouncementRequest (store_id optional, require_ack=false)

## 9) Policies/Gates
AnnouncementPolicy:
- view (eligibility)
- create (role + store scope; only admin for global)
- update/delete (admin or creator + store scope)
- publish/archive (admin or gerente in store scope)
- markSeen/ack/dismiss (eligible user only)

## 10) Service layer
AnnouncementEligibilityService:
- getActiveForUser(User $user, ?int $storeId): array {critical, banners}
- isEligible(User $user, Announcement $a, ?int $storeId): bool
- shouldShowNow(AnnouncementReceipt $r, Announcement $a, Carbon $now): bool
- touchReceiptOnShown(User $user, Announcement $a, ?int $storeId): AnnouncementReceipt
- applyDisplayRules(Collection $items): array {critical, banners}

## 11) Maintainability tips
- Use PHP enums or model constants for type/severity/scope/display_mode/status.
- Use API Resources for consistent output (AnnouncementSummaryResource, AnnouncementDetailResource).
- Keep query builders in a single place (AnnouncementQuery).
- Add unit tests for eligibility + ack + periodicity.

## 12) Deliverables
- Migrations for announcements, announcement_targets, announcement_receipts (+ optional assets)
- Models + relationships + scopes
- Policies/Gates
- Controllers (Api/V1)
- Requests (FormRequest)
- Service class
- Routes in `routes/api_v1.php`
- Short README or docs with curl examples for endpoints
