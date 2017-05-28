<?php


namespace msf\base;

use Msf;

/**
 * Component is the base class that implements the *property*, *event* and *behavior* features.
 *
 * @package msf\base
 */
class Component extends Object
{
    /**
     * @var array the attached event handlers (event name => handlers)
     */
    private $_events = [];
    /**
     * @var Behavior[]|null the attached behaviors (behavior name => behavior).
     */
    private $_behaviors;


    /**
     * Returns the value of a component property.
     *
     * will be implicitly called when executing `$value = $component->property;`.
     * @param string $name the property name
     * @return mixed the property value or the value of a behavior's property
     * @throws Exception
     */
    public function __get($name)
    {
        $getter = 'get' . $name;
        if (method_exists($this, $getter)) {
            // read property, e.g. getName()
            return $this->$getter();
        }

        // behavior property
        $this->ensureBehaviors();
        foreach ($this->_behaviors as $behavior) {
            if ($behavior->canGetProperty($name)) {
                return $behavior->$name;
            }
        }

        if (method_exists($this, 'set' . $name)) {
            throw new Exception('Getting write-only property: ' . get_class($this) . '::' . $name);
        }

        throw new Exception('Getting unknown property: ' . get_class($this) . '::' . $name);
    }

    /**
     * Sets the value of a component property.
     *
     * @param string $name the property name or the event name
     * @param mixed $value the property value
     * @throws Exception
     */
    public function __set($name, $value)
    {
        $setter = 'set' . $name;
        if (method_exists($this, $setter)) {
            // set property
            $this->$setter($value);

            return;
        } elseif (strncmp($name, 'on ', 3) === 0) {
            // on event: attach event handler
            $this->on(trim(substr($name, 3)), $value);

            return;
        } elseif (strncmp($name, 'as ', 3) === 0) {
            // as behavior: attach behavior
            $name = trim(substr($name, 3));
            $this->attachBehavior($name, $value instanceof Behavior ? $value : Msf::createObject($value));

            return;
        }

        // behavior property
        $this->ensureBehaviors();
        foreach ($this->_behaviors as $behavior) {
            if ($behavior->canSetProperty($name)) {
                $behavior->$name = $value;
                return;
            }
        }

        if (method_exists($this, 'get' . $name)) {
            throw new Exception('Setting read-only property: ' . get_class($this) . '::' . $name);
        }

        throw new Exception('Setting unknown property: ' . get_class($this) . '::' . $name);
    }

    /**
     * Checks if a property is set, i.e. defined and not null.
     *
     * @param string $name the property name or the event name
     * @return bool whether the named property is set
     */
    public function __isset($name)
    {
        $getter = 'get' . $name;
        if (method_exists($this, $getter)) {
            return $this->$getter() !== null;
        }

        // behavior property
        $this->ensureBehaviors();
        foreach ($this->_behaviors as $behavior) {
            if ($behavior->canGetProperty($name)) {
                return $behavior->$name !== null;
            }
        }

        return false;
    }

    /**
     * Sets a component property to be null.
     *
     * @param string $name the property name
     * @throws Exception if the property is read only.
     */
    public function __unset($name)
    {
        $setter = 'set' . $name;
        if (method_exists($this, $setter)) {
            $this->$setter(null);
            return;
        }

        // behavior property
        $this->ensureBehaviors();
        foreach ($this->_behaviors as $behavior) {
            if ($behavior->canSetProperty($name)) {
                $behavior->$name = null;
                return;
            }
        }

        throw new Exception('Unsetting an unknown or read-only property: ' . get_class($this) . '::' . $name);
    }

    /**
     * Calls the named method which is not a class method.
     *
     * @param string $name the method name
     * @param array $params method parameters
     * @return mixed the method return value
     * @throws Exception
     */
    public function __call($name, $params)
    {
        $this->ensureBehaviors();
        foreach ($this->_behaviors as $object) {
            if ($object->hasMethod($name)) {
                return call_user_func_array([$object, $name], $params);
            }
        }
        throw new Exception('Calling unknown method: ' . get_class($this) . "::$name()");
    }

    /**
     * This method is called after the object is created by cloning an existing one.
     * It removes all behaviors because they are attached to the old object.
     */
    public function __clone()
    {
        $this->_events = [];
        $this->_behaviors = null;
    }

