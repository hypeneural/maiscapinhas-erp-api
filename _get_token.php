<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Tentar pegar o usuário ID 1 ou qualquer admin
$user = User::find(1) ?? User::first();

if (!$user) {
    echo "Nenhum usuário encontrado.\n";
    exit(1);
}

echo "Gerando token para usuário: {$user->name} ({$user->email})\n";
$token = $user->createToken('verification-script')->plainTextToken;
echo "TOKEN: $token\n";
