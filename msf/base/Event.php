<?php


namespace msf\base;


class Event extends Object
{
    public $name;

    public $sender;

    public $handled = false;

    public $data;

    private static $_events = [];

    public static function on($class, $name, $handler, $data = null, $append = true)
    {
        $class = ltrim($class, '\\');
        if ($append || empty(self::$_events[$name][$class])) {
            self::$_events[$name][$class][] = [$handler, $data];
        } else {
            array_unshift(self::$_events[$name][$class], [$handler, $data]);
        }
    }

    public static function off($class, $name, $handler = null)
    {
        $class = ltrim($class, '\\');
        if (empty(self::$_events[$name][$class])) {
            return false;
        }
        if ($handler === null) {
            unset(self::$_events[$name][$class]);
            return true;
        }

        $removed = false;
        foreach (self::$_events[$name][$class] as $i => $event) {
            if ($event[0] === $handler) {
                unset(self::$_events[$name][$class][$i]);
                $removed = true;
            }
        }
        if ($removed) {
            self::$_events[$name][$class] = array_values(self::$_events[$name][$class]);
        }
        return $removed;
    }

    public static function offAll()
    {
        self::$_events = [];
    }

    public static function hasHandlers($class, $name)
    {
        if (empty(self::$_events[$name])) {
            return false;
        }
        if (is_object($class)) {
            $class = get_class($class);
        } else {
            $class = ltrim($class, '\\');
        }

        $classes = array_merge(
            [$class],
            class_parents($class, true),
            class_implements($class, true)
        );

        foreach ($classes as $class) {
            if (!empty(self::$_events[$name][$class])) {
                return true;
            }
        }

        return false;
    }

    public static function trigger($class, $name, $event = null)
    {
        if (empty(self::$_events[$name])) {
            return;
        }
        if ($event === null) {
            $event = new static;
        }
        $event->handled = false;
        $event->name = $name;

        if (is_object($class)) {
            if ($event->sender === null) {
                $event->sender = $class;
            }
            $class = get_class($class);
        } else {
            $class = ltrim($class, '\\');
        }

        $classes = array_merge(
            [$class],
            class_parents($class, true),
            class_implements($class, true)
        );

        foreach ($classes as $class) {
            if (!empty(self::$_events[$name][$class])) {
                foreach (self::$_events[$name][$class] as $handler) {
                    $event->data = $handler[1];
                    call_user_func($handler[0], $event);
                    if ($event->handled) {
                        return;
                    }
                }
            }
        }
    }
}