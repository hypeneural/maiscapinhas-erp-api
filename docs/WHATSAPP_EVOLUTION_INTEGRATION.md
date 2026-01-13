# WhatsApp Evolution - CRUD de Instancias e Integracao

Este documento descreve um desenho completo para CRUD de instancias da Evolution API (WhatsApp),
alinhado com a stack atual do projeto e pronto para evoluir com fila/throttle sem quebrar o front.

Objetivos principais:
- CRUD de instancias somente para super admin.
- Instancia global favorita para notificacoes e opcao de instancia por loja ou usuario.
- Secrets armazenados no banco e criptografados em repouso.
- Respostas nunca retornam secrets (somente flags/masked).
- Servico/Client dedicado para Evolution por instancia.
- Base preparada para fila, throttle e auditoria.

Cada instancia representa um numero de WhatsApp que precisa estar conectado.

---

## 1) Stack atual e padroes do projeto

Alinhamentos relevantes com o codebase:
- Laravel 12 + Sanctum (`auth:sanctum`).
- RBAC via `User::isSuperAdmin()` (boolean `is_super_admin` no model).
- `ApiResponse` trait para respostas padrao com `data` e `meta`.
- `StoreContext` para validar acesso por loja (quando `store_id` for usado).
- Scribe para documentacao (docblocks nos controllers).

Referencia interna: `app/Http/Traits/ApiResponse.php` e `app/Models/User.php`.

---

## 2) Modelagem de dados

### Escopo de instancia (global, loja, usuario)

- Global: `store_id = null` e `user_id = null`.
- Loja: `store_id` preenchido e `user_id = null`.
- Usuario: `user_id` preenchido e `store_id = null`.
- `is_default = true` marca a instancia favorita no seu escopo.
- Regra: permitir somente 1 favorita por escopo (global, por loja, por usuario).
- Resolucao recomendada no envio: `user` -> `store` -> `global default`.

### Migration: `whatsapp_instances`

```php
Schema::create('whatsapp_instances', function (Blueprint $table) {
    $table->id();

    $table->unsignedBigInteger('store_id')->nullable()->index(); // multi-loja (opcional)
    $table->unsignedBigInteger('user_id')->nullable()->index();  // opcional por usuario
    $table->string('provider')->default('evolution');            // futuro: z-api etc

    $table->string('name');                  // instance_name (ex: loja_01)
    $table->string('phone_e164')->nullable();// numero conectado (opcional)
    $table->string('base_url');              // URL do servidor Evolution

    $table->boolean('is_default')->default(false)->index(); // favorita no escopo
    $table->boolean('is_active')->default(true)->index();
    $table->text('notes')->nullable();

    // Secrets (no banco, criptografados em repouso)
    $table->text('api_key')->nullable();     // apikey da Evolution
    $table->string('api_key_last4', 4)->nullable();
    $table->string('api_key_fingerprint', 16)->nullable();
    $table->text('token')->nullable();       // token por instancia (se usar)
    $table->string('token_last4', 4)->nullable();
    $table->string('token_fingerprint', 16)->nullable();

    $table->enum('status', ['unknown','connected','disconnected','connecting'])
        ->default('unknown');
    $table->json('last_state')->nullable();
    $table->timestamp('last_state_checked_at')->nullable();

    // Webhook (opcional)
    $table->string('webhook_secret')->nullable();
    $table->string('webhook_url')->nullable();
    $table->json('webhook_events')->nullable();

    $table->timestamps();
    $table->softDeletes();

    $table->unique(['provider', 'base_url', 'name', 'deleted_at']);
    $table->index(['store_id', 'is_default']);
    $table->index(['user_id', 'is_default']);
});
```

Notas:
- `store_id` permite multi-loja; `null` indica instancia global.
- `user_id` permite instancia vinculada a usuario; `store_id` e `user_id` nao devem coexistir.
- `provider` prepara o terreno para multiplos provedores.
- `name` nao deve ser unico isoladamente; use unique composto com `provider`/`base_url`.
- `api_key`/`token` devem ser criptografados via cast `encrypted`.
- Soft delete + unique: use unique com `deleted_at` e valide no app (MySQL/MariaDB permite multiplos NULL).
- Opcao alternativa: criar tabela `whatsapp_servers` e usar `server_id + name` como unique.

---

## 3) Model: `WhatsAppInstance`

