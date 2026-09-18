<?php
namespace App\Model;

use Model;

/**
 * 角色权限
 * @property int $roleId 角色编号
 * @property int $authId 权限编号
 */
class RoleAuth extends Model
{
    public const TABLE = 'role_auths';
    public const PRIMARY = null;
    public const UNIQUE = ['roleId', 'authId'];
    public const CREATED_AT = null;
    public const UPDATED_AT = null;

    public const COLUMNS = [
        'roleId' => ['role_id', '角色编号', 'int'],
        'authId' => ['auth_id', '权限编号', 'int'],
    ];
}
