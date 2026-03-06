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

        if ($reportPath === null || ! File::exists($reportPath)) {
            $this->error('Report file not found: ' . ($reportPath ?? 'no path provided'));

            return self::FAILURE;
        }

        $schemaVersion = (string) $this->option('schema');
        $schemaPath = $this->resolveSchemaPath($schemaVersion);

        if (! File::exists($schemaPath)) {
            $this->error("Schema not found for version: {$schemaVersion}");

            return self::FAILURE;
        }

        $reportContent = File::get($reportPath);
        $reportData = json_decode($reportContent);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Invalid JSON: ' . json_last_error_msg());

            return self::FAILURE;
        }

        $schemaContent = File::get($schemaPath);
        $schema = json_decode($schemaContent);

        $errors = $this->validateAgainstSchema($reportData, $schema);

        if ($this->option('strict-artifacts') && $errors === []) {
            $errors = array_merge($errors, $this->validateArtifactPaths($reportData, dirname($reportPath)));
        }

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
        $errors = [];

        if (! is_object($data)) {
            return ['Report must be a JSON object'];
        }

        // Check required properties
        if (isset($schema->required) && is_array($schema->required)) {
            foreach ($schema->required as $required) {
                if (! property_exists($data, $required)) {
                    $errors[] = "Missing required property: {$required}";
                }
            }
        }

        // Check property types
        if (isset($schema->properties) && is_object($schema->properties)) {
            foreach ($schema->properties as $propName => $propSchema) {
                if (! property_exists($data, $propName)) {
                    continue;
                }

                $propErrors = $this->validateProperty($data->{$propName}, $propSchema, $propName);
                $errors = array_merge($errors, $propErrors);
            }
        }

        // Check additionalProperties
        if (isset($schema->additionalProperties) && $schema->additionalProperties === false) {
            $allowedProps = isset($schema->properties) ? array_keys((array) $schema->properties) : [];
            foreach (array_keys((array) $data) as $key) {
                if (! in_array($key, $allowedProps, true)) {
                    $errors[] = "Unexpected property: {$key}";
                }
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateProperty(mixed $value, object $schema, string $path): array
    {
        $errors = [];

        // Handle const
        if (isset($schema->const) && $value !== $schema->const) {
            $errors[] = "{$path}: expected constant value '{$schema->const}', got '" . (is_scalar($value) ? (string) $value : gettype($value)) . "'";
        }

        // Handle type checking
        if (isset($schema->type)) {
            $typeValid = $this->checkType($value, $schema->type);
            if (! $typeValid) {
                $expectedType = is_array($schema->type) ? implode('|', $schema->type) : $schema->type;
                $errors[] = "{$path}: expected type '{$expectedType}', got '" . gettype($value) . "'";
            }
        }

        // Handle anyOf
        if (isset($schema->anyOf) && is_array($schema->anyOf)) {
            $anyValid = false;
            foreach ($schema->anyOf as $subSchema) {
                $subErrors = $this->validateProperty($value, $subSchema, $path);
                if ($subErrors === []) {
                    $anyValid = true;

                    break;
                }
            }
            if (! $anyValid) {
                $errors[] = "{$path}: value does not match any of the allowed schemas";
            }
        }

        // Handle minLength for strings
        if (isset($schema->minLength) && is_string($value) && mb_strlen($value) < $schema->minLength) {
            $errors[] = "{$path}: string length must be at least {$schema->minLength}";
        }

        // Handle minimum for numbers
        if (isset($schema->minimum) && is_numeric($value) && $value < $schema->minimum) {
            $errors[] = "{$path}: value must be >= {$schema->minimum}";
        }

        // Handle enum
        if (isset($schema->enum) && is_array($schema->enum) && ! in_array($value, $schema->enum, true)) {
            $errors[] = "{$path}: value must be one of [" . implode(', ', $schema->enum) . ']';
        }

        // Handle nested objects
        if (isset($schema->type) && $schema->type === 'object' && is_object($value)) {
            $nested = $this->validateAgainstSchema($value, $schema);
            foreach ($nested as $nestedError) {
                $errors[] = "{$path}.{$nestedError}";
            }
        }

        // Handle arrays
        if (isset($schema->type) && $schema->type === 'array' && is_array($value) && isset($schema->items)) {
            foreach ($value as $i => $item) {
                $itemErrors = $this->validateProperty($item, $schema->items, "{$path}[{$i}]");
                $errors = array_merge($errors, $itemErrors);
            }
        }

        return $errors;
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
        $errors = [];

        if (isset($data->artifacts) && is_object($data->artifacts) && isset($data->artifacts->log_directory)) {
            $logDir = $this->resolvePath((string) $data->artifacts->log_directory, $basePath);
            if (! is_dir($logDir)) {
                $errors[] = "Artifact path does not exist: {$data->artifacts->log_directory}";
            }
        }

        if (isset($data->workers) && is_array($data->workers)) {
            foreach ($data->workers as $i => $worker) {
                if (isset($worker->artifacts->log_directory)) {
                    $logDir = $this->resolvePath((string) $worker->artifacts->log_directory, $basePath);
                    if (! is_dir($logDir)) {
                        $errors[] = "Worker {$i} artifact path does not exist: {$worker->artifacts->log_directory}";
                    }
                }
            }
        }

        return $errors;
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
