<?php


namespace msf\base;


class Exception extends \Exception
{
    public function getName()
    {
        return 'Exception';
    }
}