<?php
namespace Ypf\Utils;

use Exception;
use Throwable;

/**
 * scrypt 密码哈希工具类（兼容 AdonisJS/Node 的 scrypt 输出）
 */
class ScryptUtil
{
    private static int $cost = 16384;
    private static int $blockSize = 8;
    private static int $parallelization = 1;
    private static int $saltSize = 16;
    private static int $keyLength = 64;
    private static int $raw = 1;

    public static function initScrypt(
        int $cost = 16384,
        int $blockSize = 8,
        int $parallelization = 1,
        int $saltSize = 16,
        int $keyLength = 64,
        int $raw = 1)
    {
        self::$cost = $cost;
        self::$blockSize = $blockSize;
        self::$parallelization = $parallelization;
        self::$saltSize = $saltSize;
        self::$keyLength = $keyLength;
        self::$raw = $raw;
    }

    /**
     * 生成 PHC 格式密码哈希
     */
    public static function make(string $str): string
    {
        $salt = random_bytes(self::$saltSize);
        $hash = self::hash($str, $salt, self::$cost, self::$blockSize, self::$parallelization, self::$keyLength);

        return sprintf(
            '$scrypt$n=%d,r=%d,p=%d$%s$%s',
            self::$cost,
            self::$blockSize,
            self::$parallelization,
            self::base64EncodeNoPadding($salt),
            self::base64EncodeNoPadding($hash)
        );
    }

    /**
     * 验证 PHC 格式密码哈希
     */
    public static function verify(string $hashedValue, string $str): bool
    {
        if (!preg_match('/^\$scrypt\$n=(\d+),r=(\d+),p=(\d+)\$([^$]+)\$([^$]+)$/', $hashedValue, $m)) {
            return false;
        }

        $cost = (int) $m[1];
        $r = (int) $m[2];
        $p = (int) $m[3];
        $salt = self::base64DecodeNoPadding($m[4]);
        $storedHash = self::base64DecodeNoPadding($m[5]);

        if ($salt === false || $storedHash === false) {
            return false;
        }

        try {
            $computed = self::hash($str, $salt, $cost, $r, $p, strlen($storedHash));
        } catch (Throwable $e) {
            return false;
        }

        return hash_equals($computed, $storedHash);
    }

    /**
     * 是否需要重新哈希
     */
    public static function needsReHash(string $hashedValue): bool
    {
        if (!preg_match('/^\$scrypt\$n=(\d+),r=(\d+),p=(\d+)\$/', $hashedValue, $m)) {
            return true;
        }

        return (int) $m[1] !== self::$cost
            || (int) $m[2] !== self::$blockSize
            || (int) $m[3] !== self::$parallelization;
    }

    /**
     * scrypt 核心入口：有扩展用扩展，没有则用纯 PHP
     */
    public static function hash(
        string $str,
        string $salt,
        int $N,
        int $r,
        int $p,
        int $keyLength
    ): string {
        if (function_exists('scrypt')) {
            return self::hashWithExtension($str, $salt, $N, $r, $p, $keyLength);
        }

        return self::hashWithPurePhp($str, $salt, $N, $r, $p, $keyLength);
    }

    /**
     * 使用 PECL scrypt 扩展计算
     */
    private static function hashWithExtension(
        string $str,
        string $salt,
        int $N,
        int $r,
        int $p,
        int $keyLength
    ): string {
        // 尝试直接获取二进制输出
        $output = @scrypt($str, $salt, $N, $r, $p, $keyLength, self::$raw);

        if ($output !== false && strlen($output) === $keyLength) {
            return $output;
        }

        // 降级为 hex 输出并转换
        $output = scrypt($str, $salt, $N, $r, $p, $keyLength);
        if (strlen($output) === $keyLength * 2 && ctype_xdigit($output)) {
            return hex2bin($output);
        }

        // 假设已经是二进制
        return $output;
    }

    /**
     * 纯 PHP scrypt 实现（扩展不可用时降级）
     *
     * 警告：性能很差！N=16384 时可能需要数秒到数十秒。
     */
    private static function hashWithPurePhp(
        string $str,
        string $salt,
        int $N,
        int $r,
        int $p,
        int $dkLen
    ): string {
        if ($N <= 1 || ($N & ($N - 1)) !== 0) {
            throw new Exception('N 必须是 2 的幂且大于 1');
        }
        if ($r <= 0 || $p <= 0 || $dkLen <= 0) {
            throw new Exception('r、p、dkLen 必须为正整数');
        }

        // 第一步：PBKDF2-HMAC-SHA256 派生初始块
        $B = hash_pbkdf2('sha256', $str, $salt, 1, $p * $r * 128, true);

        // 第二步：ROMix
        $XY = '';
        for ($i = 0; $i < $p; $i++) {
            $XY .= self::scryptROMix(substr($B, $i * $r * 128, $r * 128), $r, $N);
        }

        // 第三步：最终 PBKDF2-HMAC-SHA256
        return hash_pbkdf2('sha256', $str, $XY, 1, $dkLen, true);
    }

    private static function scryptROMix(string $B, int $r, int $N): string
    {
        $X = $B;
        $V = [];

        for ($i = 0; $i < $N; $i++) {
            $V[$i] = $X;
            $X = self::scryptBlockMix($X, $r);
        }

        for ($i = 0; $i < $N; $i++) {
            $j = self::integerify($X, $r) % $N;
            $X = self::scryptBlockMix(self::xorBytes($X, $V[$j]), $r);
        }

        return $X;
    }

