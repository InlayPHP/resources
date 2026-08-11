<?php

declare(strict_types=1);

namespace Inlay\Resources\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Str;

final class MakeResourceCommand extends Command
{
    protected $signature = 'make:inlay-resource
        {model : Model class or basename}
        {--view : Add a read-only View page and an infolist}
        {--soft-deletes : Enable the soft-delete query, filter, and action presets}
        {--simple : Create a single list page that manages records in modals}
        {--generate : Derive the form, table, and rules from the model table}
        {--force : Overwrite existing files}';

    protected $description = 'Create an Inlay resource and its pages';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $modelInput = trim((string) $this->argument('model'), '\/ ');
        $modelBase = class_basename($modelInput);
        if ($modelBase === '' || preg_match('/^[A-Z][A-Za-z0-9_]*$/', $modelBase) !== 1) {
            $this->components->error('The model must end with a valid StudlyCase class name.');

            return self::FAILURE;
        }

        $appNamespace = rtrim($this->laravel->getNamespace(), '\\');
        $model = str_contains($modelInput, '\\') ? $modelInput : $appNamespace.'\\Models\\'.$modelBase;
        $simple = (bool) $this->option('simple');
        $schema = null;
        if ($this->option('generate')) {
            // A bare name normally means App\Models\Name, but an already
            // resolvable class wins so the generator works outside that layout.
            $modelClass = class_exists($model) || ! class_exists($modelInput) ? $model : $modelInput;
            if (! class_exists($modelClass) || ! is_subclass_of($modelClass, EloquentModel::class)) {
                $this->components->error("Generating from the schema requires the model class [{$modelClass}] to exist.");

                return self::FAILURE;
            }

            try {
                $schema = ModelSchema::forModel($modelClass);
            } catch (\Throwable $exception) {
                $this->components->error($exception->getMessage());

                return self::FAILURE;
            }
        }
        $view = (bool) $this->option('view');
        if ($simple && $view) {
            $this->components->error('A simple resource manages records in modals, so it cannot have a View page.');

            return self::FAILURE;
        }

        $resource = $modelBase.'Resource';
        $namespace = $appNamespace.'\\Inlay\\Resources';
        $directory = app_path('Inlay/Resources');
        $plural = Str::pluralStudly($modelBase);
        $files = [
            $directory.'/'.$resource.'.php' => $this->resourceSource(
                $appNamespace,
                $namespace,
                $resource,
                $model,
                $modelBase,
                $view,
                $simple,
                (bool) $this->option('soft-deletes'),
                $schema,
            ),
            $directory.'/List'.$plural.'.php' => $this->pageSource($namespace, 'List'.$plural, $resource, 'ListRecords', 'index'),
            app_path('Validation/'.$modelBase.'Rules.php') => $this->validationSource($appNamespace.'\\Validation', $modelBase, $schema),
        ];

        if (! $simple) {
            $files[$directory.'/Create'.$modelBase.'.php'] = $this->pageSource($namespace, 'Create'.$modelBase, $resource, 'CreateRecord', 'form');
            $files[$directory.'/Edit'.$modelBase.'.php'] = $this->pageSource($namespace, 'Edit'.$modelBase, $resource, 'EditRecord', 'form');
        }
        if ($view) {
            $files[$directory.'/View'.$modelBase.'.php'] = $this->pageSource($namespace, 'View'.$modelBase, $resource, 'ViewRecord', 'view');
        }

        foreach ($files as $path => $source) {
            if ($this->files->exists($path) && ! $this->option('force')) {
                $this->components->error("File already exists: {$path}");

                return self::FAILURE;
            }
        }

        foreach ($files as $path => $source) {
            $this->files->ensureDirectoryExists(dirname($path));
            $this->files->put($path, $source);
            $this->components->info('Created '.basename($path));
        }

        $this->newLine();
        $this->components->info("Register {$resource} with InlayResources::routes([...]) in routes/web.php.");

