<?php
namespace App\Model;

use Model;

/**
 * 员工凭证
 * @property int $id 编号
 * @property int $employeeId 员工编号
 * @property string $token 凭证
 * @property \Time $expiredAt 过期时间
 */
class EmployeeToken extends Model
{
    public const TABLE = 'employee_tokens';
    public const CREATED_AT = null;
    public const UPDATED_AT = null;

    public const COLUMNS = [
        'id' => ['id', '编号', 'int'],
        'employeeId' => ['employee_id', '员工编号', 'int'],
        'token' => ['token', '凭证', 'string'],
        'expiredAt' => ['expired_at', '过期时间', 'DateTime'],
    ];
}
