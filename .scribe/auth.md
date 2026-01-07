# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer {SEU_TOKEN}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

    Para obter um token, faça login em `POST /api/v1/auth/login` com email e senha.

    **Usuários de teste:**
    - `admin@maiscapinhas.com.br` / `password` (Admin - todas as lojas)
    - `joao.vendedor@maiscapinhas.com.br` / `password` (Vendedor - Tijucas)

    O token deve ser enviado no header `Authorization: Bearer {token}`.

    **Modo SPA (Cookie):** Para aplicações frontend, use `withCredentials: true` após obter o CSRF token em `/sanctum/csrf-cookie`.
