<?php

declare(strict_types=1);

namespace App\Support\Pdv;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Throwable;

final class PdvJsonSchemaValidator
{
    /**
     * @param array<string, mixed> $payload
     * @return array{
     *   status:'skipped'|'valid'|'invalid'|'error',
     *   message:string|null,
     *   errors:array<mixed>,
     *   schema_path:string|null
     * }
     */
    public function validate(array $payload): array
    {
        if (!(bool) config('pdv.json_schema_validation_enabled', false)) {
            return [
                'status' => 'skipped',
                'message' => null,
                'errors' => [],
                'schema_path' => null,
            ];
        }

        $schemaVersion = (string) data_get($payload, 'schema_version', '');
        $schemaPath = $this->resolveSchemaPath($schemaVersion);
        if ($schemaPath === null) {
            return [
                'status' => 'error',
                'message' => "Schema file is not configured for schema_version '{$schemaVersion}'.",
                'errors' => [],
                'schema_path' => null,
            ];
        }

        if (!is_file($schemaPath)) {
            return [
                'status' => 'error',
                'message' => "Schema file not found at '{$schemaPath}'.",
                'errors' => [],
                'schema_path' => $schemaPath,
            ];
        }

        $raw = file_get_contents($schemaPath);
        if (!is_string($raw) || trim($raw) === '') {
            return [
                'status' => 'error',
                'message' => "Schema file at '{$schemaPath}' is empty or unreadable.",
                'errors' => [],
                'schema_path' => $schemaPath,
            ];
        }

        $schema = json_decode($raw);
        if (!is_object($schema) && !is_bool($schema)) {
            return [
                'status' => 'error',
                'message' => "Schema file at '{$schemaPath}' is not valid JSON schema data.",
                'errors' => [],
                'schema_path' => $schemaPath,
            ];
        }

        try {
            $validator = new Validator();
            $result = $validator->validate($this->normalizeData($payload), $schema);
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'message' => 'Schema validator failed: ' . $e->getMessage(),
                'errors' => [],
                'schema_path' => $schemaPath,
            ];
        }

        if ($result->isValid()) {
            return [
                'status' => 'valid',
                'message' => null,
                'errors' => [],
                'schema_path' => $schemaPath,
            ];
        }

        $error = $result->error();
        if ($error === null) {
            return [
                'status' => 'valid',
                'message' => null,
                'errors' => [],
                'schema_path' => $schemaPath,
            ];
        }

        $formattedErrors = (new ErrorFormatter())->format($error, true);

        return [
            'status' => 'invalid',
            'message' => 'Payload does not match JSON schema.',
            'errors' => $formattedErrors,
            'schema_path' => $schemaPath,
        ];
    }

    private function resolveSchemaPath(string $schemaVersion): ?string
    {
        $schemaFiles = config('pdv.json_schema_files', []);
        if (!is_array($schemaFiles)) {
            return null;
        }

        $path = $schemaFiles[$schemaVersion] ?? null;
        if (!is_string($path) || trim($path) === '') {
            return null;
        }

        $path = trim($path);
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return base_path($path);
    }

    private function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function normalizeData(array $payload): object
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return (object) [];
        }

        $decoded = json_decode($json);

        return is_object($decoded) ? $decoded : (object) [];
    }
}
