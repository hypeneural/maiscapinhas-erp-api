<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Model: WheelPlayer
 * 
 * Representa uma PESSOA (cliente) que participa da roleta.
 * Identificado pelo WhatsApp (único).
 * Pode participar de múltiplas sessões/lojas.
 */
class WheelPlayer extends Model
{
    use HasFactory;

    protected $table = 'wheel_players';

    protected $fillable = [
        'player_key',
        'full_name',
        'whatsapp_e164',
        'whatsapp_lid',
        'whatsapp_confirmed_at',
        'phone_masked',
        'phone_hash',
        'phone_verified',
        // Endereço (ViaCEP)
        'cep',
        'street',
        'number',
        'complement',
        'neighborhood',
        'city',
        'state',
        'ibge',
        'ddd',
        'siafi',
        'viacep_raw',
        'viacep_synced_at',
        // Atividade
        'last_seen_at',
    ];

    protected $casts = [
        'phone_verified' => 'boolean',
        'whatsapp_confirmed_at' => 'datetime',
        'viacep_raw' => 'array',
        'viacep_synced_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    protected $hidden = [
        'whatsapp_e164',
        'phone_hash',
    ];

    // ========================================
    // Boot
    // ========================================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($player) {
            if (empty($player->player_key)) {
                $player->player_key = 'player_' . Str::random(12);
            }
            if (!empty($player->whatsapp_e164) && empty($player->phone_hash)) {
                $player->phone_hash = self::hashPhone($player->whatsapp_e164);
                $player->phone_masked = self::maskPhone($player->whatsapp_e164);
            }
        });
    }

    // ========================================
    // Relationships
    // ========================================

    /**
     * Participações em sessões (pivot).
     */
    public function sessionPlayers(): HasMany
    {
        return $this->hasMany(WheelSessionPlayer::class, 'player_id');
    }

    /**
     * Sessões em que participou.
     */
    public function sessions()
    {
        return $this->belongsToMany(WheelSession::class, 'wheel_session_players', 'player_id', 'session_id')
            ->withPivot('status', 'queue_position', 'joined_at', 'left_at')
            ->withTimestamps();
    }

    /**
     * Desafios de verificação de telefone.
     */
    public function phoneChallenges(): HasMany
    {
        return $this->hasMany(WheelPhoneChallenge::class, 'player_id');
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeByPhone($query, string $phone)
    {
        return $query->where('phone_hash', self::hashPhone($phone));
    }

    public function scopeByWhatsApp($query, string $phone)
    {
        return $query->where('whatsapp_e164', $phone);
    }

    public function scopeConfirmed($query)
    {
        return $query->whereNotNull('whatsapp_confirmed_at');
    }

    // ========================================
    // Phone Methods
    // ========================================

    /**
     * Mascara o telefone para exibição.
     */
    public static function maskPhone(string $phone): string
    {
        $clean = preg_replace('/[^0-9+]/', '', $phone);

        if (strlen($clean) < 10) {
            return '***-****';
        }

        $lastFour = substr($clean, -4);
        $ddd = strlen($clean) >= 12 ? substr($clean, 2, 2) : substr($clean, 0, 2);

        return "+55 {$ddd} *****-{$lastFour}";
    }

    /**
     * Hash do telefone para busca.
     */
    public static function hashPhone(string $phone): string
    {
        $clean = preg_replace('/[^0-9+]/', '', $phone);
        return hash('sha256', $clean);
    }

    /**
     * Retorna telefone mascarado.
     */
    public function getPhoneMasked(): string
    {
        return $this->phone_masked ?? self::maskPhone($this->whatsapp_e164 ?? '');
    }

    /**
     * Marca WhatsApp como confirmado.
     */
    public function markWhatsAppConfirmed(): void
    {
        $this->whatsapp_confirmed_at = now();
        $this->phone_verified = true;
        $this->save();
    }

    // ========================================
    // Address Methods (ViaCEP)
    // ========================================

    /**
     * Atualiza endereço via CEP.
     */
    public function updateAddressFromViaCep(array $viaCepData): void
    {
        $this->fill([
            'cep' => $viaCepData['cep'] ?? null,
            'street' => $viaCepData['logradouro'] ?? null,
            'neighborhood' => $viaCepData['bairro'] ?? null,
            'city' => $viaCepData['localidade'] ?? null,
            'state' => $viaCepData['uf'] ?? null,
            'ibge' => $viaCepData['ibge'] ?? null,
            'ddd' => $viaCepData['ddd'] ?? null,
            'siafi' => $viaCepData['siafi'] ?? null,
            'viacep_raw' => $viaCepData,
            'viacep_synced_at' => now(),
        ]);
        $this->save();
    }

    /**
     * Retorna endereço formatado.
     */
    public function getFullAddress(): ?string
    {
        if (!$this->street) {
            return null;
        }

        $parts = array_filter([
            $this->street,
            $this->number,
            $this->complement,
            $this->neighborhood,
            $this->city,
            $this->state,
            $this->cep,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Verifica se tem endereço completo.
     */
    public function hasCompleteAddress(): bool
    {
        return !empty($this->cep) &&
            !empty($this->city) &&
            !empty($this->state);
    }

    // ========================================
    // Activity Methods
    // ========================================

    /**
     * Atualiza última atividade.
     */
    public function updateLastSeen(): bool
    {
        $this->last_seen_at = now();
        return $this->save();
    }

    // ========================================
    // Eligibility Methods
    // ========================================

    /**
     * Verifica se já participou de uma campanha.
     */
    public function hasParticipatedInCampaign(int $campaignId): bool
    {
        return WheelSpin::whereHas('sessionPlayer', function ($q) {
            $q->where('player_id', $this->id);
        })
            ->whereHas('sessionPlayer.session', function ($q) use ($campaignId) {
                $q->where('campaign_id', $campaignId);
            })
            ->where('status', 'completed')
            ->exists();
    }

    /**
     * Conta quantas vezes jogou em uma campanha.
     */
    public function getSpinsInCampaign(int $campaignId): int
    {
        return WheelSpin::whereHas('sessionPlayer', function ($q) {
            $q->where('player_id', $this->id);
        })
            ->whereHas('sessionPlayer.session', function ($q) use ($campaignId) {
                $q->where('campaign_id', $campaignId);
            })
            ->where('status', 'completed')
            ->count();
    }

    // ========================================
    // Factory Methods
    // ========================================

    /**
     * Busca ou cria player pelo WhatsApp.
     */
    public static function findOrCreateByPhone(string $phone, ?string $name = null): self
    {
        $phoneHash = self::hashPhone($phone);

        $player = self::where('phone_hash', $phoneHash)->first();

        if (!$player) {
            $player = self::create([
                'player_key' => 'player_' . Str::random(12),
                'whatsapp_e164' => $phone,
                'full_name' => $name,
                'phone_masked' => self::maskPhone($phone),
                'phone_hash' => $phoneHash,
            ]);
        } elseif ($name && !$player->full_name) {
            $player->full_name = $name;
            $player->save();
        }

        return $player;
    }

    /**
     * Retorna dados públicos para API.
     */
    public function toPublicArray(): array
    {
        return [
            'player_key' => $this->player_key,
            'name' => $this->full_name,
            'phone_masked' => $this->getPhoneMasked(),
            'whatsapp_confirmed' => $this->whatsapp_confirmed_at !== null,
            'has_address' => $this->hasCompleteAddress(),
            'city' => $this->city,
            'state' => $this->state,
        ];
    }
}
