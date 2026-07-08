<?php

namespace DocForge\Core;

/**
 * Synchronous event dispatcher (PRD v5.1 amendment 3).
 */
class Events
{
    /** @var array<string,array<int,callable>> */
    private static $listeners = array();

    public static function on($event, callable $listener)
    {
        if (!isset(self::$listeners[$event])) {
            self::$listeners[$event] = array();
        }
        self::$listeners[$event][] = $listener;
    }

    /** @param array<string,mixed> $payload */
    public static function emit($event, array $payload = array())
    {
        if (!isset(self::$listeners[$event])) {
            return;
        }
        foreach (self::$listeners[$event] as $listener) {
            call_user_func($listener, $payload);
        }
    }
}
