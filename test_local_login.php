<?php
/**
 * Teste local de login com Bootstrap do Laravel
 */

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Http\Kernel');

// Criar request de login
$request = Illuminate\Http\Request::create(
    '/api/v1/auth/login',
    'POST',
    [],
    [],
    [],
    [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ],
    json_encode([
        'email' => 'admin@maiscapinhas.com.br',
        'password' => 'password'
    ])
);

echo "=== Teste Local de Login ===\n\n";

try {
    $response = $kernel->handle($request);

    $statusCode = $response->getStatusCode();
    $content = $response->getContent();
    $decoded = json_decode($content, true);

    echo "Status Code: {$statusCode}\n\n";

    if ($statusCode === 200) {
        echo "✅ LOGIN SUCESSO!\n\n";
        if (isset($decoded['data']['user'])) {
            echo "Usuário: {$decoded['data']['user']['name']}\n";
            echo "Email: {$decoded['data']['user']['email']}\n";
        }
        if (isset($decoded['data']['token'])) {
            echo "Token: " . substr($decoded['data']['token'], 0, 50) . "...\n";
        }
    } else {
        echo "❌ Erro: \n";
        echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }

} catch (Throwable $e) {
    echo "❌ EXCEÇÃO:\n";
    echo "Mensagem: " . $e->getMessage() . "\n";
    echo "Arquivo: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n";
