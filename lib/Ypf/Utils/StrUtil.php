<?php
namespace Ypf\Utils;

class StrUtil
{
    /**
     * 将 snake 命名法转换为驼峰命名法
     */
    public static function snakeToCamel(string $string, bool $ucfirst = true): string
    {
        return self::lowerToCamel($string, '_', $ucfirst);
    }

    /**
     * 将 kebab 命名法转换为驼峰命名法
     */
    public static function kebabToCamel(string $string, bool $ucfirst = true): string
    {
        return self::lowerToCamel($string, '-', $ucfirst);
    }

    /**
     * 将包含分隔符的字符串转换为驼峰命名法
     */
    public static function lowerToCamel(string $string, string $separator, bool $ucfirst = true): string
    {
        return self::arrayToCamel(explode($separator, strtolower($string)), $ucfirst);
    }

    /**
     * 将 snake 命名法转换为驼峰命名法（单数）
     */
    public static function snakeToCamelSingular(string $string, bool $ucfirst = true): string
    {
        $words = explode('_', strtolower($string));
        $lastWord = array_pop($words);
        $lastWord = self::pluralToSingular($lastWord);
        $words[] = $lastWord;
        return self::arrayToCamel($words, $ucfirst);
    }

    /**
     * 将驼峰命名法转换为 snake 命名法
     */
    public static function camelToSnake(string $str) : string
    {
        return self::camelToLower($str, '_');
    }

    /**
     * 将驼峰命名法转换为 kebab 命名法
     */
    public static function camelToKebab(string $str) : string
    {
        return self::camelToLower($str, '-');
    }

    /**
     * 将驼峰命名法转换为字符分割的字符串
     */
    public static function camelToLower(string $str, string $separator = '_') : string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1' . $separator . '$2', $str));
    }

    /**
     * 简易版英语复数转单数
     */
    public static function pluralToSingular(string $word): string
    {
        // 不可数名词（单复数同形）
        $uncountable = ['equipment', 'information', 'money', 'species', 'series', 'fish', 'sheep', 'data', 'news'];
        if (in_array(strtolower($word), $uncountable)) {
            return $word;
        }

        // 英语单复数正则替换规则 (注意顺序：从最特殊到最普通)
        $rules = [
            '/(quiz)zes$/i' => '$1',
            '/(matr)ices$/i' => '$1ix',
            '/(vert|ind)ices$/i' => '$1ex',
            '/^(ox)en$/i' => '$1',
            '/(alias|status)es$/i' => '$1',
            '/([octop|vir])i$/i' => '$1us',
            '/(cris|ax|test)es$/i' => '$1is',
            '/(shoe)s$/i' => '$1',
            '/(o)es$/i' => '$1',
            '/(bus)es$/i' => '$1',
            '/([m|l])ice$/i' => '$1ouse',
            '/(x|ch|ss|sh)es$/i' => '$1',
            '/(m)ovies$/i' => '$1ovie',
            '/(s)eries$/i' => '$1eries',
            '/([^aeiouy]|qu)ies$/i' => '$1y', // 例如: categories -> category
            '/([lr])ves$/i' => '$1f',         // 例如: halves -> half
            '/(tive)s$/i' => '$1',
            '/(hive)s$/i' => '$1',
            '/([^fo])ves$/i' => '$1fe',        // 例如: wives -> wife
            '/(us)es$/i' => '$1',
            '/s$/i' => '',                    // 最普通的去 s
        ];

        foreach ($rules as $rule => $replacement) {
            if (preg_match($rule, $word)) {
                return preg_replace($rule, $replacement, $word);
            }
        }

        return $word;
    }

    /**
     * 将数组转换为驼峰命名法
     */
    private static function arrayToCamel(array $arr, bool $ucfirst = true): string
    {
        $str = '';
        foreach ($arr as $k=>$v) {
            if ($k === 0 && !$ucfirst) {
                $str .= $v;
            } else {
                $str .= ucfirst($v);
            }
        }
        return $str;
    }
}