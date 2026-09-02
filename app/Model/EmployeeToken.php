<?php
namespace App\Model;

use Model;

/**
 * 员工凭证
 */
class EmployeeToken extends Model
{
    protected string $table = 'employee_token';
    ###generated###

    /**
     * 编号
     */
    public int $id;

    /**
     * 应用编号
     */
    public int $appId;

    /**
     * 账号编号
     */
    public int $accountId;

    /**
     * 账号角色
     */
    public array $accountRoles;

    /**
     * 访问令牌
     */
    public string $token;

    /**
     * 访问令牌MD5
     */
    public string $md5;

    /**
     * 创建时间
     */
    public string $createTime;

    /**
     * 更新时间
     */
    public string $updateTime;

    protected function columns()
    {
        $this->columns = [
            'id' => f('id', '编号', 'int'),
            'app_id' => f('appId', '应用编号', 'int'),
            'account_id' => f('accountId', '账号编号', 'int'),
            'account_roles' => f('accountRoles', '账号角色', 'array'),
            'token' => f('token', '访问令牌', 'string'),
            'md5' => f('md5', '访问令牌MD5', 'string'),
            'create_time' => f('createTime', '创建时间', 'string'),
            'update_time' => f('updateTime', '更新时间', 'string'),
        ];
    }
    ###generated###
}
