<?php

declare(strict_types=1);

namespace Inlay\Resources\Console;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Derive a resource's fields, columns, and rules from the model's table.
 *
 * Generation is a starting point, not a contract: it reads the table once at
 * generation time and writes ordinary PHP the developer then edits.
 */
final readonly class ModelSchema
{
    /** Columns the framework owns; a resource never edits them. */
    private const RESERVED = ['id', 'uuid', 'ulid', 'created_at', 'updated_at', 'deleted_at', 'remember_token'];

    /** @param list<array{name: string, type: string, nullable: bool}> $columns */
    private function __construct(public array $columns) {}

    /** @param class-string<Model> $model */
    public static function forModel(string $model): self
    {
        $instance = new $model;
        $builder = $instance->getConnection()->getSchemaBuilder();
        $table = $instance->getTable();

        if (! $builder->hasTable($table)) {
            throw new \RuntimeException("Table [{$table}] does not exist, so its resource cannot be generated from the schema.");
        }

        $columns = [];
        foreach ($builder->getColumns($table) as $column) {
            $name = (string) $column['name'];
            if (in_array($name, self::RESERVED, true) || ($column['auto_increment'] ?? false)) {
                continue;
            }

            $columns[] = [
                'name' => $name,
                'type' => self::normalizeType((string) ($column['type_name'] ?? $column['type'] ?? 'string'), (string) ($column['type'] ?? '')),
                'nullable' => (bool) ($column['nullable'] ?? false),
            ];
        }

        return new self($columns);
    }

    /** @return list<string> */
    public function formFields(): array
    {
        return array_map(function (array $column): string {
            $required = $column['nullable'] ? '' : '->required()';

            return match ($column['type']) {
                'boolean' => "Toggle::make('{$column['name']}')",
                'date' => "DatePicker::make('{$column['name']}'){$required}",
                'datetime' => "DateTimePicker::make('{$column['name']}'){$required}",
                'number' => "TextInput::make('{$column['name']}')->numeric(){$required}",
                'text' => "Textarea::make('{$column['name']}'){$required}",
                default => "TextInput::make('{$column['name']}')->maxLength(255){$required}",
            };
        }, $this->columns);
    }

    /**
     * Long text does not belong in a table cell, and the first text column is
     * the one worth searching.
     *
     * @return list<string>
     */
    public function tableColumns(): array
    {
        $columns = [];
        $searchable = true;
        foreach ($this->columns as $column) {
            if ($column['type'] === 'text') {
                continue;
            }
            if ($column['type'] === 'string' && $searchable) {
                $columns[] = "TextColumn::make('{$column['name']}')->searchable()->sortable()";
                $searchable = false;

                continue;
            }
            $columns[] = "TextColumn::make('{$column['name']}')->sortable()";
        }

        return $columns;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = [];
        foreach ($this->columns as $column) {
            $rules[$column['name']] = [
                $column['nullable'] ? 'nullable' : 'required',
                ...match ($column['type']) {
                    'boolean' => ['boolean'],
                    'date', 'datetime' => ['date'],
                    'number' => ['numeric'],
                    'text' => ['string'],
                    default => ['string', 'max:255'],
                },
            ];
        }

        return $rules;
    }

    /** @return list<string> */
    public function fieldImports(): array
    {
        $imports = [];
        foreach ($this->columns as $column) {
            $imports[] = match ($column['type']) {
                'boolean' => 'Inlay\\Forms\\Fields\\Toggle',
                'date' => 'Inlay\\Forms\\Fields\\DatePicker',
                'datetime' => 'Inlay\\Forms\\Fields\\DateTimePicker',
                'text' => 'Inlay\\Forms\\Fields\\Textarea',
                default => 'Inlay\\Forms\\Fields\\TextInput',
            };
        }

        return array_values(array_unique($imports));
    }

    private static function normalizeType(string $typeName, string $fullType): string
    {
        $typeName = strtolower($typeName);
        if ($typeName === 'tinyint' && Str::contains(strtolower($fullType), 'tinyint(1)')) {
            return 'boolean';
        }

        return match (true) {
            in_array($typeName, ['bool', 'boolean'], true) => 'boolean',
            $typeName === 'date' => 'date',
            in_array($typeName, ['datetime', 'timestamp'], true) => 'datetime',
            in_array($typeName, ['int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint', 'decimal', 'numeric', 'float', 'double', 'real'], true) => 'number',
            in_array($typeName, ['text', 'mediumtext', 'longtext', 'json', 'jsonb'], true) => 'text',
            default => 'string',
        };
    }
}
