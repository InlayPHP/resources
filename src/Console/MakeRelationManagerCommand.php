<?php

declare(strict_types=1);

namespace Inlay\Resources\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

final class MakeRelationManagerCommand extends Command
{
    protected $signature = 'make:inlay-relation-manager
        {resource : Resource class or basename}
        {relationship : Eloquent relationship method}
        {recordTitle=name : Related record title attribute}
        {--force : Overwrite the existing relation manager}';

    protected $description = 'Create an owner-scoped Inlay resource relation manager';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $resource = class_basename(trim((string) $this->argument('resource'), '\/ '));
        $relationship = trim((string) $this->argument('relationship'));
        $recordTitle = trim((string) $this->argument('recordTitle'));
        if (preg_match('/^[A-Z][A-Za-z0-9_]*Resource$/', $resource) !== 1) {
            $this->components->error('The resource must end with a valid StudlyCase Resource class name.');

            return self::FAILURE;
        }
        if (
            preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $relationship) !== 1
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $recordTitle) !== 1
        ) {
            $this->components->error('The relationship and record title must be valid PHP-style identifiers.');

            return self::FAILURE;
        }

        $appNamespace = rtrim($this->laravel->getNamespace(), '\\');
        $resourceBase = Str::remove('Resource', $resource);
        $class = Str::pluralStudly(Str::studly($relationship)).'RelationManager';
        $validation = $resourceBase.Str::singularStudly(Str::studly($relationship)).'Rules';
        $namespace = $appNamespace.'\\Inlay\\Resources\\'.$resourceBase.'\\RelationManagers';
        $path = app_path("Inlay/Resources/{$resourceBase}/RelationManagers/{$class}.php");
        $validationPath = app_path("Validation/{$validation}.php");
        foreach ([$path, $validationPath] as $candidate) {
            if (! $this->files->exists($candidate) || $this->option('force')) {
                continue;
            }
            $this->components->error("File already exists: {$candidate}");

            return self::FAILURE;
        }

        $source = <<<PHP
<?php

namespace {$namespace};

use {$appNamespace}\\Validation\\{$validation};
use Illuminate\\Database\\Eloquent\\Model;
use Inlay\\Forms\\Fields\\TextInput;
use Inlay\\Forms\\Form;
use Inlay\\Resources\\RelationManager;
use Inlay\\Resources\\RelationOperation;
use Inlay\\Tables\\Columns\\TextColumn;
use Inlay\\Tables\\Table;

final class {$class} extends RelationManager
{
    protected static string \$relationship = '{$relationship}';

    protected static ?string \$recordTitleAttribute = '{$recordTitle}';

    public function table(Table \$table): Table
    {
        return \$table->columns([
            TextColumn::make('{$recordTitle}')->searchable()->sortable(),
        ]);
    }

    public function form(Form \$form): Form
    {
        return \$form->schema([
            TextInput::make('{$recordTitle}')->required(),
        ]);
    }

    public function validation(): string
    {
        return {$validation}::class;
    }

    protected function canAccess(RelationOperation \$operation, ?Model \$record, mixed \$user): bool
    {
        // Replace with a policy or explicit application authorization.
        return false;
    }
}
PHP;
        $validationSource = <<<PHP
<?php

namespace {$appNamespace}\\Validation;

use Inlay\\Validation\\Validation;
use Inlay\\Validation\\ValidationContext;

final class {$validation} extends Validation
{
    public function rules(ValidationContext \$context): array
    {
        return [
            '{$recordTitle}' => ['required', 'string', 'max:255'],
        ];
    }
}
PHP;

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $source);
        $this->files->ensureDirectoryExists(dirname($validationPath));
        $this->files->put($validationPath, $validationSource);
        $this->components->info("Created {$class}");
        $this->components->info("Created {$validation}");
        $this->components->warn("Add {$class}::class to {$resource}::getRelations() and replace the fail-closed authorization stub.");

        return self::SUCCESS;
    }
}
