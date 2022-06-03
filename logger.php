<?php

class Log
{
    public static function debug($message) {
        print("\033[0m " . $message . "\n");
    }

    public static function error($message) {
        print("\033[31m " . $message . "\n");
    }

    public static function info($message) {
        print("\033[32m " . $message . "\n");
    }
}
