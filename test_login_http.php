<?php
// Teste HTTP de login com credenciais inválidas
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

// Simular requisição HTTP
$request = Illuminate\Http\Request::create('/api/v1/auth/login', 'POST', [
    'email' => 'usuario_invalido_' . time() . '@teste.com',
    'password' => 'senha_errada_123'
]);

$request->headers->set('Accept', 'application/json');
$request->headers->set('Content-Type', 'application/json');

echo "=== TESTE LOGIN COM CREDENCIAIS INVALIDAS ===\n\n";
echo "Email: " . $request->input('email') . "\n";
echo "Password: " . $request->input('password') . "\n\n";

try {
    $response = $kernel->handle($request);

    echo "Status Code: " . $response->getStatusCode() . "\n";
    echo "Response: " . substr($response->getContent(), 0, 500) . "\n\n";

    // Verificar se o log foi criado
    $lastLog = App\Models\AuditLog::where('event', 'auth.login_failed')
        ->latest('id')
        ->first();

    if ($lastLog) {
        echo "=== REGISTRO NO AUDIT LOG ===\n";
        echo "ID: " . $lastLog->id . "\n";
        echo "Event: " . $lastLog->event . "\n";
        echo "Action: " . $lastLog->action . "\n";
        echo "Entity Type: " . ($lastLog->entity_type ?? 'NULL') . "\n";
        echo "Entity ID: " . ($lastLog->entity_id ?? 'NULL') . "\n";
        echo "IP: " . $lastLog->ip . "\n";
        echo "Created At: " . $lastLog->created_at . "\n";
        echo "\nSUCESSO! O log foi registrado corretamente.\n";
    } else {
        echo "ERRO: Log nao encontrado!\n";
    }

} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}

$kernel->terminate($request, $response ?? null);
