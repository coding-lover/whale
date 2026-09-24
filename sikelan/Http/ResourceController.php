<?php

namespace Sikelan\Http;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 资源控制器基类（RESTful CRUD）
 *
 * 借鉴 Laravel Resource Controller 设计，在已集成的 Eloquent ORM 之上提供
 * 开箱即用的增删改查，子类只需声明对应的 Model 类名和验证规则即可。
 *
 * 子类示例：
 *   class UserController extends ResourceController
 *   {
 *       protected string $modelClass = \App\Models\User::class;
 *       protected array $rules = [
 *           'name'  => 'required|string|min:2|max:50',
 *           'email' => 'required|email',
 *       ];
 *       protected array $filterable = ['status'];
 *       protected array $sortable   = ['created_at', 'id'];
 *   }
 *
 * 自动提供 5 个动作（对应 REST 路由）：
 *   GET    /api/users        → index()   分页列表
 *   GET    /api/users/{id}   → show()    单条详情
 *   POST   /api/users        → store()   创建（201）
 *   PUT    /api/users/{id}   → update()  更新
 *   DELETE /api/users/{id}   → destroy() 删除（204）
 *
 * 安全特性：
 *   - 输入自动净化（Request 便利方法内置 InputSanitizer）
 *   - 可选的 Validator 验证（422 错误响应）
 *   - 仅 $rules 声明的字段可批量写入（防 mass assignment）
 *   - JSON 输出走 withJson()（XSS 安全 flag + 安全响应头）
 *   - 找不到资源返回 404
 */
abstract class ResourceController
{
    /**
     * 对应的 Eloquent Model 类名（FQCN），子类必须声明
     *
     * @var string
     */
    protected string $modelClass;

    /**
     * 验证规则（格式同 validator()）；为空则跳过验证，直接用 Model 的 $fillable 白名单
     *
     * @var array<string, string>
     */
    protected array $rules = [];

    /**
     * 自定义验证错误消息（key = "字段.规则"）
     *
     * @var array<string, string>
     */
    protected array $messages = [];

    /**
     * 默认每页条数
     *
     * @var int
     */
    protected int $perPage = 15;

    /**
     * 单页最大条数（防止一次拉取过多数据打爆内存）
     *
     * @var int
     */
    protected int $maxPerPage = 100;

    /**
     * index() 允许通过 query 过滤的字段白名单；空数组表示不允许过滤
     *
     * 例：['status', 'type'] → ?status=active&type=foo 会被转成 WHERE 条件
     *
     * @var array<int, string>
     */
    protected array $filterable = [];

    /**
     * index() 允许排序的字段白名单；空数组表示不允许排序
     *
     * 例：['created_at', 'id'] → ?sort=created_at&order=desc
     *
     * @var array<int, string>
     */
    protected array $sortable = [];

    /**
     * 构造时校验 $modelClass 已声明且是合法的 Eloquent Model
     */
    public function __construct()
    {
        if (empty($this->modelClass)) {
            throw new \LogicException(static::class . ' must declare $modelClass property.');
        }
        if (!class_exists($this->modelClass)) {
            throw new \LogicException("Model class '{$this->modelClass}' does not exist.");
        }
        if (!is_subclass_of($this->modelClass, Model::class)) {
            throw new \LogicException(
                "{$this->modelClass} must extend Illuminate\\Database\\Eloquent\\Model."
            );
        }
    }

    /**
     * 列表（分页 + 可选过滤/排序）
     *
     * Query 参数：
     *   page     int   页码，默认 1
     *   per_page int   每页条数，默认 $perPage，上限 $maxPerPage
     *   <field>  mixed 过滤值（仅 $filterable 中的字段生效）
     *   sort     string 排序字段（仅 $sortable 中的字段生效）
     *   order    string 排序方向 asc|desc，默认 asc
     */
    public function index(Request $request): Response
    {
        $page    = max(1, $request->getInt('page', 1));
        $perPage = min(max(1, $request->getInt('per_page', $this->perPage)), $this->maxPerPage);

        /** @var \Illuminate\Database\Eloquent\Builder $query */
        $query = $this->newQuery();

        // 过滤
        foreach ($this->filterable as $field) {
            if ($request->has($field)) {
                $query->where($field, $request->input($field));
            }
        }

        // 排序
        $sort = $request->input('sort');
        if (is_string($sort) && in_array($sort, $this->sortable, true)) {
            $order = strtolower((string) $request->input('order', 'asc'));
            $order = in_array($order, ['asc', 'desc'], true) ? $order : 'asc';
            $query->orderBy($sort, $order);
        }

        $total = $query->count();
        $items = $query->forPage($page, $perPage)->get();

        // 统一格式：code=0 / data=列表 / pagination 放 extra
        return (new Response())->ret(
            $items->toArray(),
            [
                'pagination' => [
                    'page'      => $page,
                    'per_page'  => $perPage,
                    'total'     => $total,
                    'last_page' => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
                ],
            ]
        );
    }

