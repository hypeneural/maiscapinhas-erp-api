<?php
/**
 * Script de teste para login na API de produção
 * Execute: php test_login_api.php
 */

$apiUrl = 'https://api.maiscapinhas.com.br/api/v1/auth/login';

$payload = [
    'email' => 'admin@maiscapinhas.com.br',
    'password' => 'password'
];

echo "=== Teste de Login API ===\n\n";
echo "URL: {$apiUrl}\n";
echo "Payload: " . json_encode($payload, JSON_PRETTY_PRINT) . "\n\n";

// Inicializar cURL
$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_SSL_VERIFYPEER => false, // Para ambiente de teste
    CURLOPT_TIMEOUT => 30,
]);

// Executar requisição
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
$effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

curl_close($ch);

// Exibir resultados
echo "=== Resultado ===\n\n";
echo "HTTP Code: {$httpCode}\n";
echo "URL Final: {$effectiveUrl}\n\n";

if ($error) {
    echo "ERRO cURL: {$error}\n";
} else {
    $decoded = json_decode($response, true);
    echo "Response:\n";
    echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    
    if ($httpCode === 200 && isset($decoded['data']['token'])) {
        echo "\n✅ LOGIN SUCESSO!\n";
        echo "Token: " . substr($decoded['data']['token'], 0, 50) . "...\n";
    } elseif ($httpCode === 422) {
        echo "\n⚠️ Erro de validação - verifique as credenciais\n";
    } elseif ($httpCode === 500) {
        echo "\n❌ Erro interno do servidor - verifique os logs\n";
        if (isset($decoded['message'])) {
            echo "Mensagem: {$decoded['message']}\n";
        }
        if (isset($decoded['file'])) {
            echo "Arquivo: {$decoded['file']}:{$decoded['line']}\n";
        }
    }
}

echo "\n";