    private static function scryptBlockMix(string $B, int $r): string
    {
        $X = substr($B, (2 * $r - 1) * 64, 64);
        $Y = '';

        for ($i = 0; $i < 2 * $r; $i++) {
            $X = self::salsa208Core(self::xorBytes($X, substr($B, $i * 64, 64)));
            $Y .= $X;
        }

        $output = '';
        for ($i = 0; $i < $r; $i++) {
            $output .= substr($Y, $i * 2 * 64, 64);
        }
        for ($i = 0; $i < $r; $i++) {
            $output .= substr($Y, ($i * 2 + 1) * 64, 64);
        }

        return $output;
    }

    private static function salsa208Core(string $input): string
    {
        $x = array_values(unpack('V*', $input));
        $in = $x;

        // Salsa20/8 共 8 轮 = 4 次（Column round + Row round），对应 RFC 7914 的 for (i = 8; i > 0; i -= 2)
        for ($i = 0; $i < 4; $i++) {
            // Column round
            $x[ 4] ^= self::rotL(($x[ 0] + $x[12]) & 0xffffffff, 7);
            $x[ 8] ^= self::rotL(($x[ 4] + $x[ 0]) & 0xffffffff, 9);
            $x[12] ^= self::rotL(($x[ 8] + $x[ 4]) & 0xffffffff, 13);
            $x[ 0] ^= self::rotL(($x[12] + $x[ 8]) & 0xffffffff, 18);

            $x[ 9] ^= self::rotL(($x[ 5] + $x[ 1]) & 0xffffffff, 7);
            $x[13] ^= self::rotL(($x[ 9] + $x[ 5]) & 0xffffffff, 9);
            $x[ 1] ^= self::rotL(($x[13] + $x[ 9]) & 0xffffffff, 13);
            $x[ 5] ^= self::rotL(($x[ 1] + $x[13]) & 0xffffffff, 18);

            $x[14] ^= self::rotL(($x[10] + $x[ 6]) & 0xffffffff, 7);
            $x[ 2] ^= self::rotL(($x[14] + $x[10]) & 0xffffffff, 9);
            $x[ 6] ^= self::rotL(($x[ 2] + $x[14]) & 0xffffffff, 13);
            $x[10] ^= self::rotL(($x[ 6] + $x[ 2]) & 0xffffffff, 18);

            $x[ 3] ^= self::rotL(($x[15] + $x[11]) & 0xffffffff, 7);
            $x[ 7] ^= self::rotL(($x[ 3] + $x[15]) & 0xffffffff, 9);
            $x[11] ^= self::rotL(($x[ 7] + $x[ 3]) & 0xffffffff, 13);
            $x[15] ^= self::rotL(($x[11] + $x[ 7]) & 0xffffffff, 18);

            // Row round
            $x[ 1] ^= self::rotL(($x[ 0] + $x[ 3]) & 0xffffffff, 7);
            $x[ 2] ^= self::rotL(($x[ 1] + $x[ 0]) & 0xffffffff, 9);
            $x[ 3] ^= self::rotL(($x[ 2] + $x[ 1]) & 0xffffffff, 13);
            $x[ 0] ^= self::rotL(($x[ 3] + $x[ 2]) & 0xffffffff, 18);

            $x[ 6] ^= self::rotL(($x[ 5] + $x[ 4]) & 0xffffffff, 7);
            $x[ 7] ^= self::rotL(($x[ 6] + $x[ 5]) & 0xffffffff, 9);
            $x[ 4] ^= self::rotL(($x[ 7] + $x[ 6]) & 0xffffffff, 13);
            $x[ 5] ^= self::rotL(($x[ 4] + $x[ 7]) & 0xffffffff, 18);

            $x[11] ^= self::rotL(($x[10] + $x[ 9]) & 0xffffffff, 7);
            $x[ 8] ^= self::rotL(($x[11] + $x[10]) & 0xffffffff, 9);
            $x[ 9] ^= self::rotL(($x[ 8] + $x[11]) & 0xffffffff, 13);
            $x[10] ^= self::rotL(($x[ 9] + $x[ 8]) & 0xffffffff, 18);

            $x[12] ^= self::rotL(($x[15] + $x[14]) & 0xffffffff, 7);
            $x[13] ^= self::rotL(($x[12] + $x[15]) & 0xffffffff, 9);
            $x[14] ^= self::rotL(($x[13] + $x[12]) & 0xffffffff, 13);
            $x[15] ^= self::rotL(($x[14] + $x[13]) & 0xffffffff, 18);
        }

        for ($i = 0; $i < 16; $i++) {
            $x[$i] = ($x[$i] + $in[$i]) & 0xffffffff;
        }

        return pack('V*', ...$x);
    }

    private static function rotL(int $x, int $n): int
    {
        return ((($x << $n) | ($x >> (32 - $n))) & 0xffffffff);
    }

    /**
     * Integerify：取 X 最后 64 字节块的低 32 位无符号整数
     */
    private static function integerify(string $X, int $r): int
    {
        $lastBlock = substr($X, (2 * $r - 1) * 64, 64);
        $value = unpack('V', substr($lastBlock, 0, 4))[1];

        // PHP unpack('V') 返回有符号整数，转成无符号
        if ($value < 0) {
            $value += 4294967296;
        }

        return $value;
    }

    private static function xorBytes(string $a, string $b): string
    {
        $len = strlen($a);
        $result = '';
        for ($i = 0; $i < $len; $i++) {
            $result .= $a[$i] ^ $b[$i];
        }
        return $result;
    }

    private static function base64EncodeNoPadding(string $data): string
    {
        return rtrim(base64_encode($data), '=');
    }

    private static function base64DecodeNoPadding(string $data): string|false
    {
        $padding = (4 - (strlen($data) % 4)) % 4;
        return base64_decode($data . str_repeat('=', $padding), true);
    }
}
