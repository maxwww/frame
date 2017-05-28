<?php


namespace msf\di;

use ReflectionClass;
use Msf;
use msf\base\Component;
use msf\base\Exception;
use msf\helpers\ArrayHelper;

class Container extends Component
{
    /**
     * @var array singleton objects indexed by their types
     */
    private $_singletons = [];
    /**
     * @var array object definitions indexed by their types
     */
    private $_definitions = [];
    /**
     * @var array constructor parameters indexed by object types
     */
    private $_params = [];
    /**
     * @var array cached ReflectionClass objects indexed by class/interface names
     */
    private $_reflections = [];
    /**
     * @var array cached dependencies indexed by class/interface names. Each class name
     * is associated with a list of constructor parameter types or default values.
     */
    private $_dependencies = [];


    /**
     * Returns an instance of the requested class.
     *
     * @param string $class the class name or an alias name (e.g. `foo`) that was previously registered via [[set()]]
     * or [[setSingleton()]].
     * @param array $params a list of constructor parameter values. The parameters should be provided in the order
     * they appear in the constructor declaration. If you want to skip some parameters, you should index the remaining
     * ones with the integers that represent their positions in the constructor parameter list.
     * @param array $config a list of name-value pairs that will be used to initialize the object properties.
     * @return object an instance of the requested class.
     * @throws Exception
     * @throws NotInstantiableException
     */
    public function get($class, $params = [], $config = [])
    {
        if (isset($this->_singletons[$class])) {
            // singleton
            return $this->_singletons[$class];
        } elseif (!isset($this->_definitions[$class])) {
            return $this->build($class, $params, $config);
        }

        $definition = $this->_definitions[$class];

        if (is_callable($definition, true)) {
            $params = $this->resolveDependencies($this->mergeParams($class, $params));
            $object = call_user_func($definition, $this, $params, $config);
        } elseif (is_array($definition)) {
            $concrete = $definition['class'];
            unset($definition['class']);

            $config = array_merge($definition, $config);
            $params = $this->mergeParams($class, $params);

            if ($concrete === $class) {
                $object = $this->build($class, $params, $config);
            } else {
                $object = $this->get($concrete, $params, $config);
            }
        } elseif (is_object($definition)) {
            return $this->_singletons[$class] = $definition;
        } else {
            throw new Exception('Unexpected object definition type: ' . gettype($definition));
        }

        if (array_key_exists($class, $this->_singletons)) {
            // singleton
            $this->_singletons[$class] = $object;
        }

        return $object;
    }

    /**
     * Registers a class definition with this container.
     *
     * @param string $class class name, interface name or alias name
     * @param mixed $definition the definition associated with `$class`.
     * @param array $params the list of constructor parameters. The parameters will be passed to the class
     * constructor when [[get()]] is called.
     * @return $this the container itself
     */
    public function set($class, $definition = [], array $params = [])
    {
        $this->_definitions[$class] = $this->normalizeDefinition($class, $definition);
        $this->_params[$class] = $params;
        unset($this->_singletons[$class]);
        return $this;
    }

    /**
     * Registers a class definition with this container and marks the class as a singleton class.
     *
     * @param string $class class name, interface name or alias name
     * @param mixed $definition the definition associated with `$class`. See [[set()]] for more details.
     * @param array $params the list of constructor parameters. The parameters will be passed to the class
     * constructor when [[get()]] is called.
     * @return $this the container itself
     */
    public function setSingleton($class, $definition = [], array $params = [])
    {
        $this->_definitions[$class] = $this->normalizeDefinition($class, $definition);
        $this->_params[$class] = $params;
        $this->_singletons[$class] = null;
        return $this;
    }

    /**
     * Returns a value indicating whether the container has the definition of the specified name.
     * @param string $class class name, interface name or alias name
     * @return bool whether the container has the definition of the specified name..
     */
    public function has($class)
    {
        return isset($this->_definitions[$class]);
    }

    /**
     * Returns a value indicating whether the given name corresponds to a registered singleton.
     * @param string $class class name, interface name or alias name
     * @param bool $checkInstance whether to check if the singleton has been instantiated.
     * @return bool whether the given name corresponds to a registered singleton. If `$checkInstance` is true,
     * the method should return a value indicating whether the singleton has been instantiated.
     */
    public function hasSingleton($class, $checkInstance = false)
    {
        return $checkInstance ? isset($this->_singletons[$class]) : array_key_exists($class, $this->_singletons);
    }

