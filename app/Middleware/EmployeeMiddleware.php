<?php
namespace App\Middleware;

use App\Model\EmployeeToken;
use App\Model\Employee;
use AppException;
use DB;
use App\Providers\TokenProvider;
use AuthMiddleware;
use Context;
use Hash;
use Time;

/**
 * 员工中间件
 */
class EmployeeMiddleware extends AuthMiddleware
{
    /**
     * 检查认证
     * @param int $apiId
     * @return bool
     */
    public function checkAuth($apiId)
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
            throw new AppException(7001010001, 'Access denied!');
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
            throw new AppException(7001020001, 'Access denied!');
        }

        $provider = new TokenProvider();
        [$tokenId, $secret] = $provider->parseToken($token);
        if (empty($tokenId)) {
            throw new AppException(7001020002, 'Access denied!');
        }

        $employeeToken = EmployeeToken::find($tokenId);
        if (empty($employeeToken) || empty($employeeToken->token) || !$provider->verifyToken($employeeToken->token, $secret)) {
            throw new AppException(7001020003, 'Access denied!');
        }

        $employee = Employee::find($employeeToken->accountId);
        if (empty($employee)) {
            throw new AppException(7001020004, 'Access denied!');
        }
        Context::set('employee', $employee);

        $this->roles = $employee->roles;
        $this->attrs = [
            'merchant_ids' => $employee->merchantIds,
            'focus_merchant_ids' => $employee->focusMerchantIds,
        ];
    }

    /**
     * 退出登录
     * @return void
     */
    public function logout()
    {
        EmployeeToken::find($this->tokenId)->delete();
    }

    /**
     * 获取令牌
     * @param int $id
     * @param int $expiresIn
     * @return array
     */
    public function getToken($id, $expiresIn = 86400)
    {

        $provider = new TokenProvider();
        $token = $provider->createToken($id, ['*'], $expiresIn);

        $accessToken = new EmployeeToken();
        $accessToken->employeeId = $token['identifier'];
        $accessToken->token = $token['hash'];
        $accessToken->expiresAt = new Time($token['expires_at']);
        $accessToken->save();

        return [
            'type' => 'bearer',
            'token' => $token['value'],
            'expiresAt' => $accessToken->expiresAt->toString(),
        ];
    }

    /**
     * 获取用户
     * @return Employee
     */
    public function getUser()
    {
        return Context::get('employee');
    }

    /**
     * 生成密码哈希
     * @param string $password
     * @return string
     */
    public function makePassword($password)
    {
        return Hash::make($password);
    }
    /**
     * 验证密码哈希
     * @param string $password
     * @param string $hashPassword
     * @return bool
     */
    public function verifyPassword($password, $hashPassword)
    {
        return Hash::verify($password, $hashPassword);
    }
}
