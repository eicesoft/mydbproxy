<?php
namespace Vary\Helper;

use Vary\Util\Charset;

const INT_MAX_SIZE = 2147483647;

/**
 * 获取bytes 数组.
 *
 * @param $data
 *
 * @return array
 */
function getBytes($data)
{
    $bytes = [];
    $count = strlen($data);
    for ($i = 0; $i < $count; ++$i) {
        $byte = ord($data[$i]);
        $bytes[] = $byte;
    }

    return $bytes;
}

/**
 * 无符号16位右移.
 *
 * @param int $x 要进行操作的数字
 * @param int $bits 右移位数
 * @return int
 */
function shr16($x, $bits)
{
    return ((INT_MAX_SIZE >> ($bits - 1)) & ($x >> $bits)) > 255 ? 255 : ((INT_MAX_SIZE >> ($bits - 1)) & ($x >> $bits));
}

/**
 * 数组复制.
 *
 * @param $array
 * @param $start
 * @param $len
 *
 * @return array
 */
function array_copy(array $array, int $start, int $len)
{
    return array_slice($array, $start, $len);
}

/**
 * 获取 string.
 *
 * @param array $bytes
 *
 * @return string
 */
function getString(array $bytes)
{
    return implode(array_map('chr', $bytes));
}

/**
 * 转换长度.
 *
 * @param int $size
 * @param int $length
 *
 * @return array
 */
function getMysqlPackSize(int $size, int $length = 3)
{
    $sizeData[] = $size & 0xff;
    $sizeData[] = shr16($size & 0xff << 8, 8);
    $sizeData[] = shr16($size & 0xff << 16, 16);
    if ($length > 3) {
        $sizeData[] = shr16($size & 0xff << 24, 24);
    }
    return $sizeData;
}

/**
 * 获取包长
 *
 * @param string $data
 * @param int    $step
 * @param int    $offset
 *
 * @return int
 */
function getPackageLength(string $data, int $step, int $offset)
{
    $i = ord($data[$step]);
    $i |= ord($data[$step + 1]) << 8;
    $i |= ord($data[$step + 2]) << 16;
    if ($offset >= 4) {
        $i |= ord($data[$step + 3]) << 24;
    }

    return $i + $offset;
}

/**
 * 对数据进行编码转换.
 *
 * @param array/string $data   数组
 * @param string $output 转换后的编码
 *
 * @return array|null|string|string[]
 */
function arrayIconv($data, string $output = 'utf-8')
{
    $output = Charset::charsetToEncoding($output);
    $encode_arr = ['UTF-8', 'ASCII', 'GBK', 'GB2312', 'BIG5', 'JIS', 'eucjp-win', 'sjis-win', 'EUC-JP'];
    $encoded = mb_detect_encoding($data, $encode_arr);

    if (!is_array($data)) {
        return mb_convert_encoding($data, $output, $encoded);
    } else {
        foreach ($data as $key => $val) {
            $key = arrayIconv($key, $output);
            if (is_array($val)) {
                $data[$key] = arrayIconv($val, $output);
            } else {
                $data[$key] = mb_convert_encoding($data, $output, $encoded);
            }
        }

        return $data;
    }
}

/**
 * 获取包长配置
 *
 * @return array
 */
function packageLengthSetting()
{
    $package_length_func = function ($data) {
        if (strlen($data) < 4) {
            return 0;
        }
        $length = ord($data[0]) | (ord($data[1]) << 8) | (ord($data[2]) << 16);
        if ($length <= 0) {
            return -1;
        }
        return $length + 4;
    };
    return [
        'open_length_check'   => true,
        'package_length_func' => $package_length_func,
    ];
}