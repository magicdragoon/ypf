<?php
namespace App\Model;

use Model;

/**
 * 员工
 * @property int $id 编号
 * @property array $merchantIds 所属商家
 * @property array|null $focusMerchantIds 关注商家
 * @property array|null $roles 角色列表
 * @property string|null $mobile 手机
 * @property string|null $nickname 昵称
 * @property string|null $avatar 头像
 * @property string $username 用户名
 * @property string $password 密码
 * @property \Time|null $createdAt 创建时间
 * @property \Time|null $updatedAt 更新时间
 */
class Employee extends Model
{
    public const TABLE = 'employees';

    public const COLUMNS = [
        'id' => ['id', '编号', 'int'],
        'merchantIds' => ['merchant_ids', '所属商家', 'array'],
        'focusMerchantIds' => ['focus_merchant_ids', '关注商家', 'array', 'nullable'],
        'roles' => ['roles', '角色列表', 'array', 'nullable'],
        'mobile' => ['mobile', '手机', 'string', 'nullable'],
        'nickname' => ['nickname', '昵称', 'string', 'nullable'],
        'avatar' => ['avatar', '头像', 'string', 'nullable'],
        'username' => ['username', '用户名', 'string'],
        'password' => ['password', '密码', 'string'],
        'createdAt' => ['created_at', '创建时间', 'Time', 'nullable'],
        'updatedAt' => ['updated_at', '更新时间', 'Time', 'nullable'],
    ];
}