    /**
     * Returns a value indicating whether a property is defined for this component.
     *
     * @param string $name the property name
     * @param bool $checkVars whether to treat member variables as properties
     * @param bool $checkBehaviors whether to treat behaviors' properties as properties of this component
     * @return bool whether the property is defined
     */
    public function hasProperty($name, $checkVars = true, $checkBehaviors = true)
    {
        return $this->canGetProperty($name, $checkVars, $checkBehaviors) || $this->canSetProperty($name, false, $checkBehaviors);
    }

    /**
     * Returns a value indicating whether a property can be read.
     *
     * @param string $name the property name
     * @param bool $checkVars whether to treat member variables as properties
     * @param bool $checkBehaviors whether to treat behaviors' properties as properties of this component
     * @return bool whether the property can be read
     */
    public function canGetProperty($name, $checkVars = true, $checkBehaviors = true)
    {
        if (method_exists($this, 'get' . $name) || $checkVars && property_exists($this, $name)) {
            return true;
        } elseif ($checkBehaviors) {
            $this->ensureBehaviors();
            foreach ($this->_behaviors as $behavior) {
                if ($behavior->canGetProperty($name, $checkVars)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Returns a value indicating whether a property can be set.
     *
     * @param string $name the property name
     * @param bool $checkVars whether to treat member variables as properties
     * @param bool $checkBehaviors whether to treat behaviors' properties as properties of this component
     * @return bool whether the property can be written
     */
    public function canSetProperty($name, $checkVars = true, $checkBehaviors = true)
    {
        if (method_exists($this, 'set' . $name) || $checkVars && property_exists($this, $name)) {
            return true;
        } elseif ($checkBehaviors) {
            $this->ensureBehaviors();
            foreach ($this->_behaviors as $behavior) {
                if ($behavior->canSetProperty($name, $checkVars)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Returns a value indicating whether a method is defined.
     *
     * @param string $name the property name
     * @param bool $checkBehaviors whether to treat behaviors' methods as methods of this component
     * @return bool whether the method is defined
     */
    public function hasMethod($name, $checkBehaviors = true)
    {
        if (method_exists($this, $name)) {
            return true;
        } elseif ($checkBehaviors) {
            $this->ensureBehaviors();
            foreach ($this->_behaviors as $behavior) {
                if ($behavior->hasMethod($name)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Returns a list of behaviors that this component should behave as.
     *
     * @return array the behavior configurations.
     */
    public function behaviors()
    {
        return [];
    }

    /**
     * Returns a value indicating whether there is any handler attached to the named event.
     * @param string $name the event name
     * @return bool whether there is any handler attached to the event.
     */
    public function hasEventHandlers($name)
    {
        $this->ensureBehaviors();
        return !empty($this->_events[$name]) || Event::hasHandlers($this, $name);
    }

    /**
     * Attaches an event handler to an event.
     *
     * The event handler must be a valid PHP callback. The following are
     * some examples:
     *
     * ```
     * function ($event) { ... }         // anonymous function
     * [$object, 'handleClick']          // $object->handleClick()
     * ['Page', 'handleClick']           // Page::handleClick()
     * 'handleClick'                     // global function handleClick()
     * ```
     *
     * The event handler must be defined with the following signature,
     *
     * ```
     * function ($event)
     * ```
     *
     * where `$event` is an [[Event]] object which includes parameters associated with the event.
     *
     * @param string $name the event name
     * @param callable $handler the event handler
     * @param mixed $data the data to be passed to the event handler when the event is triggered.
     * When the event handler is invoked, this data can be accessed via [[Event::data]].
     * @param bool $append whether to append new event handler to the end of the existing
     * handler list. If false, the new handler will be inserted at the beginning of the existing
     * handler list.
     * @see off()
     */
    public function on($name, $handler, $data = null, $append = true)
    {
        $this->ensureBehaviors();
        if ($append || empty($this->_events[$name])) {
            $this->_events[$name][] = [$handler, $data];
        } else {
            array_unshift($this->_events[$name], [$handler, $data]);
        }
    }

    /**
     * Detaches an existing event handler from this component.
     * This method is the opposite of [[on()]].
     * @param string $name event name
     * @param callable $handler the event handler to be removed.
     * If it is null, all handlers attached to the named event will be removed.
     * @return bool if a handler is found and detached
     * @see on()
     */
    public function off($name, $handler = null)
    {
        $this->ensureBehaviors();
        if (empty($this->_events[$name])) {
            return false;
        }
        if ($handler === null) {
            unset($this->_events[$name]);
            return true;
        }

        $removed = false;
        foreach ($this->_events[$name] as $i => $event) {
            if ($event[0] === $handler) {
                unset($this->_events[$name][$i]);
                $removed = true;
            }
        }
        if ($removed) {
            $this->_events[$name] = array_values($this->_events[$name]);
        }
        return $removed;
    }

    /**
     * Triggers an event.
     * This method represents the happening of an event. It invokes
     * all attached handlers for the event including class-level handlers.
     * @param string $name the event name
     * @param Event $event the event parameter. If not set, a default [[Event]] object will be created.
     */
    public function trigger($name, Event $event = null)
    {
        $this->ensureBehaviors();
        if (!empty($this->_events[$name])) {
            if ($event === null) {
                $event = new Event;
            }
            if ($event->sender === null) {
                $event->sender = $this;
            }
            $event->handled = false;
            $event->name = $name;
            foreach ($this->_events[$name] as $handler) {
                $event->data = $handler[1];
                call_user_func($handler[0], $event);
                // stop further handling if the event is handled
                if ($event->handled) {
                    return;
                }
            }
        }
        // invoke class-level attached handlers
        Event::trigger($this, $name, $event);
    }

    /**
     * Returns the named behavior object.
     * @param string $name the behavior name
     * @return null|Behavior the behavior object, or null if the behavior does not exist
     */
    public function getBehavior($name)
    {
        $this->ensureBehaviors();
        return isset($this->_behaviors[$name]) ? $this->_behaviors[$name] : null;
    }

    /**
     * Returns all behaviors attached to this component.
     * @return Behavior[] list of behaviors attached to this component
     */
    public function getBehaviors()
    {
        $this->ensureBehaviors();
        return $this->_behaviors;
    }

    /**
     * Attaches a behavior to this component.
     *
     * @param string $name the name of the behavior.
     * @param string|array|Behavior $behavior the behavior configuration.
     * @return Behavior the behavior object
     */
    public function attachBehavior($name, $behavior)
    {
        $this->ensureBehaviors();
        return $this->attachBehaviorInternal($name, $behavior);
    }

    /**
     * Attaches a list of behaviors to the component.
     *
     * @param array $behaviors list of behaviors to be attached to the component
     */
    public function attachBehaviors($behaviors)
    {
        $this->ensureBehaviors();
        foreach ($behaviors as $name => $behavior) {
            $this->attachBehaviorInternal($name, $behavior);
        }
    }

    /**
     * Detaches a behavior from the component.
     *
     * @param string $name the behavior's name.
     * @return null|Behavior the detached behavior. Null if the behavior does not exist.
     */
    public function detachBehavior($name)
    {
        $this->ensureBehaviors();
        if (isset($this->_behaviors[$name])) {
            $behavior = $this->_behaviors[$name];
            unset($this->_behaviors[$name]);
            $behavior->detach();
            return $behavior;
        }

        return null;
    }

    /**
     * Detaches all behaviors from the component.
     */
    public function detachBehaviors()
    {
        $this->ensureBehaviors();
        foreach ($this->_behaviors as $name => $behavior) {
            $this->detachBehavior($name);
        }
    }

    /**
     * Makes sure that the behaviors declared in [[behaviors()]] are attached to this component.
     */
    public function ensureBehaviors()
    {
        if ($this->_behaviors === null) {
            $this->_behaviors = [];
            foreach ($this->behaviors() as $name => $behavior) {
                $this->attachBehaviorInternal($name, $behavior);
            }
        }
    }

    /**
     * Attaches a behavior to this component.
     *
     * @param string|int $name the name of the behavior.
     * @param string|array|Behavior $behavior the behavior to be attached
     * @return Behavior the attached behavior.
     */
    private function attachBehaviorInternal($name, $behavior)
    {
        if (!($behavior instanceof Behavior)) {
            $behavior = Msf::createObject($behavior);
        }
        if (is_int($name)) {
            $behavior->attach($this);
            $this->_behaviors[] = $behavior;
        } else {
            if (isset($this->_behaviors[$name])) {
                $this->_behaviors[$name]->detach();
            }
            $behavior->attach($this);
            $this->_behaviors[$name] = $behavior;
        }

        return $behavior;
    }
}