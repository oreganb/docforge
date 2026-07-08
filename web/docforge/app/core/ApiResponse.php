<?php

namespace DocForge\Core;

class ApiResponse
{
    public static function json($data, $code = 200)
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }

    public static function error($message, $code = 400)
    {
        self::json(array('ok' => false, 'error' => $message), $code);
    }
}
