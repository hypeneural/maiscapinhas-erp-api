<?php

declare(strict_types=1);

namespace App\Support\Audit;

/**
 * Singleton para armazenar contexto da requisição atual.
 * 
 * Usado pelo AuditLogger para incluir automaticamente:
 * - request_id
 * - ip
 * - user_agent
 * - user_id
 * - store_id (quando aplicável)
 */
class AuditContext
{
    private ?string $requestId = null;
    private ?string $ip = null;
    private ?string $userAgent = null;
    private ?int $userId = null;
    private ?int $storeId = null;
    private ?string $route = null;
    private ?string $method = null;

    public function setRequestId(string $requestId): self
    {
        $this->requestId = $requestId;
        return $this;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function setIp(?string $ip): self
    {
        $this->ip = $ip;
        return $this;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function setUserAgent(?string $userAgent): self
    {
        // Limitar tamanho para evitar overflow
        $this->userAgent = $userAgent ? substr($userAgent, 0, 500) : null;
        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserId(?int $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function setStoreId(?int $storeId): self
    {
        $this->storeId = $storeId;
        return $this;
    }

    public function getStoreId(): ?int
    {
        return $this->storeId;
    }

    public function setRoute(?string $route): self
    {
        $this->route = $route;
        return $this;
    }

    public function getRoute(): ?string
    {
        return $this->route;
    }

    public function setMethod(?string $method): self
    {
        $this->method = $method;
        return $this;
    }

    public function getMethod(): ?string
    {
        return $this->method;
    }

    /**
     * Retorna todos os dados de contexto como array.
     */
    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'ip' => $this->ip,
            'user_agent' => $this->userAgent,
            'user_id' => $this->userId,
            'store_id' => $this->storeId,
            'route' => $this->route,
            'method' => $this->method,
        ];
    }

    /**
     * Limpa o contexto (útil para testes).
     */
    public function clear(): void
    {
        $this->requestId = null;
        $this->ip = null;
        $this->userAgent = null;
        $this->userId = null;
        $this->storeId = null;
        $this->route = null;
        $this->method = null;
    }
}