    /**
     * Removes the definition for the specified name.
     * @param string $class class name, interface name or alias name
     */
    public function clear($class)
    {
        unset($this->_definitions[$class], $this->_singletons[$class]);
    }

    /**
     * Normalizes the class definition.
     * @param string $class class name
     * @param string|array|callable $definition the class definition
     * @return array the normalized class definition
     * @throws Exception if the definition is invalid.
     */
    protected function normalizeDefinition($class, $definition)
    {
        if (empty($definition)) {
            return ['class' => $class];
        } elseif (is_string($definition)) {
            return ['class' => $definition];
        } elseif (is_callable($definition, true) || is_object($definition)) {
            return $definition;
        } elseif (is_array($definition)) {
            if (!isset($definition['class'])) {
                if (strpos($class, '\\') !== false) {
                    $definition['class'] = $class;
                } else {
                    throw new Exception("A class definition requires a \"class\" member.");
                }
            }
            return $definition;
        } else {
            throw new Exception("Unsupported definition type for \"$class\": " . gettype($definition));
        }
    }

    /**
     * Returns the list of the object definitions or the loaded shared objects.
     * @return array the list of the object definitions or the loaded shared objects (type or ID => definition or instance).
     */
    public function getDefinitions()
    {
        return $this->_definitions;
    }

    /**
     * Creates an instance of the specified class.
     * This method will resolve dependencies of the specified class, instantiate them, and inject
     * them into the new instance of the specified class.
     * @param string $class the class name
     * @param array $params constructor parameters
     * @param array $config configurations to be applied to the new instance
     * @return object the newly created instance of the specified class
     * @throws Exception
     * @throws NotInstantiableException
     */
    protected function build($class, $params, $config)
    {
        /* @var $reflection ReflectionClass */
        list ($reflection, $dependencies) = $this->getDependencies($class);

        foreach ($params as $index => $param) {
            $dependencies[$index] = $param;
        }

        $dependencies = $this->resolveDependencies($dependencies, $reflection);
        if (!$reflection->isInstantiable()) {
            throw new NotInstantiableException($reflection->name);
        }
        if (empty($config)) {
            return $reflection->newInstanceArgs($dependencies);
        }

        if (!empty($dependencies) && $reflection->implementsInterface('msf\base\Configurable')) {
            // set $config as the last parameter (existing one will be overwritten)
            $dependencies[count($dependencies) - 1] = $config;
            return $reflection->newInstanceArgs($dependencies);
        } else {
            $object = $reflection->newInstanceArgs($dependencies);
            foreach ($config as $name => $value) {
                $object->$name = $value;
            }
            return $object;
        }
    }

    /**
     * Merges the user-specified constructor parameters with the ones registered via [[set()]].
     * @param string $class class name, interface name or alias name
     * @param array $params the constructor parameters
     * @return array the merged parameters
     */
    protected function mergeParams($class, $params)
    {
        if (empty($this->_params[$class])) {
            return $params;
        } elseif (empty($params)) {
            return $this->_params[$class];
        } else {
            $ps = $this->_params[$class];
            foreach ($params as $index => $value) {
                $ps[$index] = $value;
            }
            return $ps;
        }
    }

