<?php

namespace AmoCRM;

use Exception;
use InvalidArgumentException;

class Handler
{
    private $domain;
    private $debug;
    private $errors;
    private $storageDir;

    public $user;
    public $key;
    public $config;
    public $result;
    public $last_insert_id;

    public function __construct($params)
    {
        $this->domain = $params['domain'];
        $this->user = $params['user'];
        $this->debug = $params['debug'];
        $this->config = $params['config'];
        $this->key = $params['key'];
        $this->storageDir = realpath((
            $params['storageDir']
        ));

        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0700, true)) {
            throw new InvalidArgumentException('Директория "config" не может быть создана по пути ' . $this->storageDir);
        }

        if (!is_readable($this->storageDir) || !is_writable($this->storageDir)) {
            throw new InvalidArgumentException('Директория "config" должна быть доступна для чтения и записи');
        }

        if ($this->debug) {
            $this->errors = json_decode(trim(file_get_contents(__DIR__ . '/../config/errors.json')));
        }

        $this->request(new Request(Request::AUTH, $this));
    }

    public function request(Request $request)
    {
        $headers = ['Content-Type: application/json'];
        if ($date = $request->getIfModifiedSince()) {
            $headers[] = 'IF-MODIFIED-SINCE: ' . $date;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://' . $this->domain . '.amocrm.ru/private/api/' . $request->url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'amoCRM-API-client/1.0');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->storageDir . '/cookie.txt');
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->storageDir . '/cookie.txt');

        if ($request->post) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request->params));
        }

        $result = curl_exec($ch);
        $info = curl_getinfo($ch);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            throw new Exception($error);
        }

        $this->result = json_decode($result);

        if (floor($info['http_code'] / 100) >= 3) {
            if (!$this->debug) {
                $message = $this->result->response->error;
            } else {
                $error = (isset($this->result->response->error)) ? $this->result->response->error : '';
                $error_code = (isset($this->result->response->error_code)) ? $this->result->response->error_code : '';
                $description = ($error && $error_code && isset($this->errors->{$error_code})) ? $this->errors->{$error_code} : '';
                $response = (isset($this->result->response->error)) ? $this->result->response->error : '';

                $message = json_encode([
                    'http_code' => $info['http_code'],
                    'response' => $response,
                    'description' => $description
                ], JSON_UNESCAPED_UNICODE);
            }

            throw new Exception($message);
        }

        $this->result = isset($this->result->response) ? $this->result->response : false;
        $this->last_insert_id = ($request->post && isset($this->result->{$request->type}->{$request->action}[0]->id))
            ? $this->result->{$request->type}->{$request->action}[0]->id
            : false;

        return $this;
    }
}
