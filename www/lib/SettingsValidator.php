<?php

// lib/SettingsValidator.php

require_once __DIR__ . '/../init.php';

class SettingsValidator
{
    /**
     * Валидировать настройки
     */
    public function validate($data, $groups)
    {
        $errors = [];

        foreach ($groups as $groupKey => $group) {
            foreach ($group['fields'] as $fieldKey => $field) {
                $value = $data[$fieldKey] ?? null;

                // Обязательные поля
                if (isset($field['required']) && $field['required'] && empty($value)) {
                    $errors[] = sprintf(__('validator_field_required'), $field['label']);
                    continue;
                }

                // Проверка по типу
                switch ($field['type'] ?? 'text') {
                    case 'number':
                        if (!empty($value) && !is_numeric($value)) {
                            $errors[] = sprintf(__('validator_field_must_be_number'), $field['label']);
                        } elseif (!empty($value)) {
                            // Проверка min/max для чисел
                            if (isset($field['min']) && $value < $field['min']) {
                                $errors[] = sprintf(__('validator_field_min_value'), $field['label'], $field['min']);
                            }
                            if (isset($field['max']) && $value > $field['max']) {
                                $errors[] = sprintf(__('validator_field_max_value'), $field['label'], $field['max']);
                            }
                        }
                        break;

                    case 'email':
                        if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $errors[] = sprintf(__('validator_field_invalid_email'), $field['label']);
                        }
                        break;

                    case 'url':
                        if (!empty($value) && !filter_var($value, FILTER_VALIDATE_URL)) {
                            $errors[] = sprintf(__('validator_field_invalid_url'), $field['label']);
                        }
                        break;
                }

                // Проверка минимальной длины
                if (isset($field['min_length']) && !empty($value) && strlen($value) < $field['min_length']) {
                    $errors[] = sprintf(__('validator_field_min_length'), $field['label'], $field['min_length']);
                }

                // Проверка максимальной длины
                if (isset($field['max_length']) && !empty($value) && strlen($value) > $field['max_length']) {
                    $errors[] = sprintf(__('validator_field_max_length'), $field['label'], $field['max_length']);
                }

                // Проверка формата (регулярное выражение)
                if (isset($field['pattern']) && !empty($value) && !preg_match($field['pattern'], $value)) {
                    $errors[] = sprintf(__('validator_field_invalid_format'), $field['label']);
                }
            }
        }

