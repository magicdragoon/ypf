<?php
namespace App\Model;

use Model;

/**
 * 
 */
class Manager extends Model
{
    protected string $table = 'managers';
    ###generated###

    public int $id;

    /**
     * 所属商家，多选
     */
    public array $merchantIds;

    /**
     * 关注商家
     */
    public array $focusMerchantIds;

    public string $mobile;

    public string $nickName;

    public string $avatar;

    public array $roles;

    public string $name;

    public string $password;

    public string $dingtalkUserId;

    public string $wecomUserId;

    public int $createdAt;

    public int $updatedAt;

    public int $deletedAt;

    protected function columns()
    {
        $this->columns = [
            'id' => f('id', '', 'int'),
            'merchant_ids' => f('merchantIds', '所属商家，多选', 'array'),
            'focus_merchant_ids' => f('focusMerchantIds', '关注商家', 'array'),
            'mobile' => f('mobile', '', 'string'),
            'nick_name' => f('nickName', '', 'string'),
            'avatar' => f('avatar', '', 'string'),
            'roles' => f('roles', '', 'array'),
            'name' => f('name', '', 'string'),
            'password' => f('password', '', 'string'),
            'dingtalk_user_id' => f('dingtalkUserId', '', 'string'),
            'wecom_user_id' => f('wecomUserId', '', 'string'),
            'created_at' => f('createdAt', '', 'int'),
            'updated_at' => f('updatedAt', '', 'int'),
            'deleted_at' => f('deletedAt', '', 'int'),
        ];
    }
    ###generated###
}
