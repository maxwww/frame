<?php


namespace msf\base;


class InvalidCallException extends \BadMethodCallException
{
    public function getName()
    {
        return 'Invalid Call';
    }
}