        return $errors;
    }

    /**
     * Проверить пути
     */
    public function validatePaths($data)
    {
        $errors = [];

        // Проверка директории с книгами
        if (!empty($data['BOOKS_DIR'])) {
            $booksDir = $data['BOOKS_DIR'];

            if (!is_dir($booksDir)) {
                if (!@mkdir($booksDir, 0755, true)) {
                    $errors[] = sprintf(__('validator_cannot_create_dir'), $booksDir);
                }
            } elseif (!is_writable($booksDir)) {
                $errors[] = sprintf(__('validator_dir_not_writable'), $booksDir);
            } elseif (!is_readable($booksDir)) {
                $errors[] = sprintf(__('validator_dir_not_readable'), $booksDir);
            }
        } else {
            $errors[] = __('validator_books_dir_required');
        }

        // Проверка директории кэша
        if (!empty($data['CACHE_DIR'])) {
            $cacheDir = $data['CACHE_DIR'];

            if (!file_exists($cacheDir)) {
                if (!@mkdir($cacheDir, 0755, true)) {
                    $errors[] = sprintf(__('validator_cannot_create_dir'), $cacheDir);
                }
            } elseif (!is_writable($cacheDir)) {
                $errors[] = sprintf(__('validator_dir_not_writable'), $cacheDir);
            }
        }

        // Проверка директории обложек
        if (!empty($data['COVER_CACHE_DIR'])) {
            $coverDir = $data['COVER_CACHE_DIR'];

            if (!file_exists($coverDir)) {
                if (!@mkdir($coverDir, 0755, true)) {
                    $errors[] = sprintf(__('validator_cannot_create_dir'), $coverDir);
                }
            } elseif (!is_writable($coverDir)) {
                $errors[] = sprintf(__('validator_dir_not_writable'), $coverDir);
            }
        }

        // Проверка пути к сканеру
        if (!empty($data['SCANNER_PATH'])) {
            $scannerPath = $data['SCANNER_PATH'];

            if (!file_exists($scannerPath)) {
                $errors[] = sprintf(__('validator_scanner_not_found'), $scannerPath);
            } elseif (!is_executable($scannerPath)) {
                $errors[] = sprintf(__('validator_scanner_not_executable'), $scannerPath);
            }
        }

        return $errors;
    }

    /**
     * Проверить подключение к БД
     */
    public function testDatabaseConnection($settings)
    {
        try {
            $type = $settings['DB_TYPE'] ?? 'mysql';

            if ($type === 'mysql') {
                $dsn = "mysql:host={$settings['DB_HOST']};charset=utf8mb4";

                // Добавляем порт если указан
                if (!empty($settings['DB_PORT'])) {
                    $dsn .= ";port={$settings['DB_PORT']}";
                }

                $pdo = new PDO($dsn, $settings['DB_USER'], $settings['DB_PASS'] ?? '', [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5
                ]);

                // Проверяем существование базы
                $stmt = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA 
                                      WHERE SCHEMA_NAME = " . $pdo->quote($settings['DB_NAME']));
                $dbExists = $stmt->fetch() !== false;

                return [
                    'success' => true,
                    'message' => __('validator_db_connection_success') . ' ' .
                                ($dbExists ? __('validator_db_exists') : __('validator_db_will_be_created'))
                ];

            } elseif ($type === 'sqlite') {
                $path = $settings['DB_PATH'];

                // Проверяем, является ли путь абсолютным
                if (strpos($path, '/') !== 0 && strpos($path, '\\') !== 0 && !preg_match('/^[A-Za-z]:/', $path)) {
                    // Относительный путь - преобразуем в абсолютный
                    $path = Config::getBasePath() . '/' . ltrim($path, '/');
                }

                $dir = dirname($path);

                if (!file_exists($dir)) {
                    if (!mkdir($dir, 0755, true)) {
                        throw new Exception(sprintf(__('validator_cannot_create_dir'), $dir));
                    }
                }

                if (!is_writable($dir)) {
                    throw new Exception(sprintf(__('validator_dir_not_writable'), $dir));
                }

                // Пробуем создать тестовый файл
                $testFile = $dir . '/test_write_' . uniqid() . '.tmp';
                if (file_put_contents($testFile, 'test') === false) {
                    throw new Exception(sprintf(__('validator_cannot_write'), $dir));
                }
                unlink($testFile);

                // Проверяем возможность открыть/создать базу данных
                $pdo = new PDO("sqlite:$path", null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5
                ]);

                // Проверяем возможность выполнить запрос
                $pdo->exec("CREATE TABLE IF NOT EXISTS _test (id INTEGER)");
                $pdo->exec("DROP TABLE _test");

                return [
                    'success' => true,
                    'message' => __('validator_sqlite_success')
                ];
            }

            return [
                'success' => false,
                'message' => sprintf(__('validator_unsupported_db_type'), $type)
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => __('validator_db_connection_error') . ': ' . $e->getMessage()
            ];
        }
    }

    /**
     * Проверить формат IP адресов
     */
    public function validateIpList($ipList)
    {
        if (empty($ipList)) {
            return true;
        }

        $ips = explode(',', $ipList);
        foreach ($ips as $ip) {
            $ip = trim($ip);
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Проверить, что значение является логическим
     */
    public function validateBoolean($value)
    {
        return in_array($value, ['true', 'false', '1', '0', 'on', 'off', true, false], true);
    }

    /**
     * Санитизация значения
     */
    public function sanitize($value, $type = 'text')
    {
        switch ($type) {
            case 'number':
                return (int)$value;

            case 'boolean':
                return in_array($value, ['true', '1', 'on', true], true);

            case 'email':
                return filter_var($value, FILTER_SANITIZE_EMAIL);

            case 'url':
                return filter_var($value, FILTER_SANITIZE_URL);

            case 'path':
                // Удаляем лишние слэши и точки
                $value = preg_replace('/[\/\\\\]+/', '/', $value);
                $value = preg_replace('/\/\.\//', '/', $value);
                $value = preg_replace('/\/+/', '/', $value);
                return rtrim($value, '/');

            default:
                return strip_tags(trim($value));
        }
    }
}
