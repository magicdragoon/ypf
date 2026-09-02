<?php
namespace App\Model;

use Model;

/**
 * 员工
 */
class Employee extends Model
{
    protected string $table = 'employees';
    ###generated###
    
    /**
     * 编号
     */
    public int $id;

    /**
     * 所属商家
     */
    public array $merchantIds;

    /**
     * 关注商家
     */
    public array $focusMerchantIds;

    /**
     * 角色列表
     */
    public array $roles;

    /**
     * 手机
     */
    public string $mobile;

    /**
     * 昵称
     */
    public string $nickname;

    /**
     * 头像
     */
    public string $avatar;

    /**
     * 用户名
     */
    public string $username;

    /**
     * 密码
     */
    public string $password;

    /**
     * 创建时间
     */
    public int $createdAt;

    /**
     * 更新时间
     */
    public int $updatedAt;

    /**
     * 删除时间
     */
    public int $deletedAt;

    protected function columns()
    {
        $this->columns = [
            'id' => f('id', '编号', 'int'),
            'merchant_ids' => f('merchantIds', '所属商家', 'array'),
            'focus_merchant_ids' => f('focusMerchantIds', '关注商家', 'array'),
            'roles' => f('roles', '角色列表', 'array'),
            'mobile' => f('mobile', '手机', 'string'),
            'nickname' => f('nickname', '昵称', 'string'),
            'avatar' => f('avatar', '头像', 'string'),
            'username' => f('username', '用户名', 'string'),
            'password' => f('password', '密码', 'string'),
            'created_at' => f('createdAt', '创建时间', 'int'),
            'updated_at' => f('updatedAt', '更新时间', 'int'),
            'deleted_at' => f('deletedAt', '删除时间', 'int'),
        ];
    }
    ###generated###
}
