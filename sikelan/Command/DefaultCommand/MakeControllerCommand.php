<?php

namespace Sikelan\Command\DefaultCommand;

use Sikelan\Command\CommandInterface;
use Sikelan\Command\FileOverwriteGuard;
use Sikelan\Command\RouteUpdaterTrait;

class MakeControllerCommand implements CommandInterface
{
    use FileOverwriteGuard;
    use RouteUpdaterTrait;

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

        // ---- 解析参数 ----
        $controllerName = $args[0];
        $force = in_array('--force', $args) || in_array('-f', $args);
        // --no-backup：配合 -f 使用，覆盖前不生成 .bak 备份
        $noBackup = in_array('--no-backup', $args);
        // -y/--yes：路由冲突覆盖确认直接选是（非交互环境/脚本中必用）
        $autoYes = in_array('-y', $args) || in_array('--yes', $args);

        // --model=ClassName 或 --model ClassName：生成绑定 Eloquent Model 的 ResourceController
        $modelClass = '';
        foreach ($args as $i => $arg) {
            if (strpos($arg, '--model=') === 0) {
                $modelClass = substr($arg, 8);
            } elseif ($arg === '--model' && isset($args[$i + 1])) {
                $modelClass = $args[$i + 1];
            }
        }
        // 简写：如果参数看起来是 Model 类名（含 \ 或以 Model 结尾且不是控制器名），--model 可省略
        // 但为避免歧义，这里不自动推断，必须显式 --model

        if (strpos($controllerName, 'Controller') === false) {
            $controllerName .= 'Controller';
        }

        $namespace = 'App\\Controllers';
        $className = $controllerName;
        $filePath = $this->controllerDir . '/' . $controllerName . '.php';

        // 根据是否绑定 Model 选择模板（先渲染，门禁需要与磁盘文件逐字节比对）
        if ($modelClass !== '') {
            // 校验 Model 类是否存在
            $modelFqcn = strpos($modelClass, '\\') !== false
                ? $modelClass
                : 'App\\Models\\' . $modelClass;
            if (!class_exists($modelFqcn)) {
                return "\033[31mError: Model '{$modelFqcn}' does not exist.\033[0m\n"
                    . "请先执行 `php bin/sikelan make:model <table>` 创建 Model，或检查类名拼写。";
            }
            $template = $this->generateResourceTemplate($namespace, $className, $modelFqcn);
        } else {
            $template = $this->generateTemplate($namespace, $className);
        }

        // 安全写入门禁：检测手工修改，-f 覆盖前自动备份
        $guard = $this->guardWrite($filePath, $template, $force, !$noBackup);
        if ($guard['status'] === 'rejected') {
            return "\033[33mController '{$controllerName}' already exists.\033[0m\n"
                . "\033[33m{$guard['message']}\033[0m";
        }

        // 路由只在文件真正新建/覆盖时处理；unchanged 时也做一次同步
        // （替换语义：旧路由会被刷新；检测到 method+path 冲突时会提示确认）
        $routeHint = $this->updateRouter($controllerName, $autoYes);

        $hint = $modelClass !== '' ? " (bound to {$modelFqcn})" : '';
        $verb = $guard['status'] === 'created' ? 'created successfully' : $guard['status'];
        $out = "\033[32mController '{$controllerName}' {$verb}!{$hint}\033[0m\nFile: {$filePath}";

