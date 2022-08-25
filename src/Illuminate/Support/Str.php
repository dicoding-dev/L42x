<?php namespace Illuminate\Support;

use Illuminate\Support\Traits\MacroableTrait;
use RuntimeException;
use voku\helper\ASCII;

class Str
{

    use MacroableTrait;

    /**
     * The cache of snake-cased words.
     *
     * @var array
     */
	protected static array $snakeCache = [];

	/**
	 * The cache of camel-cased words.
	 *
	 * @var array
	 */
	protected static array $camelCache = [];

	/**
	 * The cache of studly-cased words.
	 *
	 * @var array
	 */
	protected static array $studlyCache = [];

	/**
	 * Transliterate a UTF-8 value to ASCII.
	 *
	 * @param string $value
	 *
	 * @return string
	 */
	public static function ascii(string $value): string
    {
        return ASCII::to_ascii($value);
    }

	/**
	 * Convert a value to camel case.
	 *
	 * @param string $value
	 *
	 * @return string
	 */
	public static function camel(string $value): string
    {
		if (isset(static::$camelCache[$value]))
		{
			return static::$camelCache[$value];
		}

		return static::$camelCache[$value] = lcfirst(static::studly($value));
	}

	/**
	 * Determine if a given string contains a given substring.
	 *
	 * @param string       $haystack
	 * @param array|string $needles
	 *
	 * @return bool
	 */
	public static function contains(string $haystack, array|string $needles): bool
    {
		foreach ((array) $needles as $needle)
		{
			if (!empty($needle) && str_contains($haystack, (string)$needle)) {
                return true;
            }
		}

		return false;
	}

	/**
	 * Determine if a given string ends with a given substring.
	 *
	 * @param string       $haystack
	 * @param array|string $needles
	 *
	 * @return bool
	 */
	public static function endsWith(string $haystack, array|string $needles): bool
    {
		foreach ((array) $needles as $needle)
		{
			if (!empty($needle) && str_ends_with($haystack, (string)$needle)) {
                return true;
            }
		}

		return false;
	}

	/**
	 * Cap a string with a single instance of a given value.
	 *
	 * @param string $value
	 * @param string $cap
	 *
	 * @return string
	 */
	public static function finish(string $value, string $cap): string
    {
		$quoted = preg_quote($cap, '/');

		return preg_replace('/(?:'.$quoted.')+$/', '', $value).$cap;
	}

	/**
	 * Determine if a given string matches a given pattern.
	 *
	 * @param string $pattern
	 * @param string $value
	 *
	 * @return bool
	 */
	public static function is(string $pattern, string $value): bool
    {
		if ($pattern == $value) {
            return true;
        }

		$pattern = preg_quote($pattern, '#');

		// Asterisks are translated into zero-or-more regular expression wildcards
		// to make it convenient to check if the strings starts with the given
		// pattern such as "library/*", making any string check convenient.
		$pattern = str_replace('\*', '.*', $pattern).'\z';

		return (bool) preg_match('#^'.$pattern.'#', $value);
	}

	/**
	 * Return the length of the given string.
	 *
	 * @param string $value
	 *
	 * @return int
	 */
	public static function length(string $value): int
    {
		return mb_strlen($value);
	}

	/**
	 * Limit the number of characters in a string.
	 *
	 * @param string $value
	 * @param int    $limit
	 * @param string $end
	 *
	 * @return string
	 */
	public static function limit(string $value, int $limit = 100, string $end = '...'): string
    {
		if (mb_strlen($value) <= $limit) return $value;

		return rtrim(mb_substr($value, 0, $limit, 'UTF-8')).$end;
	}

	/**
	 * Convert the given string to lower-case.
	 *
	 * @param string $value
	 *
	 * @return string
	 */
	public static function lower(?string $value = null): string
    {
		return mb_strtolower($value ?? '');
	}

	/**
	 * Limit the number of words in a string.
	 *
	 * @param string $value
	 * @param int    $words
	 * @param string $end
	 *
	 * @return string
	 */
	public static function words(string $value, int $words = 100, string $end = '...'): string
    {
		preg_match('/^\s*+(?:\S++\s*+){1,'.$words.'}/u', $value, $matches);

		if ( ! isset($matches[0]) || strlen($value) === strlen((string) $matches[0])) return $value;

		return rtrim((string) $matches[0]).$end;
	}

