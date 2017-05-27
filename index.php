<?php
require_once 'autoload.php';
require_once 'msf/Msf.php';


class A extends \msf\base\Object
{
    public $var1;
    public $_var2;

    public function setVar2($val)
    {
        $this->_var2 = $val;
    }


    public function getVar2()
    {
        return $this->_var2;
    }


}

$config = [
  "var1" => "val1",
  "var2" => "val2"
];

$ob = new A($config);
echo $ob->getvar2();

debug($ob);



function debug($var)
{
    echo '<pre>';
    print_r($var);
    var_dump($var);
    echo '</pre>';
}