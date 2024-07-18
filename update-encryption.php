<?php
/**
 * This code is licensed under the MIT License.
 *
 * MIT License
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

use Magento\Framework\App\DeploymentConfig\Writer\PhpFormatter;
use Magento\Framework\Encryption\Adapter\SodiumChachaIetf;

// Configure this with relevant tables
$tablesToExclude = [
    "%^catalog%",
    "%amasty_xsearch_users_search%",
    "%url_rewrite%",
    "%amasty_merchandiser_product_index_eav_replica%"
];
$envPathsToExclude = [
    "backend/",
    "crypt/",
    "db/",
    "resource/",
    "x-frame-options/",
    "MAGE_MODE/",
    "session/",
    "queue/",
    "cache/",
    "lock/",
    "cache_types/",
    "install/",
    "directories/",
    "http_cache_hosts/",
];

$basePath = __DIR__;
if (!file_exists($basePath . '/app/etc/env.php')) {
    if (file_exists(dirname($basePath) . '/app/etc/env.php')) {
        $basePath = dirname($basePath);
    } else {
        exit("Run the script from the magento root folder or in the var folder");
    }
}

$scriptName = $argv[0];
$command = $argv[1] ?? "";

if (!in_array($command, ['scan', 'generate-commands', 'update-table', 'update-record', 'scan-env', 'update-env'])) {
    echo "Usage:\n";
    echo "  Database commands:\n";
    echo "    php $scriptName scan\n";
    echo "    php $scriptName generate-commands [--key-number=NUMBER] [--old-key-number=NUMBER] [--dry-run] [--dump-file=FILENAME]\n";
    echo "    php $scriptName update-table --table=TABLE --field=FIELD --id-field=ID_FIELD [--key-number=NUMBER] [--old-key-number=NUMBER] [--dry-run] [--dump-file=FILENAME] [--backup-file=FILENAME]\n";
    echo "    php $scriptName update-record --table=TABLE --field=FIELD --id-field=ID_FIELD --id=ID [--key-number=NUMBER] [--old-key-number=NUMBER] [--dry-run] [--dump-file=FILENAME] [--backup-file=FILENAME]\n";
    echo "\n";
    echo "  Environment file commands:\n";
    echo "   php $scriptName scan-env\n";
    echo "   php $scriptName update-env [--key-number=NUMBER] [--old-key-number=NUMBER] [--dry-run] [--dump-file=FILENAME] [--backup-file=FILENAME]\n";
    exit();
}

$params = [
    'dry-run' => false,
    'key-number' => 1,
    'old-key-number' => 0,
    'id-field' => '',
    'field' => '',
    'table' => '',
    'id' => '',
    'dump-file' => '',
    'backup-file' => ''
];

foreach ($argv as $i => $argument) {
    if ($i == 0 || $i == 1)
        continue;

    if ($argument == '--dry-run') {
        $params['dry-run'] = true;
    } else if (preg_match('%--key-number=(\d+?)$%', $argument, $m)) {
        $params['key-number'] = (int)$m[1];
    } else if (preg_match('%--old-key-number=(\d+?)$%', $argument, $m)) {
        $params['old-key-number'] = (int)$m[1];
    } else if (preg_match('%--id-field=(.*?)$%', $argument, $m)) {
        $params['id-field'] = $m[1];
    } else if (preg_match('%--field=(.*?)$%', $argument, $m)) {
        $params['field'] = $m[1];
    } else if (preg_match('%--table=(.*?)$%', $argument, $m)) {
        $params['table'] = $m[1];
    } else if (preg_match('%--id=(\d+?)$%', $argument, $m)) {
        $params['id'] = $m[1];
    } else if (preg_match('%--dump-file=(.*?)$%', $argument, $m)) {
        $params['dump-file'] = $m[1];
    } else if (preg_match('%--backup-file=(.*?)$%', $argument, $m)) {
        $params['backup-file'] = $m[1];
    }
}

require $basePath . '/vendor/magento/framework/Encryption/Adapter/EncryptionAdapterInterface.php';
require $basePath . '/vendor/magento/framework/Encryption/Adapter/SodiumChachaIetf.php';
require $basePath . '/vendor/magento/framework/App/DeploymentConfig/Writer/FormatterInterface.php';
require $basePath . '/vendor/magento/framework/App/DeploymentConfig/Writer/PhpFormatter.php';

$env = include $basePath . '/app/etc/env.php';

$keys = preg_split('/\s+/s', trim((string) $env['crypt']['key']));
if ($params['old-key-number'] >= count($keys)) {
    exit("--old-key-number is not a valid key number");
}
if ($params['key-number'] >= count($keys)) {
    exit("--key-number is not a valid key number");
}

$key = $keys[$params['old-key-number']];
$crypt  = new SodiumChachaIetf($key);

$newKey = $keys[$params['key-number']];
$cryptNew  = new SodiumChachaIetf($newKey);

function recursiveScanEnv($envPathsToExclude, $value, callable $onMatch, $path = '')
{
    foreach ($envPathsToExclude as $envPathToExclude) {
        if (str_starts_with($path, $envPathToExclude)) {
            return $value;
        }
    }

    if (is_array($value)) {
        foreach($value as $key => $field) {
            $value[$key] = recursiveScanEnv($envPathsToExclude, $field, $onMatch, $path . ($path === '' ? '' : '/') . $key );
        }
        return $value;
    }

    if (is_string($value) && preg_match("%^\d\:\d\:%", $value)) {
        return $onMatch($value, $path);
    }

    return $value;
}

if ($command == 'scan' || $command == 'generate-commands') {
    $dbConfig = $env['db']['connection']['default'];
    $db = new PDO(sprintf('mysql:host=%s;dbname=%s;', $dbConfig['host'], $dbConfig['dbname']), $dbConfig['username'], $dbConfig['password']);

    $encryptedFields = [];

    $tables = $db->query("SHOW TABLES")->fetchAll();

    foreach ($tables as $tableRow) {
        $table = $tableRow[0];
        $skipTable = false;
        foreach ($tablesToExclude as $pattern) {
            if (preg_match($pattern, $table)) {
                $skipTable = true;
                break;
            }
        }

        if ($skipTable) {
            continue;
        }

        $data = $db->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC);
        if (!$data) {
            continue;
        }

        $fields = $db->query("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_ASSOC);
        if (!$fields) {
            continue;
        }

        $idField = "";
        foreach ($fields as $field) {
            if ($field['Extra'] == 'auto_increment') {
                $idField = $field['Field'];
            }
        }

        foreach ($data as $row) {
            foreach ($row as $fieldName => $value) {
                if (($value !== null) && preg_match("%^\d\:\d\:%", $value)) {
                    $encryptedField = sprintf("%s::%s::%s", $table, $fieldName, $idField);
                    if (!in_array($encryptedField, $encryptedFields)) {
                        $encryptedFields[] = $encryptedField;
                    }
                }
            }
        }
    }

    if ($command === 'generate-commands') {
        foreach ($encryptedFields as $encryptedField) {
            [$table, $fieldName, $idField] = explode("::", $encryptedField);

            $cmdParams = [];
            $cmdParams[] = sprintf('--table=%s', $table);
            $cmdParams[] = sprintf('--field=%s', $fieldName);
            $cmdParams[] = sprintf('--id-field=%s', $idField);
            if ($params['key-number'] !== 1) {
                $cmdParams[] = sprintf('--key-number=%s', $params['key-number']);
            }
            if ($params['old-key-number'] !== 0) {
                $cmdParams[] = sprintf('--old-key-number=%s',
                    $params['old-key-number']);
            }
            if ($params['dry-run']) {
                $cmdParams[] = "--dry-run";
            }
            if (!empty($params['dump-file'])) {
                $cmdParams[] = sprintf('--dump-file=%s', $params['dump-file']);
            }
            if (!empty($params['backup-file'])) {
                $cmdParams[] = sprintf('--backup-file=%s', $params['backup-file']);
            }
            echo sprintf("php $scriptName update-table %s", join(" ", $cmdParams)) . "\n";
        };
    } else {
        echo join("\n", $encryptedFields) . "\n";
    }
}


if ($command == 'update-table' || $command == 'update-record') {
    $dbConfig = $env['db']['connection']['default'];
    $db = new PDO(sprintf('mysql:host=%s;dbname=%s;', $dbConfig['host'], $dbConfig['dbname']), $dbConfig['username'], $dbConfig['password']);

    if (!isset($params['table']))
        exit("--table option is required");
    if (!isset($params['id-field']))
        exit("--id-field option is required");
    if (!isset($params['field']))
        exit("--field option is required");
    if (!isset($params['id']) && $command == 'update-record')
        exit("Use update-record command to update a single record");

    $idField = $params['id-field'];
    $table = $params['table'];
    $field = $params['field'];

    $keyNumber    = $params['old-key-number'];
    $recordFilter = '';
    if ($command == 'update-record' && isset($params['id']) && ($id = (int)$params['id']) > 0) {
        $recordFilter = sprintf(" AND `%s`='%d'", $idField, $id);
    }

    $query = sprintf("SELECT * FROM `%s` WHERE `%s` LIKE '%d:3%%' %s", $table, $field, $keyNumber, $recordFilter);
    $data = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);

    $fileHandler = null;
    $backupHandler = null;
    if (!empty($params['dump-file'])) {
        $fileHandler = fopen($params['dump-file'], 'a');
    }
    if (!empty($params['backup-file'])) {
        $backupHandler = fopen($params['backup-file'], 'a');
    }
    foreach ($data as $row) {
        $value = $row[$field];
        $chunks      = explode(':', $value);
        $decrypted   = $crypt->decrypt(base64_decode($chunks[2]));
        $reEncrypted = sprintf("%d:3:%s", $params['key-number'], base64_encode($cryptNew->encrypt($decrypted)));

        $updateQuery = sprintf(
            "UPDATE `%s` SET `%s`=%s WHERE `%s`=%d LIMIT 1;",
            $table,
            $field,
            $db->quote($reEncrypted),
            $idField,
            $row[$idField]
        );

        $backupQuery = sprintf(
            "UPDATE `%s` SET `%s`=%s WHERE `%s`=%d LIMIT 1;",
            $table,
            $field,
            $db->quote($value),
            $idField,
            $row[$idField]
        );

        if (!empty($params['dump-file'])) {
            fwrite($fileHandler, $updateQuery . "\n");
        }

        if (!empty($params['backup-file'])) {
            fwrite($backupHandler, $backupQuery . "\n");
        }

        echo $updateQuery . "\n";
        if (!$params['dry-run'] && empty($params['dump-file'])) {
            $db->query($updateQuery);
        }
    }
}

if ($command === 'scan-env') {
    recursiveScanEnv($envPathsToExclude, $env, function($value, $path) {
        echo sprintf("%s", $path) . "\n";
    });
}

if ($command === 'update-env') {
    $updatedEnv = recursiveScanEnv($envPathsToExclude, $env, function(&$value, $path) use ($crypt, $cryptNew, $params) {
        if (!str_starts_with($value, sprintf('%d:3', $params['old-key-number']))) {
            return;
        }

        $chunks      = explode(':', $value);
        $decrypted   = $crypt->decrypt(base64_decode($chunks[2]));
        $reEncrypted = sprintf("%d:3:%s", $params['key-number'], base64_encode($cryptNew->encrypt($decrypted)));

        echo sprintf("%s = %s", $path, $reEncrypted) . "\n";

        return $reEncrypted;
    });

    $formatter = new PhpFormatter();
    $newEnvContents = $formatter->format($updatedEnv);

    if (!empty($params['dump-file'])) {
        file_put_contents($params['dump-file'], $newEnvContents);
    }

    if (!empty($params['backup-file'])) {
        $currentEnv = file_get_contents($basePath . '/app/etc/env.php');
        file_put_contents($params['backup-file'], $currentEnv);
    }

    if (!$params['dry-run'] && empty($params['dump-file'])) {
        file_put_contents($basePath . '/app/etc/env.php', $newEnvContents);
    }
}
