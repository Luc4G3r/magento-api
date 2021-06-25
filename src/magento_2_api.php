<?php

/**
 * @author Luca Gerhardt <luca.gerhardt@inblau.de>
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(-1);

const CONFIG_FILE_PATH = __DIR__ . '/../config.json';

$restApiPath = 'index.php/rest/';
$restApiTokenEndpoint = 'V1/integration/admin/token';
try {
    $config = json_decode(file_get_contents(CONFIG_FILE_PATH), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    echo sprintf('Error occurred while reading config file:%1$s%2$s', PHP_EOL, $e->getMessage());
}

try {
    if (!isset($config) || !is_array($config) || count($config) < 1) {
        throw new Exception(sprintf('Config file %1$s was empty', CONFIG_FILE_PATH));
    }
    if (!isset($config['storeUrl'])) {
        throw new Exception(getConfigError('storeUrl'));
    }
    if (!isset($config['apiUser']['username'], $config['apiUser']['password'])) {
        throw new Exception(getConfigError('apiUser'));
    }
    if (!isset($config['storeCode'])) {
        $config['storeCode'] = 'default';
    }
    if (!isset($config['apiCalls'])) {
        throw new Exception(getConfigError('apiCalls'));
    } else {
        foreach ($config['apiCalls'] as $index => $call) {
            if (!isset($config['apiCalls'][$index]['path'])) {
                throw new Exception(getConfigError(sprintf('apiCalls -> %1$s -> path', $index)));
            } elseif (!isset($config['apiCalls'][$index]['name'])) {
                $config['apiCalls'][$index]['name'] = '';
            } elseif (!isset($config['apiCalls']['requestType'])) {
                $config['apiCalls'][$index]['requestType'] = 'GET';
            } elseif (!isset($config['apiCalls']['postData'])) {
                $config['apiCalls'][$index]['postData'] = [];
            } elseif (!isset($config['apiCalls']['getData'])) {
                $config['apiCalls'][$index]['getData'] = [];
            }
        }
    }
} catch (Exception $e) {
    echo $e->getMessage();
    die();
}

echo PHP_EOL;
echo sprintf('<<< Now calling API with user: %s >>>', $config['apiUser']['username']);
echo PHP_EOL;
$requestUrlAuth = $config['storeUrl'] . $restApiPath . $config['storeCode'] . '/' . $restApiTokenEndpoint;
echo PHP_EOL;
echo sprintf('<<< API Authentication (%1$s) >>>', $requestUrlAuth);
echo PHP_EOL;
$ch = curl_init($requestUrlAuth);

curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($config['apiUser']));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
try {
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Content-Length: ' . strlen(json_encode($config['apiUser'], JSON_THROW_ON_ERROR))]);
    $token = json_decode(curl_exec($ch), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    echo PHP_EOL;
    echo sprintf('JsonException was thrown while encoding post data: %s, skipping request', $e->getMessage());
    echo PHP_EOL;
    die();
}

if (!is_string($token) || strlen($token) < 1) {
    echo PHP_EOL;
    echo sprintf('<<< Authorization failed, response was: %s >>>', var_export($token, true));
    echo PHP_EOL;
    die();
}
echo PHP_EOL;
echo sprintf('<<< Received token: %s >>>', $token);
echo PHP_EOL;

echo '========================';

// TODO: multiple store codes
//foreach ($config['storeCodes'])
foreach ($config['apiCalls'] as $apiCall) {
    $urlGetParametersString = arrayDataToString($apiCall['getData']);
    $requestPathApiCall = $config['storeUrl'] . $restApiPath . $config['storeCode'] . '/V1/' . $apiCall['path'] . $urlGetParametersString;
    echo PHP_EOL;
    echo sprintf('<<< API call "%1$s" (%2$s) >>>', $apiCall['name'], $requestPathApiCall);
    echo PHP_EOL;

    $ch = curl_init($requestPathApiCall);

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($apiCall['requestType']));
    try {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($apiCall['postData'], JSON_THROW_ON_ERROR));
    } catch (JsonException $e) {
        echo PHP_EOL;
        echo sprintf('JsonException was thrown while encoding post data: %s, skipping request', $e->getMessage());
        echo PHP_EOL;
        continue;
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $token]);

    $result = curl_exec($ch);
    $info = curl_getinfo($ch);

    echo PHP_EOL;
    try {
        echo sprintf('<<< API response (Code %1$s: >>>%2$s%3$s%2$sRequest took %4$s seconds.',
            $info['http_code'],
            PHP_EOL,
            json_encode(json_decode($result, true, 512, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
            $info['total_time']
        );
    } catch (JsonException $e) {
        echo sprintf('<<< Error while parsing API response: %s >>>', $e->getMessage());
    }
    echo PHP_EOL;
}


/**
 * recursive function which generates valid magento 2 api url
 * suffix with api parameters
 *
 * @param array $data
 * @param string|null $parameterName
 * @return string
 */
function arrayDataToString(
    array $data,
    string $parameterName = null
): string {
    $hasParameterName = (null !== $parameterName);
    $string = $hasParameterName ? '' : '?';
    $firstItem = true;
    if (count($data) >= 1) {
        foreach ($data as $key => $item) {
            $isArray = is_array($item);
            $format = '';
            if (!$firstItem) {
                $format .= '&';
            }
            if (!$isArray) {
                $format .= '%1$s%2$s=%3$s';
            } elseif ($hasParameterName) {
                $format .= '%3$s';
            } else {
                $format .= '%1$s%3$s';
            }
            $keyName = $hasParameterName ? '[' . $key . ']' : $key;
            $string .= sprintf($format, $parameterName, $keyName, ($isArray) ? arrayDataToString($item, $parameterName . $keyName) : $item);
            $firstItem = false;
        }
    }
    return $string;
}

function getConfigError(string $fieldName): string
{
    return sprintf(
        'Failed to read config file %1$s:%2$s%3$s was invalid, please check your configuration',
        CONFIG_FILE_PATH,
        PHP_EOL,
        $fieldName
    );
}