        // 覆盖警告：明确告知备份位置，防止用户找不到原文件
        if ($guard['status'] === 'overwritten' && $guard['backup'] !== null) {
            $out .= "\n\033[33m⚠ 原文件已被手工修改，覆盖前已备份：{$guard['backup']}\033[0m";
        }
        if ($routeHint !== null) {
            $out .= "\n\033[33m{$routeHint}\033[0m";
        }
        return $out;
    }

    public function help(array $args): ?string
    {
        return <<<HELP
Make Controller Command

Usage:
  php sikelan make:controller <name> [options]

Arguments:
  name                    Controller name (e.g., User, Product, Order)

Options:
  --model=<ModelClass>    绑定 Eloquent Model，生成继承 ResourceController 的完整 CRUD 控制器
                          （支持简写类名如 User，自动补全 App\Models\User；或完整 FQCN）
  -f, --force             文件已存在时强制覆盖（检测到手工修改会先备份为 .bak 文件）
      --no-backup         配合 -f 使用：覆盖前不备份（慎用，手工修改将无法找回）
  -y, --yes               路由同步检测到 method+path 重复/冲突时，跳过交互确认直接覆盖
                          （管道/脚本等非交互环境会自动取消，必须加此选项才会覆盖）

Examples:
  # 生成空壳控制器（手动实现业务）
  php sikelan make:controller User

  # 生成绑定 Model 的完整 CRUD 控制器（推荐）
  php sikelan make:controller User --model=User
  php sikelan make:controller Order --model=App\\Models\\Order -f
HELP;
    }

    public function desc(): string
    {
        return 'Create a new controller class';
    }

    /**
     * 生成绑定 Eloquent Model 的 ResourceController 子类模板
     *
     * 继承 Sikelan\Http\ResourceController，自动获得 index/show/store/update/destroy
     * 完整 CRUD 能力，子类只需声明 $modelClass 和可选的 $rules/$filterable/$sortable。
     *
     * @param string $namespace  命名空间
     * @param string $className  控制器类名
     * @param string $modelFqcn  Model 的 FQCN
     * @return string PHP 代码
     */
    protected function generateResourceTemplate(string $namespace, string $className, string $modelFqcn): string
    {
        $modelShort = substr($modelFqcn, strrpos($modelFqcn, '\\') + 1);

        return <<<PHP
<?php

namespace {$namespace};

use {$modelFqcn};
use Sikelan\Http\Request;
use Sikelan\Http\ResourceController;

/**
 * 资源控制器（由 make:controller --model 生成）
 *
 * 继承 ResourceController 自动获得完整 CRUD：
 *   GET    /api/xxx        → index()   分页列表（支持 ?page=&per_page=&过滤字段=）
 *   GET    /api/xxx/{id}   → show()    单条详情
 *   POST   /api/xxx        → store()   创建（验证通过后写入）
 *   PUT    /api/xxx/{id}   → update()  部分更新
 *   DELETE /api/xxx/{id}   → destroy() 删除
 *
 * 按需修改下方属性：
 *   \$rules       验证规则（为空则用 Model 的 \$fillable 作为白名单）
 *   \$messages    自定义验证错误消息
 *   \$perPage     默认每页条数
 *   \$filterable  允许通过 query 过滤的字段白名单
 *   \$sortable    允许排序的字段白名单
 */
class {$className} extends ResourceController
{
    protected string \$modelClass = {$modelShort}::class;

    /**
     * 验证规则（参考 Sikelan\Security\Validator 支持的规则）
     *
     * @var array<string, string>
     */
    protected array \$rules = [
        // 'name'  => 'required|string|min:2|max:50',
        // 'email' => 'required|email',
    ];

    /**
     * 自定义验证错误消息
     *
     * @var array<string, string>
     */
    protected array \$messages = [
        // 'name.required' => '名称不能为空',
    ];

    /**
     * 默认每页条数
     *
     * @var int
     */
    protected int \$perPage = 15;

    /**
     * 允许通过 query 过滤的字段白名单
     *
     * @var array<int, string>
     */
    protected array \$filterable = [
        // 'status',
    ];

    /**
     * 允许排序的字段白名单
     *
     * @var array<int, string>
     */
    protected array \$sortable = [
        // 'created_at',
        // 'id',
    ];
}

PHP;
    }

    protected function generateTemplate(string $namespace, string $className): string
    {
        return <<<PHP
<?php

namespace {$namespace};

use Sikelan\Http\Request;
use Sikelan\Http\Response;

class {$className}
{
    public function index(Request \$request): Response
    {
        unset(\$request);

        // 统一成功响应：code=0 / data=空列表
        return (new Response())->ret([]);
    }

    public function show(Request \$request): Response
    {
        \$id = \$request->getInt('id');

        return (new Response())->ret(['id' => \$id]);
    }

    public function store(Request \$request): Response
    {
        \$data = \$request->getPostParams();

        return (new Response())->ret(\$data);
    }

    public function update(Request \$request): Response
    {
        \$id = \$request->getInt('id');
        \$data = \$request->getPostParams();

        return (new Response())->ret([
            'id'   => \$id,
            'data' => \$data,
        ]);
    }

    public function destroy(Request \$request): Response
    {
        \$id = \$request->getInt('id');

        return (new Response())->ret(['id' => \$id]);
    }
}

PHP;
    }

    /**
     * 向 router.php 同步 5 条 RESTful 路由（委托给 RouteUpdaterTrait 统一处理）。
     *
     * @return string|null null=已同步；string=提示信息（如已取消覆盖）
     */
    protected function updateRouter(string $controllerName, bool $autoYes = false): ?string
    {
        return $this->syncStandardCrudRoutes($controllerName, $autoYes);
    }
}
