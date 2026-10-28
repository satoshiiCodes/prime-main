<?php
/**
 * Prime City - Database configuration.
 *
 * Reads the connection settings from the project root `.env` file and exposes
 * one reusable MySQLi connection for the dashboard module.
 *
 * Usage:
 *     require_once __DIR__ . '/../config/database.php';
 *     $conn = db();
 */

/**
 * Absolute path of the project root (the folder that holds `.env`).
 *
 * @return string
 */
function env_root_path()
{
    return dirname(__DIR__);
}

/**
 * Parse a `.env` file into a key => value map.
 *
 * Supported syntax: blank lines, `#` comments, `export KEY=value`,
 * single/double quoted values and trailing `#` comments on bare values.
 *
 * @param  string $path Absolute path of the file to parse.
 * @return array<string, string>
 */
function env_parse_file($path)
{
    $values = array();

    if (!is_string($path) || !is_file($path) || !is_readable($path)) {
        return $values;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return $values;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#') {
            continue;
        }

        // Allow "export KEY=value" copies straight from a shell.
        if (stripos($line, 'export ') === 0) {
            $line = trim(substr($line, 7));
        }

        $position = strpos($line, '=');
        if ($position === false) {
            continue;
        }

        $key = trim(substr($line, 0, $position));
        if ($key === '') {
            continue;
        }

        $value = trim(substr($line, $position + 1));
        $length = strlen($value);

        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);

                if ($first === '"') {
                    $value = str_replace(
                        array('\\n', '\\r', '\\t', '\\"', '\\\\'),
                        array("\n", "\r", "\t", '"', '\\'),
                        $value
                    );
                }

                $values[$key] = $value;
                continue;
            }
        }

        // Bare value: strip an inline comment.
        $hash = strpos($value, ' #');
        if ($hash !== false) {
            $value = rtrim(substr($value, 0, $hash));
        }

        $values[$key] = $value;
    }

    return $values;
}

/**
 * All environment values, loaded once per request.
 *
 * Real process environment variables win over the file so that deployments can
 * override any value without editing `.env`.
 *
 * @return array<string, string>
 */
function env_all()
{
    static $loaded = null;

    if ($loaded !== null) {
        return $loaded;
    }

    $loaded = env_parse_file(env_root_path() . DIRECTORY_SEPARATOR . '.env');

    if (!$loaded) {
        $loaded = env_parse_file(env_root_path() . DIRECTORY_SEPARATOR . '.env.example');
    }

    foreach ($loaded as $key => $value) {
        $runtime = getenv($key);
        if ($runtime !== false && $runtime !== '') {
            $loaded[$key] = $runtime;
        }
    }

    return $loaded;
}

/**
 * Read a single environment value.
 *
 * @param  string      $key     Variable name, e.g. `DB_HOST`.
 * @param  string|null $default Fallback when the variable is missing/empty.
 * @return string|null
 */
function env($key, $default = null)
{
    $values = env_all();

    if (!array_key_exists($key, $values)) {
        return $default;
    }

    $value = $values[$key];

    return $value === '' ? $default : $value;
}

/**
 * Read an environment value as a boolean.
 *
 * @param  string $key     Variable name.
 * @param  bool   $default Fallback value.
 * @return bool
 */
function env_bool($key, $default = false)
{
    $value = env($key);

    if ($value === null) {
        return $default;
    }

    return in_array(strtolower(trim($value)), array('1', 'true', 'yes', 'on'), true);
}

/**
 * Normalised database settings.
 *
 * @return array<string, string|int>
 */
function db_config()
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $port = (int) env('DB_PORT', '3306');

    $config = array(
        'driver'   => env('DB_CONNECTION', 'mysql'),
        'host'     => env('DB_HOST', 'localhost'),
        'port'     => $port > 0 ? $port : 3306,
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'database' => env('DB_DATABASE', 'db_prime_city'),
        'charset'  => env('DB_CHARSET', 'utf8mb4'),
    );

    return $config;
}

/**
 * Shared MySQLi connection.
 *
 * @param  bool $reconnect Drop the cached handle and open a fresh one.
 * @return mysqli
 */
function db($reconnect = false)
{
    static $connection = null;

    if ($reconnect === true) {
        if ($connection instanceof mysqli) {
            @$connection->close();
        }
        $connection = null;
    }

    if ($connection instanceof mysqli) {
        return $connection;
    }

    $timezone = env('APP_TIMEZONE');
    if ($timezone !== null && in_array($timezone, timezone_identifiers_list(), true)) {
        date_default_timezone_set($timezone);
    }

    // Let MySQLi raise exceptions instead of returning false on failure.
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $config = db_config();

    try {
        $connection = new mysqli(
            $config['host'],
            $config['username'],
            $config['password'],
            $config['database'],
            $config['port']
        );
    } catch (mysqli_sql_exception $e) {
        throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 500, $e);
    }

    $connection->set_charset($config['charset']);

    return $connection;
}

/**
 * Shared PDO connection (used by the customer module).
 *
 * @param  bool $reconnect Drop the cached handle and open a fresh one.
 * @return PDO
 */
function db_pdo($reconnect = false)
{
    static $pdo = null;

    if ($reconnect === true) {
        $pdo = null;
    }

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $timezone = env('APP_TIMEZONE');
    if ($timezone !== null && in_array($timezone, timezone_identifiers_list(), true)) {
        date_default_timezone_set($timezone);
    }

    $config = db_config();

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['host'],
        $config['port'],
        $config['database'],
        $config['charset']
    );

    try {
        $pdo = new PDO($dsn, $config['username'], $config['password'], array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ));
    } catch (PDOException $e) {
        throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 500, $e);
    }

    return $pdo;
}