	/**
	 * Parse a Class@method style callback into class and method.
	 *
	 * @param string $callback
	 * @param string $default
	 *
	 * @return array
	 */
	public static function parseCallback(string $callback, string $default): array
    {
		return static::contains($callback, '@') ? explode('@', $callback, 2) : array($callback, $default);
	}

	/**
	 * Get the plural form of an English word.
	 *
	 * @param string $value
	 * @param int    $count
	 *
	 * @return string
	 */
	public static function plural(string $value, int $count = 2): string
    {
		return Pluralizer::plural($value, $count);
	}

    /**
     * Generate a more truly "random" alpha-numeric string.
     *
     * @param int $length
     *
     * @return string
     *
     * @throws RuntimeException
     * @throws \Exception
     */
	public static function random(int $length = 16): string
    {
        $string = '';

        while (($len = strlen($string)) < $length) {
            $size = $length - $len;

            $bytes = random_bytes($size);

            $string .= substr(str_replace(['/', '+', '='], '', base64_encode($bytes)), 0, $size);
        }

        return $string;
	}

	/**
	 * Generate a "random" alpha-numeric string.
	 *
	 * Should not be considered sufficient for cryptography, etc.
	 *
	 * @param int $length
	 *
	 * @return string
	 */
	public static function quickRandom(int $length = 16): string
    {
		$pool = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

		return substr(str_shuffle(str_repeat($pool, $length)), 0, $length);
	}

	/**
	 * Convert the given string to upper-case.
	 *
	 * @param string $value
	 *
	 * @return string
	 */
	public static function upper(string $value): string
    {
		return mb_strtoupper($value);
	}

	/**
	 * Convert the given string to title case.
	 *
	 * @param string $value
	 *
	 * @return string
	 */
	public static function title(string $value): string
    {
		return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
	}

	/**
	 * Get the singular form of an English word.
	 *
	 * @param string $value
	 *
	 * @return string
	 */
	public static function singular(string $value): string
    {
		return Pluralizer::singular($value);
	}

	/**
	 * Generate a URL friendly "slug" from a given string.
	 *
	 * @param string $title
	 * @param string $separator
	 *
	 * @return string
	 */
	public static function slug(string $title, string $separator = '-'): string
    {
		$title = static::ascii($title);

		// Convert all dashes/underscores into separator
		$flip = $separator == '-' ? '_' : '-';

		$title = preg_replace('!['.preg_quote($flip).']+!u', $separator, $title);

		// Remove all characters that are not the separator, letters, numbers, or whitespace.
		$title = preg_replace('![^'.preg_quote($separator).'\pL\pN\s]+!u', '', mb_strtolower($title));

		// Replace all separator characters and whitespace by a single separator
		$title = preg_replace('!['.preg_quote($separator).'\s]+!u', $separator, $title);

		return trim($title, $separator);
	}

	/**
	 * Convert a string to snake case.
	 *
	 * @param string $value
	 * @param string $delimiter
	 *
	 * @return string
	 */
	public static function snake(string $value, string $delimiter = '_'): string
    {
		$key = $value.$delimiter;

		if (isset(static::$snakeCache[$key]))
		{
			return static::$snakeCache[$key];
		}

		if ( ! ctype_lower($value))
		{
			$replace = '$1'.$delimiter.'$2';

			$value = strtolower(preg_replace('/(.)([A-Z])/', $replace, $value));
		}

		return static::$snakeCache[$key] = $value;
	}

	/**
	 * Determine if a given string starts with a given substring.
	 *
	 * @param string       $haystack
	 * @param array|string $needles
	 *
	 * @return bool
	 */
	public static function startsWith(string $haystack, array|string $needles): bool
    {
		foreach ((array) $needles as $needle)
		{
			if (!empty($needle) && str_starts_with($haystack, (string)$needle)) {
                return true;
            }
		}

		return false;
	}

	/**
	 * Convert a value to studly caps case.
	 *
	 * @param string $value
	 *
	 * @return string
	 */
	public static function studly(string $value): string
    {
		$key = $value;

		if (isset(static::$studlyCache[$key]))
		{
			return static::$studlyCache[$key];
		}

		$value = ucwords(str_replace(array('-', '_'), ' ', $value));

		return static::$studlyCache[$key] = str_replace(' ', '', $value);
	}

    public static function numberFormat(
        ?float $num = 0.0,
        ?int $decimals = 0,
        ?string $decimal_separator = ".",
        ?string $thousands_separator = ","
    ): string {
        return number_format($num ?? 0.0, $decimals, $decimal_separator, $thousands_separator);
    }
}
