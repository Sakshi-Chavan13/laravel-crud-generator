<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class GenerateCrud extends Command
{
    protected $signature = 'generate:crud {name}';
    protected $description = 'Generate CRUD files from a template JSON';

    public function handle()
    {
        $name = $this->argument('name');
        $templatePath = base_path("crud-templates/{$name}.json");

        if (!File::exists($templatePath)) {
            $this->error("Template file not found at: {$templatePath}");
            return;
        }

        $template = json_decode(File::get($templatePath), true);
        $modelName = $template['model'];
        $fields = $template['fields'];
        // dump($fields);

        $this->generatePermissions($modelName);

        $this->generateRequest($modelName, 'STORE', $fields);
        $this->generateRequest($modelName, 'UPDATE', $fields);
        $this->generateRequest($modelName, 'INDEX', $fields);
        $this->generateRequest($modelName, 'DESTROY', $fields);
        $this->generateRequest($modelName, 'SHOW', $fields);


        $this->generateModel($modelName, $fields);
        $this->generateMigration($modelName, $fields);
        $this->generateResource($modelName, $fields);
        // $this->generateController($modelName, $generateInsideFolder);

        // $this->generateFactory($modelName, $fields, $generateInsideFolder);



        $this->info("CRUD for {$modelName} generated successfully.");
    }


    private function generatePermissions($modelName)
    {
        $stub = File::get(base_path('app/Stubs/Permissions.stub'));

        $upperModel = Str::upper($modelName);
        $tableName = Str::plural(Str::snake($modelName));

        $stub = str_replace(
            ['{{ modelName }}', '{{ upperModel }}', '{{ tableName }}'],
            [$modelName, $upperModel, $tableName],
            $stub
        );

        $dir = app_path('Constants/Permissions');
        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $path = "{$dir}/{$modelName}Permissions.php";
        File::put($path, $stub);

        $this->info("Permission constants created: $path");
    }


    private function generateRequest($modelName, $type, $fields)
    {
        $stub = File::get(base_path('app/Stubs/Request.stub'));

        $namespaceModel = Str::studly($modelName);
        $className = ucfirst(strtolower($type)) . "{$namespaceModel}Request";

        if ($type == 'INDEX' || $type == 'SHOW') {
            $permissionType = 'VIEW';
        } elseif ($type == 'STORE') {
            $permissionType = 'CREATE';
        } elseif ($type == 'DESTROY') {
            $permissionType = 'DELETE';
        } else {
            $permissionType = $type;
        }

        $rulesArray = [];

        if ($type === 'INDEX') {
            $rulesArray = [
                'per_page' => ['sometimes', 'integer'],
                'page' => ['sometimes', 'integer'],
            ];
        } elseif ($type == 'STORE' || $type == 'UPDATE') {
            foreach ($fields as $field) {
                $name = $field['name'];

                $rulesArray[$name][] = $field['type'];

                if ($type === 'STORE') {
                    $rulesArray[$name][] = isset($field['nullable']) && $field['nullable']
                        ? 'nullable'
                        : 'required';
                } elseif ($type === 'UPDATE') {
                    $rulesArray[$name][] = 'nullable';
                }

                if (str_contains($name, 'id')) {
                    $relation = str_replace('_id', '', $name);
                    $tableName = Str::plural($relation);
                    $rulesArray[$name][] = "exists:{$tableName},id";
                }
            }
        }


        $permissionConst = strtoupper($permissionType) . '_' . strtoupper($modelName);

        $rulesString = $this->convertRulesArrayToString($rulesArray);

        $stub = str_replace(
            ['{{ modelName }}', '{{ className }}', '{{ permissionConst }}', '{{ rules }}'],
            [$namespaceModel, $className, $permissionConst, $rulesString],
            $stub
        );

        $dir = app_path("Http/Requests/{$namespaceModel}");
        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $path = "{$dir}/{$className}.php";
        File::put($path, $stub);

        $this->info("Request created: $path");
    }

    private function generateModel($modelName, $fields)
    {
        $stub = File::get(base_path('app/Stubs/Model.stub'));

        $fillable = collect($fields)
            ->pluck('name')
            ->map(fn($f) => "'$f'")
            ->implode(', ');

        $relations = '';
        $imports = [];

        foreach ($fields as $field) {

            if (Str::endsWith($field['name'], '_id')) {

                $relationName = Str::camel(str_replace('_id', '', $field['name']));
                $relatedModel = Str::studly($relationName);

                // Collect unique import
                $imports[$relatedModel] = "use App\\Models\\{$relatedModel};";

                $relations .= <<<EOD

    public function {$relationName}()
    {
        return \$this->belongsTo({$relatedModel}::class);
    }

EOD;
            }
        }


        $importLines = implode("\n", $imports);


        $stub = str_replace(
            ['{{ modelName }}', '{{ imports }}', '{{ fillable }}', '{{ relations }}'],
            [$modelName, $importLines, $fillable, trim($relations)],
            $stub
        );

        $path = app_path("Models/{$modelName}.php");
        File::put($path, $stub);

        $this->info("Model created: $path");
    }


    private function generateMigration($modelName, $fields)
    {
        $stub = File::get(base_path('app/Stubs/Migrations.stub'));

        $tableName = Str::plural(Str::snake($modelName));
        $fieldLines = '';

        foreach ($fields as $field) {
            $name = $field['name'];
            $type = $field['type'];
            $nullable = isset($field['nullable']) && $field['nullable'];
            $default = $field['default'] ?? null;


            if (Str::endsWith($name, '_id')) {
                $relation = str_replace('_id', '', $name);
                $relatedTable = Str::plural($relation);
                $line = "\$table->foreignId('{$name}')->constrained('{$relatedTable}')";
            } elseif ($type === 'integer') {
                $line = "\$table->unsignedBigInteger('{$name}')";
            } else {
                $line = "\$table->{$type}('{$name}')";
            }


            if ($nullable) {
                $line .= "->nullable()";
            }


            if (!is_null($default)) {
                $defaultValue = is_string($default) ? "'{$default}'" : $default;
                $line .= "->default({$defaultValue})";
            }

            $fieldLines .= "            {$line};\n";
        }

        $stub = str_replace(
            ['{{ tableName }}', '{{ fields }}'],
            [$tableName, trim($fieldLines)],
            $stub
        );

        $timestamp = now()->format('Y_m_d_His');
        $fileName = "{$timestamp}_create_{$tableName}_table.php";
        $path = database_path("migrations/{$fileName}");

        File::put($path, $stub);
        $this->info("Migration created: $path");
    }


    private function generateResource($modelName, $fields)
    {

        $stub = File::get(base_path('app/Stubs/Resource.stub'));
        $resourceFields = [
            "id" => '$this->id',

        ];
        $className =  Str::studly($modelName) . "Resource";
        $namespaceModel = Str::studly($modelName);

        foreach ($fields as $field) {
            $name = $field['name'];

            if (Str::endsWith($name, '_id')) {
                $relation = Str::camel(str_replace('_id', '', $name));
                $relationModel = ucfirst($relation);
                $resource = Str::studly($relation) . 'Resource';

                $imports[] = "use App\Http\Resources\\{$relationModel}\\{$resource};";

                $resourceFields[$relation] = "new {$resource}(\$this->whenLoaded('{$relation}'))";
            } elseif (!in_array($name, ['id', 'created_at', 'updated_at'])) {
                $resourceFields[$name] = "\$this->{$name}";
            }
        }
        $resourceFields = array_merge($resourceFields, [
            'created_at' => '$this->created_at',
            'updated_at' => '$this->updated_at',
        ]);

        $fieldsString = collect($resourceFields)
            ->map(fn($value, $key) => "'$key' => $value")
            ->implode(",\n            ");


        $imports = implode("\n", $imports);

       
        $stub = str_replace(
            ['{{ namespace }}', '{{ className }}', '{{ fields }}', '{{ imports }}'],
            [$namespaceModel, $className, $fieldsString, $imports],
            $stub
        );

        $dir = app_path("Http/Resources/{$namespaceModel}");
        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $path = "{$dir}/{$className}.php";
        File::put($path, $stub);

        $this->info("Resource created: $path");
    }




    private function generateController($modelName)
    {
        $stub = File::get(base_path('stubs/controller.stub'));

        $modelVariable = Str::camel($modelName);

        $stub = str_replace(
            ['{{ modelName }}', '{{ modelVariable }}'],
            [$modelName, $modelVariable],
            $stub
        );

        $path = app_path("Http/Controllers/{$modelName}Controller.php");
        File::put($path, $stub);

        $this->info("Controller created: $path");
    }







    private function convertRulesArrayToString(array $rulesArray): string
    {
        $lines = [];
        $first = 1;
        foreach ($rulesArray as $field => $rules) {
            $rulesJoined = implode("', '", $rules);
            if ($first) {
                $lines[] = "'{$field}' => ['{$rulesJoined}'],";
                $first = 0;
            } else {

                $lines[] = "            '{$field}' => ['{$rulesJoined}'],";
            }
        }

        return implode("\n", $lines);
    }
}