        return self::SUCCESS;
    }

    private function resourceSource(
        string $appNamespace,
        string $namespace,
        string $resource,
        string $model,
        string $modelBase,
        bool $view,
        bool $simple,
        bool $softDeletes,
        ?ModelSchema $schema,
    ): string {
        $plural = Str::pluralStudly($modelBase);
        $validation = $modelBase.'Rules';
        $validationFqcn = $appNamespace.'\\Validation\\'.$validation;
        $softDeleteProperty = $softDeletes ? "\n    protected static bool \$softDeletes = true;\n" : '';
        $infolistImports = $view
            ? "use Inlay\\Infolists\\Entries\\TextEntry;\nuse Inlay\\Infolists\\Infolist;\n"
            : '';
        $infolist = $view
            ? <<<INFOLIST


    public static function infolist(Infolist \$infolist): Infolist
    {
        return \$infolist->schema([TextEntry::make('name')]);
    }
INFOLIST
            : '';
        $tableColumns = $schema === null || $schema->tableColumns() === []
            ? "TextColumn::make('name')->searchable()->sortable()"
            : "\n            ".implode(",\n            ", $schema->tableColumns())."\n        ";
        $formFields = $schema === null || $schema->formFields() === []
            ? "TextInput::make('name')->required()"
            : "\n            ".implode(",\n            ", $schema->formFields())."\n        ";
        $fieldImports = $schema === null
            ? "use Inlay\\Forms\\Fields\\TextInput;\n"
            : implode('', array_map(static fn (string $import): string => "use {$import};\n", $schema->fieldImports()));

        $pages = $simple
            ? "            'index' => List{$plural}::route('/'),"
            : implode("\n", array_filter([
                "            'index' => List{$plural}::route('/'),",
                "            'create' => Create{$modelBase}::route('/create'),",
                $view ? "            'view' => View{$modelBase}::route('/{record}')," : null,
                "            'edit' => Edit{$modelBase}::route('/{record}/edit'),",
            ]));

        return <<<PHP
<?php

namespace {$namespace};

use {$model};
use {$validationFqcn};
use Illuminate\\Database\\Eloquent\\Model;
{$fieldImports}use Inlay\\Forms\\Form;
{$infolistImports}
use Inlay\\Resources\\Resource;
use Inlay\\Resources\\ResourceOperation;
use Inlay\\Tables\\Columns\\TextColumn;
use Inlay\\Tables\\Table;

final class {$resource} extends Resource
{
    protected static string \$model = {$modelBase}::class;
{$softDeleteProperty}
    public static function table(Table \$table): Table
    {
        return \$table->columns([{$tableColumns}]);
    }

    public static function form(Form \$form): Form
    {
        return \$form->schema([{$formFields}]);
    }

    public static function getPages(): array
    {
        return [
{$pages}
        ];
    }{$infolist}

    public static function validation(): string
    {
        return {$validation}::class;
    }

    protected static function canAccess(ResourceOperation \$operation, ?Model \$record, mixed \$user): bool
    {
        return \$user?->can(\$operation->policyAbility(), \$record ?? {$modelBase}::class) ?? false;
    }
}
PHP;
    }

    private function pageSource(string $namespace, string $class, string $resource, string $base, string $component): string
    {
        $componentPath = Str::kebab(Str::remove('Resource', $resource)).'/'.$component;

        return <<<PHP
<?php

namespace {$namespace};

use Inlay\\Resources\\Pages\\{$base};

final class {$class} extends {$base}
{
    protected static string \$resource = {$resource}::class;

    protected static string \$component = '{$componentPath}';
}
PHP;
    }

    private function validationSource(string $namespace, string $modelBase, ?ModelSchema $schema = null): string
    {
        $rules = $schema === null
            ? "            'name' => ['required', 'string', 'max:255'],"
            : implode("\n", array_map(
                static fn (string $field, array $fieldRules): string => "            '{$field}' => ['".implode("', '", $fieldRules)."'],",
                array_keys($schema->rules()),
                array_values($schema->rules()),
            ));

        return <<<PHP
<?php

namespace {$namespace};

use Inlay\\Validation\\ValidationContext;
use Inlay\\Validation\\Validation;

final class {$modelBase}Rules extends Validation
{
    public function rules(ValidationContext \$context): array
    {
        return [
{$rules}
        ];
    }
}
PHP;
    }
}
