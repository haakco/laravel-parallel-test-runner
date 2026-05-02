<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'test:report:validate', description: 'Validate a run_report.json against the v1 schema')]
class TestReportValidateCommand extends Command
{
    protected $signature = 'test:report:validate
        {--report= : Path to the report JSON file}
        {--schema=v1 : Schema version to validate against}
        {--strict-artifacts : Fail if artifact paths do not exist on disk}
        {--pretty : Human-friendly output}';

    protected $description = 'Validate a test run report against the JSON schema';

    public function handle(): int
    {
        $reportPath = $this->resolveReportPath();

        if (! $this->reportExists($reportPath)) {
            $this->error('Report file not found: ' . ($reportPath ?? 'no path provided'));

            return self::FAILURE;
        }

        $schemaVersion = (string) $this->option('schema');
        $schemaPath = $this->resolveSchemaPath($schemaVersion);

        if (! $this->schemaExists($schemaPath, $schemaVersion)) {
            $this->error("Schema not found for version: {$schemaVersion}");

            return self::FAILURE;
        }

        $reportData = $this->decodeJsonFile($reportPath);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Invalid JSON: ' . json_last_error_msg());

            return self::FAILURE;
        }

        $schema = $this->decodeJsonFile($schemaPath);

        $errors = $this->validateAgainstSchema($reportData, $schema);

        $errors = $this->validateStrictArtifacts($reportData, $reportPath, $errors);

        if ($errors !== []) {
            $this->error('Validation FAILED with ' . count($errors) . ' error(s):');

            foreach ($errors as $error) {
                $this->line("  - {$error}");
            }

            return self::FAILURE;
        }

        if ($this->option('pretty')) {
            $this->info('Report is valid against schema ' . $schemaVersion);
            $this->displaySummary($reportData);
        } else {
            $this->info('VALID');
        }

