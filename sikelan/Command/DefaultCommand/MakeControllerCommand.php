<?php

namespace Sikelan\Command\DefaultCommand;

use Sikelan\Command\CommandInterface;

class MakeControllerCommand implements CommandInterface
{
    protected string $controllerDir;

    public function __construct()
    {
        $this->controllerDir = APP_PATH . '/Controllers';
    }

    public function commandName(): string
    {
        return 'make:controller';
    }

    public function exec(array $args): ?string
    {
        if (empty($args)) {
            return "\033[31mError: Controller name is required.\033[0m\n" . $this->help([]);
        }

        $controllerName = $args[0];
        $force = in_array('--force', $args) || in_array('-f', $args);

        if (strpos($controllerName, 'Controller') === false) {
            $controllerName .= 'Controller';
        }

        $namespace = 'App\\Controllers';
        $className = $controllerName;
        $filePath = $this->controllerDir . '/' . $controllerName . '.php';

        if (file_exists($filePath) && !$force) {
            return "\033[33mController '{$controllerName}' already exists.\033[0m\nUse --force or -f to overwrite.";
        }

        $template = $this->generateTemplate($namespace, $className);

        if (!is_dir($this->controllerDir)) {
            mkdir($this->controllerDir, 0755, true);
        }

        file_put_contents($filePath, $template);

        $this->updateRouter($controllerName);

        return "\033[32mController '{$controllerName}' created successfully!\033[0m\nFile: {$filePath}";
    }

    public function help(array $args): ?string
    {
        return <<<HELP
Make Controller Command

Usage:
  php sikelan make:controller <name> [options]

Arguments:
  name            Controller name (e.g., User, Product, Order)

Options:
  -f, --force     Force overwrite if file exists

Examples:
  php sikelan make:controller User
  php sikelan make:controller ProductController
  php sikelan make:controller Order -f
HELP;
    }

    public function desc(): string
    {
        return 'Create a new controller class';
    }

    protected function generateTemplate(string $namespace, string $className): string
    {
        $controllerBaseName = str_replace('Controller', '', $className);
        $lowerName = strtolower($controllerBaseName);
        $pluralName = $this->toPlural($lowerName);

        return <<<PHP
<?php

namespace {$namespace};

use Sikelan\Http\Request;
use Sikelan\Http\Response;

class {$className}
{
    public function index(Request \$request, \$params)
    {
        return [
            'status' => 'success',
            'data' => [],
        ];
    }

    public function show(Request \$request, \$params)
    {
        \$id = \$params['id'] ?? 0;

        return [
            'status' => 'success',
            'data' => [
                'id' => \$id,
            ],
        ];
    }

    public function store(Request \$request, \$params)
    {
        \$data = \$request->getPostParams();

        return (new Response(201))->withJson([
            'status' => 'success',
            'message' => 'Created successfully',
            'data' => \$data,
        ]);
    }

    public function update(Request \$request, \$params)
    {
        \$id = \$params['id'] ?? 0;
        \$data = \$request->getPostParams();

        return [
            'status' => 'success',
            'message' => 'Updated successfully',
            'data' => [
                'id' => \$id,
                'data' => \$data,
            ],
        ];
    }

    public function destroy(Request \$request, \$params)
    {
        \$id = \$params['id'] ?? 0;

        return [
            'status' => 'success',
            'message' => 'Deleted successfully',
            'data' => [
                'id' => \$id,
            ],
        ];
    }
}

PHP;
    }

    protected function updateRouter(string $controllerName): void
    {
        $routerFile = CONFIG_PATH . '/router.php';

        if (!file_exists($routerFile)) {
            return;
        }

        $controllerBaseName = str_replace('Controller', '', $controllerName);
        $lowerName = strtolower($controllerBaseName);
        $pluralName = $this->toPlural($lowerName);
        $className = "App\\Controllers\\{$controllerName}";

        $routerRoutes = [];
        $content = file_get_contents($routerFile);

        $newRoutes = [
            "    [",
            "        'method' => 'GET',",
            "        'path' => '/api/{$pluralName}',",
            "        'handler' => '{$className}@index',",
            "    ],",
            "    [",
            "        'method' => 'GET',",
            "        'path' => '/api/{$pluralName}/{id}',",
            "        'handler' => '{$className}@show',",
            "    ],",
            "    [",
            "        'method' => 'POST',",
            "        'path' => '/api/{$pluralName}',",
            "        'handler' => '{$className}@store',",
            "    ],",
            "    [",
            "        'method' => 'PUT',",
            "        'path' => '/api/{$pluralName}/{id}',",
            "        'handler' => '{$className}@update',",
            "    ],",
            "    [",
            "        'method' => 'DELETE',",
            "        'path' => '/api/{$pluralName}/{id}',",
            "        'handler' => '{$className}@destroy',",
            "    ],",
        ];

        if (preg_match('/return \[(.*?)\];/s', $content, $matches)) {
            $oldRoutes = $matches[1];
            $newContent = str_replace(
                'return [',
                "return [\n" . implode("\n", $newRoutes) . "\n",
                $content
            );
            file_put_contents($routerFile, $newContent);
        }
    }

    protected function toPlural(string $word): string
    {
        $endings = ['s', 'x', 'z', 'ch', 'sh'];
        foreach ($endings as $ending) {
            if (substr($word, -strlen($ending)) === $ending) {
                return $word . 'es';
            }
        }
        if (substr($word, -1) === 'y') {
            return substr($word, 0, -1) . 'ies';
        }
        return $word . 's';
    }
}