```php
class WhatsAppInstance extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'store_id','user_id','provider','name','phone_e164','base_url',
        'is_default','is_active','notes',
        'api_key','api_key_last4','api_key_fingerprint',
        'token','token_last4','token_fingerprint',
        'status','last_state','last_state_checked_at',
        'webhook_secret','webhook_url','webhook_events',
    ];

    protected $casts = [
        'api_key'    => 'encrypted',
        'token'      => 'encrypted',
        'last_state' => 'array',
        'webhook_events' => 'array',
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
        'last_state_checked_at' => 'datetime',
    ];

    protected $hidden = [
        'api_key',
        'token',
        'webhook_secret',
    ];

    // flags para UI sem expor secrets
    protected $appends = ['has_api_key','has_token','api_key_masked','token_masked','scope'];

    public function getHasApiKeyAttribute(): bool
    {
        return !empty($this->api_key_last4) || !empty($this->api_key_fingerprint);
    }

    public function getHasTokenAttribute(): bool
    {
        return !empty($this->token_last4) || !empty($this->token_fingerprint);
    }

    public function getApiKeyMaskedAttribute(): ?string
    {
        return $this->api_key_last4 ? str_repeat('*', 8) . $this->api_key_last4 : null;
    }

    public function getTokenMaskedAttribute(): ?string
    {
        return $this->token_last4 ? str_repeat('*', 8) . $this->token_last4 : null;
    }

    public function getScopeAttribute(): string
    {
        if (!empty($this->user_id)) {
            return 'user';
        }
        if (!empty($this->store_id)) {
            return 'store';
        }
        return 'global';
    }
}
```

Notas:
- `hidden` garante que secrets nao vazem em responses.
- `appends` permite mostrar flags e masked values no front.
- `*_last4` e `*_fingerprint` evitam desencriptar para listar instancias.

---

## 4) RBAC (somente super admin no CRUD)

Padrao recomendado: middleware exclusivo para super admin (ou checagem direta no controller).

Exemplo de middleware:
```php
class EnsureIsSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()?->isSuperAdmin()) {
            return response()->json(['message' => 'Apenas super admin.'], 403);
        }
        return $next($request);
    }
}
```

Uso nas rotas de admin:
```php
Route::middleware(['auth:sanctum', 'super-admin'])
    ->prefix('api/v1/admin/whatsapp')
    ->group(function () {
        Route::apiResource('instances', WhatsAppInstanceController::class);
        Route::post('instances/{instance}/set-default', [WhatsAppInstanceController::class, 'setDefault']);
        Route::delete('instances/{instance}/secrets/api-key', [WhatsAppInstanceController::class, 'clearApiKey']);
        Route::delete('instances/{instance}/secrets/token', [WhatsAppInstanceController::class, 'clearToken']);
        Route::get('instances/{instance}/state', [WhatsAppInstanceController::class, 'state']);
        Route::get('instances/{instance}/connect', [WhatsAppInstanceController::class, 'connect']);
        Route::post('instances/{instance}/test', [WhatsAppInstanceController::class, 'test']);
    });
```

Observacao:
- O projeto ja usa `is_super_admin` (boolean) no `User`.
- Se preferir, use metodo `authorizeSuperAdmin()` no controller (padrao similar ao Admin/UserController).

---

## 5) Rotas (routes/api_v1.php)

### Admin (CRUD + state/connect/test)
Dentro do grupo `auth:sanctum`:
```php
Route::prefix('admin/whatsapp')->name('admin.whatsapp.')->group(function () {
    Route::apiResource('instances', WhatsAppInstanceController::class);
    Route::post('instances/{instance}/set-default', [WhatsAppInstanceController::class, 'setDefault']);
    Route::delete('instances/{instance}/secrets/api-key', [WhatsAppInstanceController::class, 'clearApiKey']);
    Route::delete('instances/{instance}/secrets/token', [WhatsAppInstanceController::class, 'clearToken']);
    Route::get('instances/{instance}/state', [WhatsAppInstanceController::class, 'state']);
    Route::get('instances/{instance}/connect', [WhatsAppInstanceController::class, 'connect']);
    Route::post('instances/{instance}/test', [WhatsAppInstanceController::class, 'test']);
});
```

