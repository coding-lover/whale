<?php

namespace App\Controllers;

use App\Models\Strategy;
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
 *   $rules       验证规则（为空则用 Model 的 $fillable 作为白名单）
 *   $messages    自定义验证错误消息
 *   $perPage     默认每页条数
 *   $filterable  允许通过 query 过滤的字段白名单
 *   $sortable    允许排序的字段白名单
 */
class StrategyController extends ResourceController
{
    protected string $modelClass = Strategy::class;

    /**
     * 验证规则（参考 Sikelan\Security\Validator 支持的规则）
     *
     * @var array<string, string>
     */
    protected array $rules = [
        // 'name'  => 'required|string|min:2|max:50',
        // 'email' => 'required|email',
    ];

    /**
     * 自定义验证错误消息
     *
     * @var array<string, string>
     */
    protected array $messages = [
        // 'name.required' => '名称不能为空',
    ];

    /**
     * 默认每页条数
     *
     * @var int
     */
    protected int $perPage = 15;

    /**
     * 允许通过 query 过滤的字段白名单
     *
     * @var array<int, string>
     */
    protected array $filterable = [
        // 'status',
    ];

    /**
     * 允许排序的字段白名单
     *
     * @var array<int, string>
     */
    protected array $sortable = [
        // 'created_at',
        // 'id',
    ];
}
