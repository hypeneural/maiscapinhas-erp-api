<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\StoreUserRole;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

/**
 * @group Upload de Arquivos
 *
 * Endpoints para upload de imagens de avatar e fotos.
 */
class AvatarController extends Controller
{
    use ApiResponse;

    public function __construct(
        private AuditLogger $auditLogger
    ) {
    }

    /**
     * Atualizar avatar do usuário
     *
     * Permite ao usuário atualizar seu próprio avatar ou admins atualizarem
     * o avatar de qualquer usuário.
     *
     * **Quem pode usar:**
     * - O próprio usuário (atualizar seu avatar)
     * - Admins (atualizar avatar de qualquer pessoa)
     *
     * **Validações:**
     * - Tipos: jpg, jpeg, png, webp
     * - Tamanho máximo: 2MB
     * - Dimensões mínimas: 200x200px
     *
     * @urlParam user integer required ID do usuário. Example: 5
     * @bodyParam avatar file required Arquivo de imagem. Example: avatar.jpg
     * @bodyParam remove boolean Remover avatar atual. Example: false
     *
     * @response 200 scenario="Avatar atualizado" {
     *   "data": {
     *     "user_id": 5,
     *     "avatar_url": "https://api.maiscapinhas.com.br/storage/users/5/avatar.jpg"
     *   },
     *   "meta": { "request_id": "uuid", "timestamp": "2026-01-07T12:00:00Z" }
     * }
     *
     * @response 403 scenario="Sem permissão" {
     *   "error": { "code": 403, "message": "Você não tem permissão para atualizar este avatar." }
     * }
     */
    public function updateAvatar(Request $request, User $user): JsonResponse
    {
        $authUser = $request->user();

        // Authorization: own user, admin, or super admin
        $isOwnUser = $authUser->id === $user->id;
        $isSuperAdmin = $authUser->isSuperAdmin();
        $isAdmin = $authUser->storeUsers()
            ->where('role', StoreUserRole::ADMIN->value)
            ->exists();

        if (!$isOwnUser && !$isAdmin && !$isSuperAdmin) {
            return $this->forbidden('Você não tem permissão para atualizar este avatar.');
        }

        // Handle remove
        if ($request->boolean('remove')) {
            if ($user->avatar_url) {
                // Delete old file
                $oldPath = $this->extractPathFromUrl($user->avatar_url);
                if ($oldPath) {
                    Storage::disk('public')->delete($oldPath);
                }

                $user->update(['avatar_url' => null]);

                $this->auditLogger->log('user.avatar_removed', $user);
            }

            return $this->success([
                'user_id' => $user->id,
                'avatar_url' => null,
            ]);
        }

        // Validate file
        $request->validate([
            'avatar' => [
                'required',
                File::image()
                    ->types(['jpg', 'jpeg', 'png', 'webp'])
                    ->max(2 * 1024), // 2MB
                'dimensions:min_width=200,min_height=200',
            ],
        ]);

        // Delete old avatar if exists
        if ($user->avatar_url) {
            $oldPath = $this->extractPathFromUrl($user->avatar_url);
            if ($oldPath) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        // Store new avatar
        $file = $request->file('avatar');
        $extension = $file->getClientOriginalExtension();
        $filename = 'avatar.' . $extension;
        $path = $file->storeAs("users/{$user->id}", $filename, 'public');

        $avatarUrl = Storage::disk('public')->url($path);

        $user->update(['avatar_url' => $avatarUrl]);

        $this->auditLogger->log('user.avatar_updated', $user, [
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ]);

        return $this->success([
            'user_id' => $user->id,
            'avatar_url' => $avatarUrl,
        ]);
    }

    /**
     * Extract storage path from full URL.
     */
    private function extractPathFromUrl(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        // Try to extract path from storage URL
        $storagePath = parse_url($url, PHP_URL_PATH);
        if ($storagePath && str_contains($storagePath, '/storage/')) {
            return str_replace('/storage/', '', $storagePath);
        }

        return null;
    }
}