        return self::SUCCESS;
    }

    private function reportExists(?string $path): bool
    {
        return $path !== null && File::exists($path);
    }

    private function schemaExists(string $path, string $version): bool
    {
        return $version !== '' && File::exists($path);
    }

    private function decodeJsonFile(string $path): mixed
    {
        return json_decode(File::get($path));
    }

    private function resolveReportPath(): ?string
    {
        /** @var string|null $path */
        $path = $this->option('report');

        return $path;
    }

    private function resolveSchemaPath(string $version): string
    {
        return dirname(__DIR__) . "/Reporting/Schemas/{$version}.json";
    }

    /**
     * @return list<string>
     */
    private function validateAgainstSchema(mixed $data, object $schema): array
    {
        if (! is_object($data)) {
            return ['Report must be a JSON object'];
        }

        return array_merge(
            $this->validateRequiredProperties($data, $schema),
            $this->validateDefinedProperties($data, $schema),
            $this->validateAdditionalProperties($data, $schema),
        );
    }

    /**
     * @return list<string>
     */
    private function validateRequiredProperties(object $data, object $schema): array
    {
        if (! isset($schema->required) || ! is_array($schema->required)) {
            return [];
        }

        $errors = [];

        foreach ($schema->required as $required) {
            if (! property_exists($data, $required)) {
                $errors[] = "Missing required property: {$required}";
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateDefinedProperties(object $data, object $schema): array
    {
        if (! isset($schema->properties) || ! is_object($schema->properties)) {
            return [];
        }

        $errors = [];

        foreach ($schema->properties as $propName => $propSchema) {
            if (property_exists($data, $propName)) {
                $errors = array_merge($errors, $this->validateProperty($data->{$propName}, $propSchema, $propName));
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateAdditionalProperties(object $data, object $schema): array
    {
        if (! isset($schema->additionalProperties) || $schema->additionalProperties !== false) {
            return [];
        }

        $allowedProps = isset($schema->properties) ? array_keys((array) $schema->properties) : [];
        $errors = [];

        foreach (array_keys((array) $data) as $key) {
            if (! in_array($key, $allowedProps, true)) {
                $errors[] = "Unexpected property: {$key}";
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateProperty(mixed $value, object $schema, string $path): array
    {
        return array_merge(
            $this->validateConst($value, $schema, $path),
            $this->validateType($value, $schema, $path),
            $this->validateAnyOf($value, $schema, $path),
            $this->validateMinLength($value, $schema, $path),
            $this->validateMinimum($value, $schema, $path),
            $this->validateEnum($value, $schema, $path),
            $this->validateNestedObject($value, $schema, $path),
            $this->validateArrayItems($value, $schema, $path),
        );
    }

    /**
     * @return list<string>
     */
    private function validateConst(mixed $value, object $schema, string $path): array
    {
        if (! isset($schema->const) || $value === $schema->const) {
            return [];
        }

        $actual = is_scalar($value) ? (string) $value : gettype($value);

        return ["{$path}: expected constant value '{$schema->const}', got '{$actual}'"];
    }

    /**
     * @return list<string>
     */
    private function validateType(mixed $value, object $schema, string $path): array
    {
        if (! isset($schema->type) || $this->checkType($value, $schema->type)) {
            return [];
        }

        $expectedType = is_array($schema->type) ? implode('|', $schema->type) : $schema->type;

        return ["{$path}: expected type '{$expectedType}', got '" . gettype($value) . "'"];
    }

    /**
     * @return list<string>
     */
    private function validateAnyOf(mixed $value, object $schema, string $path): array
    {
        if (! isset($schema->anyOf) || ! is_array($schema->anyOf)) {
            return [];
        }

        foreach ($schema->anyOf as $subSchema) {
            if ($this->validateProperty($value, $subSchema, $path) === []) {
                return [];
            }
        }

        return ["{$path}: value does not match any of the allowed schemas"];
    }

    /**
     * @return list<string>
     */
    private function validateMinLength(mixed $value, object $schema, string $path): array
    {
        if (! isset($schema->minLength) || ! is_string($value) || mb_strlen($value) >= $schema->minLength) {
            return [];
        }

        return ["{$path}: string length must be at least {$schema->minLength}"];
    }

    /**
     * @return list<string>
     */
    private function validateMinimum(mixed $value, object $schema, string $path): array
    {
        if (! isset($schema->minimum) || ! is_numeric($value) || $value >= $schema->minimum) {
            return [];
        }

        return ["{$path}: value must be >= {$schema->minimum}"];
    }

    /**
     * @return list<string>
     */
    private function validateEnum(mixed $value, object $schema, string $path): array
    {
        if (! isset($schema->enum) || ! is_array($schema->enum) || in_array($value, $schema->enum, true)) {
            return [];
        }

        return ["{$path}: value must be one of [" . implode(', ', $schema->enum) . ']'];
    }

    /**
     * @return list<string>
     */
    private function validateNestedObject(mixed $value, object $schema, string $path): array
    {
        if (! $this->propertyTypeIs($schema, 'object') || ! is_object($value)) {
            return [];
        }

        return array_map(
            static fn(string $nestedError): string => "{$path}.{$nestedError}",
            $this->validateAgainstSchema($value, $schema),
        );
    }

    /**
     * @return list<string>
     */
    private function validateArrayItems(mixed $value, object $schema, string $path): array
    {
        if (! $this->propertyTypeIs($schema, 'array') || ! is_array($value) || ! isset($schema->items)) {
            return [];
        }

        $errors = [];

        foreach ($value as $i => $item) {
            $errors = array_merge($errors, $this->validateProperty($item, $schema->items, "{$path}[{$i}]"));
        }

        return $errors;
    }

    private function propertyTypeIs(object $schema, string $type): bool
    {
        return isset($schema->type) && $schema->type === $type;
    }

    /**
     * @param list<string>|string $type
     */
    private function checkType(mixed $value, array|string $type): bool
    {
        $types = is_array($type) ? $type : [$type];

        foreach ($types as $t) {
            $valid = match ($t) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array' => is_array($value),
                'object' => is_object($value),
                'null' => $value === null,
                default => true,
            };

            if ($valid) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function validateArtifactPaths(object $data, string $basePath): array
    {
        return array_merge(
            $this->validateRunArtifactPath($data, $basePath),
            $this->validateWorkerArtifactPaths($data, $basePath),
        );
    }

    /**
     * @param list<string> $errors
     * @return list<string>
     */
    private function validateStrictArtifacts(mixed $reportData, string $reportPath, array $errors): array
    {
        if (! $this->option('strict-artifacts') || $errors !== [] || ! is_object($reportData)) {
            return $errors;
        }

        return array_merge($errors, $this->validateArtifactPaths($reportData, dirname($reportPath)));
    }

    /**
     * @return list<string>
     */
    private function validateRunArtifactPath(object $data, string $basePath): array
    {
        if (! isset($data->artifacts) || ! is_object($data->artifacts) || ! isset($data->artifacts->log_directory)) {
            return [];
        }

        $logDir = $this->resolvePath((string) $data->artifacts->log_directory, $basePath);

        return is_dir($logDir) ? [] : ["Artifact path does not exist: {$data->artifacts->log_directory}"];
    }

    /**
     * @return list<string>
     */
    private function validateWorkerArtifactPaths(object $data, string $basePath): array
    {
        if (! isset($data->workers) || ! is_array($data->workers)) {
            return [];
        }

        $errors = [];

        foreach ($data->workers as $i => $worker) {
            $errors = array_merge($errors, $this->validateWorkerArtifactPath($worker, $basePath, $i));
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateWorkerArtifactPath(mixed $worker, string $basePath, int $index): array
    {
        if (! isset($worker->artifacts->log_directory)) {
            return [];
        }

        $logDirectory = (string) $worker->artifacts->log_directory;
        $logDir = $this->resolvePath($logDirectory, $basePath);

        return is_dir($logDir) ? [] : ["Worker {$index} artifact path does not exist: {$logDirectory}"];
    }

    private function resolvePath(string $path, string $basePath): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        $projectPath = base_path($path);
        if (File::exists($projectPath)) {
            return $projectPath;
        }

        return $basePath . '/' . ltrim($path, '/');
    }

    private function displaySummary(object $data): void
    {
        $this->newLine();
        $this->line('  Run ID:    ' . ($data->run_id ?? 'unknown'));
        $this->line('  Duration:  ' . ($data->duration_seconds ?? 0) . 's');
        $this->line('  Success:   ' . (($data->success ?? false) ? 'YES' : 'NO'));

        if (isset($data->counters)) {
            $this->line('  Tests:     ' . ($data->counters->tests ?? 0));
            $this->line('  Failures:  ' . ($data->counters->failures ?? 0));
        }

        if (isset($data->sections)) {
            $this->line('  Sections:  ' . ($data->sections->scheduled ?? 0) . ' scheduled, ' . ($data->sections->failed ?? 0) . ' failed');
        }
    }
}
