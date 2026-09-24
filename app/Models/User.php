<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 用户模型（users 表）
 *
 * 安全约定：
 *   - password 字段存入 bcrypt 哈希（框架 bcrypt() 函数），绝不存明文
 *   - password / remember_token 在序列化时隐藏（$hidden），避免 API 泄漏
 *   - 软删除（SoftDeletes）：删除记录时仅置 deleted_at，不物理删除
 */
class User extends Model
{
    use SoftDeletes;

    protected $table = 'users';

    /**
     * 可批量赋值字段白名单
     *
     * 注意：created_at / updated_at / deleted_at 由 Eloquent 自动管理，不放入 $fillable
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'username',
        'email',
        'password',
        'nickname',
        'avatar',
        'role',
        'status',
        'email_verified_at',
        'last_login_at',
        'last_login_ip',
        'remember_token',
    ];

    /**
     * 序列化时隐藏的敏感字段（toArray / JSON 输出不包含）
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * 类型转换
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at'     => 'datetime',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
        'deleted_at'        => 'datetime',
    ];

    public $timestamps = true;

    /**
     * 设置密码时自动 bcrypt 哈希
     *
     * 用法：$user->password = 'plain';  // 自动哈希
     *
     * @param string $value 明文密码
     */
    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = bcrypt($value);
    }
}
