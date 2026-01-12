# Internal Communication API

Sistema de Comunicação Interna para o ERP Mais Capinhas.

## Endpoints

### Dashboard (User)
```
GET /api/v1/me/announcements/active?store_id={id}
```
Returns `{ critical: [...], banners: [...] }`.

### History (User)
```
GET /api/v1/me/announcements
```
Filters: `status`, `severity`, `type`, `only_unacknowledged`, `only_unseen`.

### Actions
```
POST /api/v1/announcements/{id}/seen
POST /api/v1/announcements/{id}/ack
POST /api/v1/announcements/{id}/dismiss
```

### CRUD (Admin/Gerente)
```
GET    /api/v1/announcements        # List
POST   /api/v1/announcements        # Create
GET    /api/v1/announcements/{id}   # Show
PUT    /api/v1/announcements/{id}   # Update
DELETE /api/v1/announcements/{id}   # Delete
POST   /api/v1/announcements/{id}/publish
POST   /api/v1/announcements/{id}/archive
```

## Create Example

```bash
curl -X POST http://localhost/api/v1/announcements \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Aviso Importante",
    "message": "Conteúdo completo aqui",
    "type": "advertencia",
    "severity": "danger",
    "scope": "store",
    "require_ack": true,
    "targets": [{ "target_type": "store", "target_id": "1" }]
  }'
```

## Scopes
- `global`: All users (admin only)
- `store`: Users in specific stores
- `user`: Specific users
- `role`: Users with specific roles (admin, gerente, conferente, vendedor)

## RBAC
- **Super Admin**: Full access
- **Admin**: Create global, manage all
- **Gerente**: Create store-scoped only
- **Vendedor/Conferente**: Read and acknowledge only
