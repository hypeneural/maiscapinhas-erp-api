<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ValidatePdvSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $authMode = $this->resolveAuthMode();

        if ($authMode === 'bearer') {
            return $this->handleBearerMode($request, $next, 'bearer');
        }

        if ($authMode === 'hmac') {
            return $this->handleHmacMode($request, $next);
        }

        // auto mode: prefer HMAC when headers are present.
        $signature = $this->normalizeSignature((string) $request->header('X-PDV-Signature', ''));
        $timestamp = (string) $request->header('X-PDV-Timestamp', '');
        $hasHmacHeaders = $signature !== '' || $timestamp !== '';

        // Legacy fallback for transition period: allow Bearer only when HMAC headers are absent.
        if (!$hasHmacHeaders && $this->isBearerFallbackEnabled() && $this->isValidBearerToken($request)) {
            $request->attributes->set('pdv_auth_mode', 'bearer_fallback');
            Log::warning('PDV webhook authenticated via temporary bearer fallback mode.');

            return $next($request);
        }

        return $this->handleHmacMode($request, $next);
    }

    private function handleHmacMode(Request $request, Closure $next): Response
    {
        $signature = $this->normalizeSignature((string) $request->header('X-PDV-Signature', ''));
        $timestamp = (string) $request->header('X-PDV-Timestamp', '');

        if ($signature === '' || $timestamp === '' || !ctype_digit($timestamp)) {
            return $this->unauthorized('Missing or invalid webhook authentication headers.');
        }

        $secret = config('pdv.hmac_secret');
        if (!is_string($secret) || trim($secret) === '') {
            Log::error('PDV webhook rejected because PDV_HMAC_SECRET is not configured.');

            return response()->json([
                'message' => 'Webhook service unavailable.',
            ], 503);
        }

        $rawBody = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            return response()->json([
                'message' => 'Invalid webhook signature.',
            ], 403);
        }

        $request->attributes->set('pdv_auth_mode', 'hmac');

        return $next($request);
    }

    private function handleBearerMode(Request $request, Closure $next, string $modeLabel): Response
    {
        $configuredToken = (string) config('pdv.bearer_token', '');
        if (trim($configuredToken) === '') {
            Log::error('PDV webhook rejected because PDV_BEARER_TOKEN is not configured.');

            return response()->json([
                'message' => 'Webhook service unavailable.',
            ], 503);
        }

        $authorization = trim((string) $request->header('Authorization', ''));
        if ($authorization === '' || !str_starts_with(strtolower($authorization), 'bearer ')) {
            return $this->unauthorized('Missing or invalid bearer token.');
        }

        $incomingToken = trim(substr($authorization, 7));
        if ($incomingToken === '') {
            return $this->unauthorized('Missing or invalid bearer token.');
        }

        if (!hash_equals($configuredToken, $incomingToken)) {
            return response()->json([
                'message' => 'Invalid bearer token.',
            ], 403);
        }

        $request->attributes->set('pdv_auth_mode', $modeLabel);

        return $next($request);
    }

    private function normalizeSignature(string $signature): string
    {
        if (str_starts_with($signature, 'sha256=')) {
            return substr($signature, 7);
        }

        return $signature;
    }

    private function unauthorized(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
        ], 401);
    }

    private function isBearerFallbackEnabled(): bool
    {
        return (bool) config('pdv.allow_bearer_fallback', false);
    }

    private function resolveAuthMode(): string
    {
        $mode = strtolower(trim((string) config('pdv.auth_mode', 'auto')));

        return in_array($mode, ['auto', 'hmac', 'bearer'], true) ? $mode : 'auto';
    }

    private function isValidBearerToken(Request $request): bool
    {
        $configuredToken = (string) config('pdv.bearer_token', '');
        if (trim($configuredToken) === '') {
            Log::warning('PDV bearer fallback is enabled but PDV_BEARER_TOKEN is empty.');

            return false;
        }

        $authorization = trim((string) $request->header('Authorization', ''));
        if ($authorization === '' || !str_starts_with(strtolower($authorization), 'bearer ')) {
            return false;
        }

        $incomingToken = trim(substr($authorization, 7));
        if ($incomingToken === '') {
            return false;
        }

        return hash_equals($configuredToken, $incomingToken);
    }
}
