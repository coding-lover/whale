<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * 应用层 Model 基类
 *
 * 所有业务模型继承此类，即可使用 Eloquent ORM 的全部能力。
 * 框架已在启动时完成 Eloquent 初始化（连接池 + 协程适配），
 * 子类只需声明 $table、$fillable 等属性即可。
 *
 * 使用示例：
 *   namespace App\Models;
 *
 *   class User extends Model
 *   {
 *       protected $table = 'users';
 *       protected $fillable = ['name', 'email'];
 *   }
 *
 *   // 查询
 *   $user = User::find(1);
 *   $users = User::where('age', '>', 18)->get();
 *
 *   // 创建
 *   $user = User::create(['name' => 'Alice', 'email' => 'a@b.com']);
 *
 *   // 更新
 *   $user->name = 'Bob';
 *   $user->save();
 */
abstract class Model extends EloquentModel
{
    /**
     * 不使用 Eloquent 默认的 created_at / updated_at 时间戳
     * 如需时间戳，在子类中设为 true 并在表中添加 created_at、updated_at 字段
     *
     * @var bool
     */
    public $timestamps = false;
}
