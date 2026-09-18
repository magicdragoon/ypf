<?php
namespace App\Model;

use Model;

/**
 * 角色
 * @property int $id 编号
 * @property string $name 名称
 */
class Role extends Model
{
    public const TABLE = 'roles';
    public const CREATED_AT = null;
    public const UPDATED_AT = null;

    public const COLUMNS = [
        'id' => ['id', '编号', 'int'],
        'name' => ['name', '名称', 'string'],
    ];
}