### Mensagens (uso interno e front)
```php
Route::prefix('whatsapp')->name('whatsapp.')->group(function () {
    Route::post('instances/{instance}/messages/text', [WhatsAppMessageController::class, 'sendText']);
    Route::post('instances/{instance}/messages/media', [WhatsAppMessageController::class, 'sendMedia']);
    Route::post('instances/{instance}/numbers/check', [WhatsAppMessageController::class, 'checkNumbers']);
});
```

Recomendacao de acesso:
- Para mensagens: validar acesso a loja quando `store_id` existir (usar `StoreContext`).
- Super admin bypassa.
- Resolucao de instancia para envio: `user` -> `store` -> `global default` (favorita).

---

## 6) Requests e validacao

### Store/Update Instance
```php
public function rules(): array
{
    return [
        'name'       => ['required','string','max:60','regex:/^[a-zA-Z0-9_\\-]+$/',
            Rule::unique('whatsapp_instances')->where(fn($q) => $q
                ->where('provider', $this->input('provider', 'evolution'))
                ->where('base_url', rtrim($this->input('base_url', ''), '/'))
                ->whereNull('deleted_at')
            )->ignore($this->instance)],
        'provider'  => ['sometimes','string','max:50'],
        'base_url'  => ['required','url','max:255'],
        'phone_e164' => ['nullable','string','max:20','regex:/^\\d+$/'],
        'api_key'    => ['nullable','string','max:500'],
        'token'      => ['nullable','string','max:500'],
        'store_id'   => ['nullable','integer','exists:stores,id','prohibited_with:user_id'],
        'user_id'    => ['nullable','integer','exists:users,id','prohibited_with:store_id'],
        'is_default' => ['sometimes','boolean'],
        'is_active'  => ['sometimes','boolean'],
        'notes'      => ['nullable','string','max:1000'],
        'webhook_secret' => ['nullable','string','max:255'],
        'webhook_url'    => ['nullable','url','max:255'],
        'webhook_events' => ['nullable','array'],
        'status'     => ['nullable', Rule::in(['unknown','connected','disconnected','connecting'])],
    ];
}
```

Regras de update de secrets:
- So sobrescrever `api_key`/`token` se vierem no payload e nao forem string vazia.
- Para limpar, usar endpoints explicitos: `DELETE /instances/{id}/secrets/api-key` e `DELETE /instances/{id}/secrets/token`.

Regras de escopo:
- `store_id` e `user_id` sao mutuamente exclusivos.
- Se `is_default = true`, desmarcar outros defaults no mesmo escopo.

### SendTextRequest
```php
public function rules(): array
{
    return [
        'number' => ['required','string','regex:/^\\d+$/','max:20'],
        'text'   => ['required','string','max:4000'],
    ];
}
```

### SendMediaRequest
```php
public function rules(): array
{
    return [
        'number'    => ['required','string','regex:/^\\d+$/','max:20'],
        'mediatype' => ['required','string','in:image,video,document,audio'],
        'mimetype'  => ['required','string','max:100'],
        'media'     => ['required','url','max:2048'],
        'fileName'  => ['nullable','string','max:255'],
        'caption'   => ['nullable','string','max:1000'],
    ];
}
```

### CheckNumbersRequest
```php
public function rules(): array
{
    return [
        'numbers'   => ['required','array','min:1','max:200'],
        'numbers.*' => ['string','regex:/^\\d+$/','max:20'],
    ];
}
```

---

## 7) Resource (resposta padrao)

```php
class WhatsAppInstanceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'user_id' => $this->user_id,
            'scope' => $this->scope,
            'provider' => $this->provider,
            'name' => $this->name,
            'phone_e164' => $this->phone_e164,
            'base_url' => $this->base_url,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'status' => $this->status,
            'last_state' => $this->last_state,
            'last_state_checked_at' => $this->last_state_checked_at,
            'webhook_url' => $this->webhook_url,
            'webhook_events' => $this->webhook_events,
            'has_api_key' => $this->has_api_key,
            'has_token' => $this->has_token,
            'api_key_masked' => $this->api_key_masked,
            'token_masked' => $this->token_masked,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
```

---

## 8) Service/Client Evolution (por instancia)

