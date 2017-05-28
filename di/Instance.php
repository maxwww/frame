<?php


namespace msf\di;

use Msf;
use msf\base\Exception;

class Instance
{
    /**
     * @var string the component ID, class name, interface name or alias name
     */
    public $id;


    /**
     * Constructor.
     * @param string $id the component ID
     */
    protected function __construct($id)
    {
        $this->id = $id;
    }

    /**
     * Creates a new Instance object.
     * @param string $id the component ID
     * @return Instance the new Instance object.
     */
    public static function of($id)
    {
        return new static($id);
    }

    /**
     * Resolves the specified reference into the actual object and makes sure it is of the specified type.
     *
     * @param object|string|array|static $reference an object or a reference to the desired object.
     * @param string $type the class/interface name to be checked. If null, type check will not be performed.
     * @param ServiceLocator|Container $container the container. This will be passed to [[get()]].
     * @return object the object referenced by the Instance, or `$reference` itself if it is an object.
     * @throws Exception if the reference is invalid
     */
    public static function ensure($reference, $type = null, $container = null)
    {
        if (is_array($reference)) {
            $class = isset($reference['class']) ? $reference['class'] : $type;
            if (!$container instanceof Container) {
                $container = Msf::$container;
            }
            unset($reference['class']);
            return $container->get($class, [], $reference);
        } elseif (empty($reference)) {
            throw new Exception('The required component is not specified.');
        }

        if (is_string($reference)) {
            $reference = new static($reference);
        } elseif ($type === null || $reference instanceof $type) {
            return $reference;
        }

        if ($reference instanceof self) {
            try {
                $component = $reference->get($container);
            } catch(\ReflectionException $e) {
                throw new Exception('Failed to instantiate component or class "' . $reference->id . '".', 0, $e);
            }
            if ($type === null || $component instanceof $type) {
                return $component;
            } else {
                throw new Exception('"' . $reference->id . '" refers to a ' . get_class($component) . " component. $type is expected.");
            }
        }

        $valueType = is_object($reference) ? get_class($reference) : gettype($reference);
        throw new Exception("Invalid data type: $valueType. $type is expected.");
    }

    /**
     * Returns the actual object referenced by this Instance object.
     * @param ServiceLocator|Container $container the container used to locate the referenced object.
     * If null, the method will first try `Yii::$app` then `Yii::$container`.
     * @return object the actual object referenced by this Instance object.
     */
    public function get($container = null)
    {
        if ($container) {
            return $container->get($this->id);
        }
        if (Msf::$app && Msf::$app->has($this->id)) {
            return Msf::$app->get($this->id);
        } else {
            return Msf::$container->get($this->id);
        }
    }
}