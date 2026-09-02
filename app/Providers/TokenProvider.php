<?php
namespace App\Providers;

use Ypf\Utils\StrUtil;

class TokenProvider
{
    private string $tokenPrefix = 'oat_';
    private int $tokenSecretLength = 40;

    public function initToken(string $prefix = 'oat_', int $len = 40)
    {
        $this->tokenPrefix = $prefix;
        $this->tokenSecretLength = $len;
    }

    /**
     * 计算与 AdonisJS 一致的 CRC32
     * 返回无符号 32 位整数
     */
    private function crc32Unsigned(string $input): int
    {
        $crc = crc32($input);
        if ($crc < 0) {
            $crc += 4294967296;
        }
        return $crc;
    }

    /**
     * 创建 token
     *
     * @return array{identifier: int, secret: string, hash: string, value: string}
     */
    public function createToken(int $identifier, array $abilities = ['*'], ?int $expiresIn = null): array
    {
        $seed = StrUtil::randomString($this->tokenSecretLength);
        $crc = $this->crc32Unsigned($seed);
        $secret = $seed . $crc;
        $hash = hash('sha256', $secret);

        return [
            'identifier' => $identifier,
            'secret' => $secret,
            'hash' => $hash,
            'value' => $this->tokenPrefix
                . StrUtil::urlEncode((string) $identifier)
                . '.'
                . StrUtil::urlEncode($secret),
            'abilities' => $abilities,
            'expires_at' => $expiresIn ? time() + $expiresIn : null,
        ];
    }

    public function parseToken(string $token)
    {
        if (!str_starts_with($token, $this->tokenPrefix)) {
            return false;
        }

        $token = substr($token, strlen($this->tokenPrefix));
        $parts = explode('.', $token);

        if (count($parts) !== 2) {
            return false;
        }

        $identifier = StrUtil::urlDecode($parts[0]);
        $secret = StrUtil::urlDecode($parts[1]);

        if ($identifier === false || $secret === false) {
            return false;
        }

        $newHash = hash('sha256', $secret);

        return [$identifier, $newHash];
    }

    /**
     * 验证客户端传入的 token value 是否与数据库中存储的 hash 匹配
     */
    public function verifyToken(string $newHash, string $storedHash): bool
    {
        // hash_equals 防时序攻击
        return hash_equals($storedHash, $newHash);
    }
}