### Client
```php
class EvolutionApiClient
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private string $instanceName,
    ) {}

    private function http()
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withHeaders(['apikey' => $this->apiKey])
            ->timeout(20)
            ->retry(2, 250, throw: false);
    }

    public function connectionState(): array
    {
        $res = $this->http()->get("/instance/connectionState/{$this->instanceName}");
        return ['ok' => $res->successful(), 'status' => $res->status(), 'data' => $res->json()];
    }

    public function connect(): array
    {
        $res = $this->http()->get("/instance/connect/{$this->instanceName}");
        return ['ok' => $res->successful(), 'status' => $res->status(), 'data' => $res->json()];
    }

    public function sendText(string $number, string $text): array
    {
        $res = $this->http()->post("/message/sendText/{$this->instanceName}", [
            'number' => $number,
            'text' => $text,
        ]);
        return ['ok' => $res->successful(), 'status' => $res->status(), 'data' => $res->json()];
    }

    public function sendMedia(array $payload): array
    {
        $res = $this->http()->post("/message/sendMedia/{$this->instanceName}", $payload);
        return ['ok' => $res->successful(), 'status' => $res->status(), 'data' => $res->json()];
    }

    public function checkWhatsAppNumbers(array $numbers): array
    {
        $res = $this->http()->post("/chat/whatsappNumbers/{$this->instanceName}", [
            'numbers' => $numbers,
        ]);
        return ['ok' => $res->successful(), 'status' => $res->status(), 'data' => $res->json()];
    }
}
```

### Factory
```php
class EvolutionClientFactory
{
    public function make(WhatsAppInstance $instance): EvolutionApiClient
    {
        if (!$instance->api_key) {
            throw new RuntimeException('Instancia sem API Key configurada.');
        }

        return new EvolutionApiClient(
            baseUrl: $instance->base_url,
            apiKey: $instance->api_key, // decrypted pelo cast
            instanceName: $instance->name,
        );
    }
}
```

---

## 9) Controllers

### Admin/WhatsAppInstanceController (CRUD + state/connect/test)
Responsavel por:
- CRUD de instancias (super admin only).
- `setDefault`: define favorita no escopo e desmarca as outras.
- `clearApiKey` / `clearToken`: limpar secrets sem risco de apagar sem querer.
- `state`: chama Evolution, atualiza `last_state` e `status`.
- `connect`: retorna `pairingCode`/`code` para renderizar QR no front.
- `test`: checagem rapida (ex: `connectionState`).

Regras importantes:
- Nunca retornar `api_key`/`token`.
- Em `update`, so sobrescrever secret se novo valor foi enviado e nao for vazio.
- Atualizar `api_key_last4`/`api_key_fingerprint` e `token_last4`/`token_fingerprint` quando secret mudar.
- Atualizar `last_state_checked_at` em `state`.
- Responder `connect` com headers no-cache e formato padrao.
- Usar `ApiResponse` para respostas padrao.

### WhatsAppMessageController
Responsavel por:
- `sendText`, `sendMedia`, `checkNumbers`.
- Validar acesso a loja se `store_id` estiver setado.
- Usar `EvolutionClientFactory` para montar client.
- Bloquear envio se `is_active = false`.
- Resolver instancia por escopo quando nao for passado `instance_id`.
- Notificacoes gerais do sistema usam a instancia global `is_default = true`.

---

## 10) Mapeamento de status

Resposta tipica da Evolution:
```json
{ "instance": { "instanceName": "loja_01", "state": "open" } }
```

Mapeamento sugerido:
- `open` -> `connected`
- `close` -> `disconnected`
- `connecting` -> `connecting`
- qualquer outro -> `unknown`

Salvar `last_state` com o payload bruto para diagnostico.

---

## 11) Responses (padrao do projeto)

O `ApiResponse` gera:
```json
{
  "data": { ... },
  "meta": { "request_id": "...", "timestamp": "..." }
}
```

Exemplo de `GET /api/v1/admin/whatsapp/instances`:
```json
{
  "data": [
    {
      "id": 1,
      "store_id": 1,
      "user_id": null,
      "scope": "store",
      "provider": "evolution",
      "name": "loja_01",
      "phone_e164": "559999999999",
      "base_url": "https://evolution.example.com",
      "is_default": false,
      "is_active": true,
      "status": "connected",
      "last_state": { "instance": { "state": "open" } },
      "last_state_checked_at": "2026-01-13T12:00:00Z",
      "has_api_key": true,
      "has_token": false,
      "api_key_masked": "********1234",
      "token_masked": null
    }
  ],
  "meta": { "request_id": "...", "timestamp": "..." }
}
```

### Response de connect (QR)

