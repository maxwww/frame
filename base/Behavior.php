<?php


namespace msf\base;

/**
 * Behavior is the base class for all behavior classes.
 *
 * @package msf\base
 */
class Behavior
{
    /**
     * @var Component the owner of this behavior
     */
    public $owner;


    /**
     * Declares event handlers for the [[owner]]'s events.
     *
     * @return array
     */
    public function events()
    {
        return [];
    }
    /**
     * Attaches the behavior object to the component.
     *
     * @param Component $owner the component that this behavior is to be attached to.
     */
    public function attach($owner)
    {
        $this->owner = $owner;
        foreach ($this->events() as $event => $handler) {
            $owner->on($event, is_string($handler) ? [$this, $handler] : $handler);
        }
    }
    /**
     * Detaches the behavior object from the component.
     */
    public function detach()
    {
        if ($this->owner) {
            foreach ($this->events() as $event => $handler) {
                $this->owner->off($event, is_string($handler) ? [$this, $handler] : $handler);
            }
            $this->owner = null;
        }
    }
}