    /**
     * Returns the dependencies of the specified class.
     * @param string $class class name, interface name or alias name
     * @return array the dependencies of the specified class.
     */
    protected function getDependencies($class)
    {
        if (isset($this->_reflections[$class])) {
            return [$this->_reflections[$class], $this->_dependencies[$class]];
        }

        $dependencies = [];
        $reflection = new ReflectionClass($class);

        $constructor = $reflection->getConstructor();
        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $param) {
                if ($param->isDefaultValueAvailable()) {
                    $dependencies[] = $param->getDefaultValue();
                } else {
                    $c = $param->getClass();
                    $dependencies[] = Instance::of($c === null ? null : $c->getName());
                }
            }
        }

        $this->_reflections[$class] = $reflection;
        $this->_dependencies[$class] = $dependencies;

        return [$reflection, $dependencies];
    }

    /**
     * Resolves dependencies by replacing them with the actual object instances.
     * @param array $dependencies the dependencies
     * @param ReflectionClass $reflection the class reflection associated with the dependencies
     * @return array the resolved dependencies
     * @throws Exception
     */
    protected function resolveDependencies($dependencies, $reflection = null)
    {
        foreach ($dependencies as $index => $dependency) {
            if ($dependency instanceof Instance) {
                if ($dependency->id !== null) {
                    $dependencies[$index] = $this->get($dependency->id);
                } elseif ($reflection !== null) {
                    $name = $reflection->getConstructor()->getParameters()[$index]->getName();
                    $class = $reflection->getName();
                    throw new Exception("Missing required parameter \"$name\" when instantiating \"$class\".");
                }
            }
        }
        return $dependencies;
    }

    /**
     * Invoke a callback with resolving dependencies in parameters.
     *
     * @param callable $callback callable to be invoked.
     * @param array $params The array of parameters for the function.
     * This can be either a list of parameters, or an associative array representing named function parameters.
     * @return mixed the callback return value.
     * @throws Exception
     * @throws NotInstantiableException
     */
    public function invoke(callable $callback, $params = [])
    {
        if (is_callable($callback)) {
            return call_user_func_array($callback, $this->resolveCallableDependencies($callback, $params));
        } else {
            return call_user_func_array($callback, $params);
        }
    }

    /**
     * Resolve dependencies for a function.
     *
     * This method can be used to implement similar functionality as provided by [[invoke()]] in other
     * components.
     *
     * @param callable $callback callable to be invoked.
     * @param array $params The array of parameters for the function, can be either numeric or associative.
     * @return array The resolved dependencies.
     * @throws Exception
     * @throws NotInstantiableException
     */
    public function resolveCallableDependencies(callable $callback, $params = [])
    {
        if (is_array($callback)) {
            $reflection = new \ReflectionMethod($callback[0], $callback[1]);
        } else {
            $reflection = new \ReflectionFunction($callback);
        }

        $args = [];

        $associative = ArrayHelper::isAssociative($params);

        foreach ($reflection->getParameters() as $param) {
            $name = $param->getName();
            if (($class = $param->getClass()) !== null) {
                $className = $class->getName();
                if ($associative && isset($params[$name]) && $params[$name] instanceof $className) {
                    $args[] = $params[$name];
                    unset($params[$name]);
                } elseif (!$associative && isset($params[0]) && $params[0] instanceof $className) {
                    $args[] = array_shift($params);
                } elseif (isset(Msf::$app) && Msf::$app->has($name) && ($obj = Msf::$app->get($name)) instanceof $className) {
                    $args[] = $obj;
                } else {
                    // If the argument is optional we catch not instantiable exceptions
                    try {
                        $args[] = $this->get($className);
                    } catch (NotInstantiableException $e) {
                        if ($param->isDefaultValueAvailable()) {
                            $args[] = $param->getDefaultValue();
                        } else {
                            throw $e;
                        }
                    }

                }
            } elseif ($associative && isset($params[$name])) {
                $args[] = $params[$name];
                unset($params[$name]);
            } elseif (!$associative && count($params)) {
                $args[] = array_shift($params);
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } elseif (!$param->isOptional()) {
                $funcName = $reflection->getName();
                throw new Exception("Missing required parameter \"$name\" when calling \"$funcName\".");
            }
        }

        foreach ($params as $value) {
            $args[] = $value;
        }
        return $args;
    }

    /**
     * Registers class definitions within this container.
     *
     * @param array $definitions array of definitions. There are two allowed formats of array.
     */
    public function setDefinitions(array $definitions)
    {
        foreach ($definitions as $class => $definition) {
            if (count($definition) === 2 && array_values($definition) === $definition) {
                $this->set($class, $definition[0], $definition[1]);
                continue;
            }

            $this->set($class, $definition);
        }
    }

    /**
     * Registers class definitions as singletons within this container by calling [[setSingleton()]]
     *
     * @param array $singletons array of singleton definitions. See [[setDefinitions()]]
     * for allowed formats of array.
     */
    public function setSingletons(array $singletons)
    {
        foreach ($singletons as $class => $definition) {
            if (count($definition) === 2 && array_values($definition) === $definition) {
                $this->setSingleton($class, $definition[0], $definition[1]);
                continue;
            }

            $this->setSingleton($class, $definition);
        }
    }
}