Resposta recomendada:
```json
{
  "data": {
    "type": "qr_text",
    "code": "2@y8eK+bjtEjUWy9/FOM...",
    "pairingCode": "WZYEH1YY",
    "expires_in": 60
  },
  "meta": { "request_id": "...", "timestamp": "..." }
}
```

Headers no Laravel:
- `Cache-Control: no-store, no-cache, must-revalidate`
- `Pragma: no-cache`

### Mapeamento de erros (HTTP)

- Provider fora do ar -> `502` com `message` e `details` do provider.
- Timeout -> `504`.
- Instancia sem api_key -> `422` (ou `409` se preferir conflito).
- Sem permissao -> `403`.
- Recurso nao encontrado -> `404`.
- Validacao -> `422` com `errors`.

Evite respostas `200` com `ok:false` para falhas.

---

## 12) Fluxo de telas (front admin)

1) Lista de instancias
- Mostrar `scope`, `status`, `is_default`, `is_active`, `has_api_key`, `has_token`.
- `api_key`/`token` nunca aparecem, apenas masked.

2) Criar instancia
- Informar `name`, `base_url`, `api_key` (e `token` se usar).
- Definir escopo: global (default), por loja (`store_id`) ou por usuario (`user_id`).
- Opcional `is_default` e `is_active`.

3) Editar instancia
- Inputs de secret com placeholder "********".
- So atualizar se admin digitar novo valor.
- Para limpar secret, usar acao explicita (DELETE secret).
- Permitir marcar como favorita no escopo.

4) Conectar (QR)
- Chamar `GET /instances/{id}/state`.
- Se nao estiver `open`, chamar `GET /instances/{id}/connect`.
- Renderizar QR com o campo `code` (texto) via lib de QR no front.
- Fazer polling em `state` a cada 3-5s ate `open`.

---

## 13) Seguranca e boas praticas

- Usar cast `encrypted` para `api_key` e `token` (criptografia em repouso).
- Nunca retornar secrets nas responses.
- Usar `*_last4`/`*_fingerprint` para masked sem desencriptar.
- Nao logar request body de endpoints admin.
- Proteger `APP_KEY` (se perder, nao consegue desencriptar).
- Auditar alteracoes criticas (ActivityLog ou AuditContext).

### Normalizacao de dados

- `base_url`: salvar sem barra no final (`rtrim('/', ...)`).
- `name`: `trim` e padrao consistente (ex: lowercase).
- `phone_e164` e `number`: somente digitos com DDI (ex: 55...).

---

## 14) Evolucao pronta para fila/throttle (opcional)

Tabela sugerida: `whatsapp_outbox_messages`
```php
Schema::create('whatsapp_outbox_messages', function (Blueprint $table) {
    $table->id();
    $table->foreignId('instance_id')->constrained('whatsapp_instances');
    $table->string('to_number');
    $table->enum('type', ['text','media']);
    $table->json('payload');
    $table->enum('status', ['queued','sent','failed'])->default('queued');
    $table->string('provider_message_id')->nullable();
    $table->json('raw_response')->nullable();
    $table->text('error_message')->nullable();
    $table->timestamps();
});
```

Fluxo futuro (sem mudar o front):
1. Endpoint salva mensagem em outbox (status queued).
2. Job envia via EvolutionApiClient.
3. Atualiza status + raw_response.
4. Middleware de throttle por instancia.

---

## 15) Checklist de implementacao

- Migration `whatsapp_instances`.
- Model `WhatsAppInstance` com cast `encrypted`, metadata `*_last4`/`*_fingerprint` e `hidden`.
- Resource `WhatsAppInstanceResource`.
- Requests de CRUD e mensagens.
- Controllers (admin + mensagens) usando `ApiResponse`.
- Endpoints de `set-default` e limpeza de secrets.
- Rotas em `routes/api_v1.php`.
- Service `EvolutionApiClient` + Factory.
- Tests: RBAC, encrypt/decrypt, responses sem secrets.
- Atualizar docs do Scribe.

---

## 16) Referencia rapida - Endpoints Evolution

Status:
```
GET /instance/connectionState/{instance}
Header: apikey: <API_KEY>
```

Connect (QR/Pairing):
```
GET /instance/connect/{instance}
Header: apikey: <API_KEY>
```

Mensagens:
```
POST /message/sendText/{instance}
POST /message/sendMedia/{instance}
POST /chat/whatsappNumbers/{instance}
```