    /**
     * 详情
     */
    public function show(Request $request, $id): Response
    {
        unset($request);
        $model = $this->findModel($id);
        if ($model === null) {
            return $this->notFound();
        }
        return (new Response())->ret($model->toArray());
    }

    /**
     * 创建
     */
    public function store(Request $request): Response
    {
        $data = $request->getPostParams();

        $validated = $this->validate($data);
        if ($validated === null) {
            // 验证失败：错误响应已在 validate() 中返回
            return $this->lastValidationError;
        }

        $model = $this->modelClass::create($validated);

        return (new Response())->ret($model->toArray());
    }

    /**
     * 更新
     */
    public function update(Request $request, $id): Response
    {
        $model = $this->findModel($id);
        if ($model === null) {
            return $this->notFound();
        }

        $data = $request->getPostParams();

        $validated = $this->validate($data, true);
        if ($validated === null) {
            return $this->lastValidationError;
        }

        // 仅更新提交的字段（部分更新）
        $model->fill($validated);
        $model->save();

        return (new Response())->ret($model->toArray());
    }

    /**
     * 删除
     */
    public function destroy(Request $request, $id): Response
    {
        unset($request);
        $model = $this->findModel($id);
        if ($model === null) {
            return $this->notFound();
        }

        $model->delete();

        return (new Response())->ret();
    }

    // -----------------------------------------------------------
    //  内部辅助
    // -----------------------------------------------------------

    /**
     * 缓存最近一次验证失败的响应，供 store/update 返回
     *
     * @var Response|null
     */
    private ?Response $lastValidationError = null;

    /**
     * 执行验证，返回通过验证的字段数据；失败时返回 null 并设置 $lastValidationError
     *
     * @param array $data    输入数据
     * @param bool  $partial true = update 模式，只验证 data 中存在的字段
     * @return array|null    通过验证的字段；验证失败返回 null
     */
    protected function validate(array $data, bool $partial = false): ?array
    {
        $this->lastValidationError = null;

        // 无规则：用 Model 的 $fillable 作为白名单
        if ($this->rules === []) {
            $fillable = $this->modelClass::make()->getFillable();
            return array_intersect_key($data, array_flip($fillable));
        }

        // update 模式：只对提交的字段应用规则
        $rules = $partial ? array_intersect_key($this->rules, $data) : $this->rules;

        if ($rules === []) {
            // 提交的字段都不在规则中 → 无可更新字段
            return [];
        }

        $validator = validator($data, $rules, $this->messages);
        if ($validator->fails()) {
            $this->lastValidationError = (new Response())->err(
                'Validation failed',
                Response::CODE_VALIDATION,
                ['errors' => $validator->errors()]
            );
            return null;
        }

        return $validator->validated();
    }

    /**
     * 按主键查找 Model；找不到返回 null（不抛异常，方便统一返回 404）
     *
     * @param mixed $id
     */
    protected function findModel($id): ?Model
    {
        try {
            return $this->modelClass::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return null;
        }
    }

    /**
     * 构造查询构造器（子类可覆写以添加全局作用域，如软删除过滤）
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function newQuery()
    {
        return $this->modelClass::query();
    }

    /**
     * 资源不存在的失败响应（HTTP 200 + 业务码 CODE_NOT_FOUND）
     */
    protected function notFound(): Response
    {
        return (new Response())->err('Resource not found', Response::CODE_NOT_FOUND);
    }
}
