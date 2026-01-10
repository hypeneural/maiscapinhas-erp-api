<?php
/**
 * Script para criar o symlink do storage do Laravel
 * 
 * INSTRUÇÕES:
 * 1. Faça upload deste arquivo para a pasta 'public' do servidor
 * 2. Acesse: https://api.maiscapinhas.com.br/create-storage-link.php
 * 3. Após executar com sucesso, DELETE este arquivo por segurança!
 */

// Caminhos
$target = dirname(__DIR__) . '/storage/app/public';
$link = __DIR__ . '/storage';

echo "<h1>Criador de Symlink - Laravel Storage</h1>";
echo "<hr>";

// Verifica se o diretório target existe
if (!is_dir($target)) {
    echo "<p style='color: red;'>❌ ERRO: Diretório de origem não existe: <code>{$target}</code></p>";
    echo "<p>Verifique se o caminho está correto.</p>";
    exit;
}

echo "<p>📁 Diretório de origem: <code>{$target}</code> ✅</p>";

// Verifica se já existe um symlink ou pasta
if (file_exists($link)) {
    if (is_link($link)) {
        $currentTarget = readlink($link);
        echo "<p style='color: orange;'>⚠️ Já existe um symlink em: <code>{$link}</code></p>";
        echo "<p>Aponta para: <code>{$currentTarget}</code></p>";
        
        // Verifica se aponta para o lugar certo
        if (realpath($currentTarget) === realpath($target)) {
            echo "<p style='color: green;'>✅ O symlink já está configurado corretamente!</p>";
            echo "<p>O problema pode ser de permissões. Verifique as permissões da pasta storage.</p>";
        } else {
            echo "<p style='color: red;'>❌ O symlink aponta para o lugar errado!</p>";
            echo "<p>Removendo e recriando...</p>";
            
            if (unlink($link)) {
                if (symlink($target, $link)) {
                    echo "<p style='color: green;'>✅ Symlink recriado com sucesso!</p>";
                } else {
                    echo "<p style='color: red;'>❌ Falha ao criar symlink. Tente o método alternativo abaixo.</p>";
                }
            }
        }
    } else {
        echo "<p style='color: red;'>❌ Já existe uma pasta (não é symlink) em: <code>{$link}</code></p>";
        echo "<p>Você precisa remover essa pasta manualmente antes de criar o symlink.</p>";
    }
} else {
    // Tenta criar o symlink
    echo "<p>🔗 Tentando criar symlink...</p>";
    
    if (@symlink($target, $link)) {
        echo "<p style='color: green;'>✅ SUCESSO! Symlink criado!</p>";
        echo "<p><code>{$link}</code> → <code>{$target}</code></p>";
    } else {
        echo "<p style='color: red;'>❌ Falha ao criar symlink via symlink()</p>";
        echo "<p>Tentando método alternativo com shell_exec...</p>";
        
        // Tenta via shell command (Linux)
        $command = "ln -s " . escapeshellarg($target) . " " . escapeshellarg($link);
        $result = @shell_exec($command . " 2>&1");
        
        if (is_link($link)) {
            echo "<p style='color: green;'>✅ SUCESSO via shell_exec!</p>";
        } else {
            echo "<p style='color: red;'>❌ Também falhou via shell_exec.</p>";
            echo "<p>Resultado: <code>{$result}</code></p>";
            
            echo "<h2>Soluções alternativas:</h2>";
            echo "<ol>";
            echo "<li>Peça ao suporte da hospedagem para criar o symlink</li>";
            echo "<li>Ou mude a estratégia para salvar arquivos em <code>public/uploads</code> ao invés de storage</li>";
            echo "</ol>";
        }
    }
}

// Verifica permissões
echo "<hr>";
echo "<h2>Verificação de Permissões</h2>";

$storagePath = dirname(__DIR__) . '/storage';
$storagePerms = substr(sprintf('%o', fileperms($storagePath)), -4);
echo "<p>Permissões de <code>storage/</code>: {$storagePerms}</p>";

$appPublicPath = $target;
if (is_dir($appPublicPath)) {
    $appPublicPerms = substr(sprintf('%o', fileperms($appPublicPath)), -4);
    echo "<p>Permissões de <code>storage/app/public/</code>: {$appPublicPerms}</p>";
    
    if ($appPublicPerms !== '0775' && $appPublicPerms !== '0755') {
        echo "<p style='color: orange;'>⚠️ Considere alterar para 755 ou 775</p>";
    }
}

echo "<hr>";
echo "<p style='color: red; font-weight: bold;'>⚠️ IMPORTANTE: Delete este arquivo após usar!</p>";
echo "<p>Por segurança, remova <code>create-storage-link.php</code> do servidor.</p>";

// Lista arquivos no storage/app/public para debug
echo "<hr>";
echo "<h2>Arquivos em storage/app/public</h2>";
if (is_dir($target)) {
    $files = scandir($target);
    echo "<ul>";
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $fullPath = $target . '/' . $file;
            $type = is_dir($fullPath) ? '📁' : '📄';
            echo "<li>{$type} {$file}</li>";
            
            // Se for diretório, lista os arquivos dentro
            if (is_dir($fullPath)) {
                $subFiles = scandir($fullPath);
                echo "<ul>";
                foreach ($subFiles as $subFile) {
                    if ($subFile !== '.' && $subFile !== '..') {
                        $subFullPath = $fullPath . '/' . $subFile;
                        $subType = is_dir($subFullPath) ? '📁' : '📄';
                        echo "<li>{$subType} {$subFile}</li>";
                    }
                }
                echo "</ul>";
            }
        }
    }
    echo "</ul>";
} else {
    echo "<p>Diretório não encontrado</p>";
}
