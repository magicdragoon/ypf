<?php
namespace App\Middleware;

use App\Model\EmployeeToken;
use App\Model\Employee;
use AppException;
use Consts;
use DB;
use App\Providers\TokenProvider;
use YpfAuth;

/**
 * 员工中间件
 */
class EmployeeMiddleware extends YpfAuth
{
    public function checkAuth(int $apiId) : bool
    {
        // 超管有所有权限
        if (in_array(0, $this->roles)) {
            return true;
        }
        $auth = DB::queryOne('SELECT * FROM `auths` WHERE `id` = ?', [$apiId]);
        if (empty($auth)) {
            return true;
        }
        $permission = DB::queryOne('SELECT * FROM `role_auths` WHERE `auth_id` = ? AND `role_id` IN (' . join(',', $this->roles) . ')', [$apiId]);
        if (empty($permission)) {
            throw new AppException(Consts::CODE_ACCESS_DENIED, 'Access denied!');
        }
        return true;
    }

    public function checkLogin()
    {
        $token = $this->request->header('auth');
        if (!empty($token)) {
            $token = substr($token, 7);
        } else {
            $token = $this->request->get('token');
        }
        if (empty($token)) {
            throw new AppException(Consts::CODE_ACCESS_DENIED, 'Access denied!');
        }

        $provider = new TokenProvider();
        [$tokenId, $secret] = $provider->parseToken($token);
        if (empty($tokenId)) {
            throw new AppException(Consts::CODE_ACCESS_DENIED, 'Access denied!');
        }

        $employeeToken = EmployeeToken::find($tokenId);
        if (empty($employeeToken) || empty($employeeToken->token) || !$provider->verifyToken($employeeToken->token, $secret)) {
            throw new AppException(Consts::CODE_ACCESS_DENIED, 'Access denied!');
        }

        $employee = Employee::find($employeeToken->accountId);
        if (empty($employee)) {
            throw new AppException(Consts::CODE_ACCESS_DENIED, 'Access denied!');
        }

        $this->roles = $employee->roles;
        $this->attrs = [
            'merchant_ids' => $employee->merchantIds,
            'focus_merchant_ids' => $employee->focusMerchantIds,
        ];
    }
}
