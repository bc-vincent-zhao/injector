<?php
namespace Bigcommerce\Injector;

use Bigcommerce\Injector\Exception\InjectorInvocationException;
use Bigcommerce\Injector\Exception\MissingRequiredParameterException;
use Bigcommerce\Injector\Reflection\ParameterInspector;
use Pimple\Container;

/**
 * The Injector provides instantiation of objects (or invocation of methods) within the BC application and
 * automatically injects dependencies from the IoC container. It behaves as a factory for any class wiring dependencies
 * JIT which serves two primary purposes:
 *  - Binding of service definitions within the IoC container - allowing constructor signatures to define their
 *      dependencies and in most cases reducing the touch-points required for refactors.
 *  - Construction of objects with dependencies served by the IoC container during the post-bootstrap application
 *      lifecycle (such as factories building command objects dynamically) without passing around the IoC container
 *      to avoid Service Location/Implicit Dependencies
 *
 * NOTE: The second use case should ONLY apply when objects that depend on services need to be constructed dynamically.
 * You should generally strive to construct your entire dependency object graph at construction rather than dynamically
 * to ensure dependencies are clear.
 *
 * Return type hinting is provided for all constructed objects in IntelliJ/PHPStorm via the dynamicReturnTypes
 * extension. Make sure you install it if you are using the injector to provide IDE hinting.
 * @package \Bigcommerce\Injector
 */
class Injector implements InjectorInterface
{
    /**
     * @var Container
     */
    private $container;

    /**
     * Regular Expressions matching dependencies that can be automatically created using their class name, even if they
     * are not defined in the IoC Container.
     *
     * @var string[]
     */
    protected $autoCreateWhiteList = [];

    /**
     * @var ParameterInspector
     */
    private $inspector;

    /**
     * Injector constructor.
     * @param Container $container
     * @param ParameterInspector $inspector
     */
    public function __construct(Container $container, ParameterInspector $inspector)
    {
        $this->container = $container;
        $this->inspector = $inspector;
    }

    /**
     * Instantiate an object and attempt to inject the dependencies for the class by mapping constructor parameter \
     * names to objects registered within the IoC container.
     *
     * The optional $parameters passed to this method accept and will inject values based on:
     *  - Type:  [Cache::class => new RedisCache()] will inject RedisCache to each parameter typed Cache::class
     *  - Name:  ["cache" => new RedisCache()] will inject RedisCache to the parameter named $cache
     *  - Index: [ 3 => new RedisCache()] will inject RedisCache to the 4th parameter (zero index)
     *
     * @param string $className The fully qualified class name for the object we're creating
     * @param array $parameters An optional array of additional parameters to pass to the created objects constructor.
     * @return object
     * @throws InjectorInvocationException
     * @throws \InvalidArgumentException
     * @throws \ReflectionException
     */
    public function create($className, $parameters = [])
    {
        $reflectionClass = new \ReflectionClass($className);
        if (!$reflectionClass->hasMethod("__construct")) {
            //This class doesn't have a constructor
            return $reflectionClass->newInstanceWithoutConstructor();
        }
        if (!$reflectionClass->getMethod('__construct')->isPublic()) {
            throw new InjectorInvocationException(
                "Injector failed to create $className - constructor isn't public." .
                " Do you need to use a static factory method instead?"
            );
        }
        try {
            $parameters = $this->buildParameterArray(
                $this->inspector->getSignatureByReflectionClass($reflectionClass, "__construct"),
                $parameters
            );
            return $reflectionClass->newInstanceArgs($parameters);
        } catch (MissingRequiredParameterException $e) {
            throw new InjectorInvocationException(
                "Can't create $className " .
                " - __construct() missing parameter '".$e->getParameterString()."'" .
                " could not be found. Either register it as a service or pass it to create via parameters.",
                $e
            );
        } catch (InjectorInvocationException $e) {
            //Wrap the exception stack for recursive calls to aid debugging
            throw new InjectorInvocationException(
                $e->getMessage() .
                PHP_EOL . " => (Called when creating $className)",
                $e
            );
        }
    }

    /**
     * Call a method with auto dependency injection from the IoC container. This is functionally equivalent to
     * call_user_func_array with auto-wiring against the service container.
     * Note: Whilst this method is useful for dynamic dispatch i.e controller actions, generally you should be
     * calling methods concretely. Use this wisely and ensure you always document return types.
     *
     * The optional $parameters passed to this method accept and will inject values based on:
     *  - Type:  [Cache::class => new RedisCache()] will inject RedisCache to each parameter typed Cache::class
     *  - Name:  ["cache" => new RedisCache()] will inject RedisCache to the parameter named $cache
     *  - Index: [ 3 => new RedisCache()] will inject RedisCache to the 4th parameter (zero index)
     *
     * @param object $instance
     * @param string $methodName
     * @param array $parameters
     * @return mixed
     * @throws InjectorInvocationException
     * @throws \InvalidArgumentException
     */
    public function invoke($instance, $methodName, $parameters = [])
    {
        if (!is_object($instance)) {
            throw new \InvalidArgumentException(
                "Attempted Injector::invoke on a non-object: " . gettype($instance) . "."
            );
        }
        $className = get_class($instance);
        try {
            $parameters = $this->buildParameterArray(
                $this->inspector->getSignatureByClassName($className, $methodName),
                $parameters
            );
            return call_user_func_array([$instance, $methodName], $parameters);
        } catch (MissingRequiredParameterException $e) {
            throw new InjectorInvocationException(
                "Can't invoke method $className::$methodName()" .
                " - missing parameter '".$e->getParameterString()."'"  .
                " could not be found. Either register it as a service or pass it to invoke via parameters.",
                $e
            );
        } catch (\ReflectionException $e) {
            throw new InjectorInvocationException(
                "Failed to invoke $className::$methodName - method doesn't exist."
            );
        }
    }

    /**
     * Add a regular expression to match classes that the Injector is permitted to construct as dependencies for other
     * objects its creating, even if they haven't been defined in the service container.
     *
     * @param string $regex
     * @return void
     */
    public function addAutoCreate($regex)
    {
        $this->autoCreateWhiteList[] = "/^" . $regex . "$/ims";
    }

    /**
     * @return \string[]
     */
    public function getAutoCreateWhiteList()
    {
        return $this->autoCreateWhiteList;
    }

    /**
     * Check whether the Injector has been configured to allow automatic construction of the given FQCN as a dependency
     *
     * @param string $className
     * @return bool
     */
    public function canAutoCreate($className)
    {
        foreach ($this->autoCreateWhiteList as $regex) {
            if (preg_match($regex, $className)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Construct the parameter array to be passed to a method call based on its parameter signature
     * This method will hunt for dependencies to satisfy the parameter requirements in the following order:
     *  - Key name in provided parameters (named parameters)
     *  - Index in provided parameters
     *  - FQCN in provided parameters
     *  - FQCN in container
     *  - Default value against method signature
     *  - Auto create white list of classes to recursively create
     *
     * @param array $methodSignature
     * @param array $providedParameters
     * @return array
     * @throws InjectorInvocationException
     * @throws MissingRequiredParameterException
     * @throws \InvalidArgumentException
     * @throws \ReflectionException
     */
    private function buildParameterArray($methodSignature, $providedParameters)
    {
        $parameters = [];
        foreach ($methodSignature as $index => $parameterData) {
            $name = $parameterData['name'];
            $type = (isset($parameterData['type'])) ? $parameterData['type'] : false;

            if (array_key_exists($name, $providedParameters)) {
                //Dependency exists by name in providedParameters
                $parameters[$index] = $providedParameters[$name];
            } elseif (array_key_exists($index, $providedParameters)) {
                //Dependency exists by index in providedParameters
                $parameters[$index] = $providedParameters[$index];
            } elseif ($type && array_key_exists($type, $providedParameters)) {
                //Dependency exists by type (Fully Qualified Class Name) in providedParameters
                $parameters[$index] = $providedParameters[$type];
            } elseif ($type && isset($this->container[$type])) {
                //Dependency in container by type (Fully Qualified Class Name)
                $parameters[$index] = $this->container[$type];
            } elseif (array_key_exists("default", $parameterData)) {
                //Default value defined in signature
                $parameters[$index] = $parameterData['default'];
            } elseif ($this->canAutoCreate($type)) {
                //Auto create white list - recursion
                $parameters[$index] = $this->create($type);
            } else {
                throw new MissingRequiredParameterException(
                    $name,
                    $type,
                    sprintf('Could not find required parameter "%s" for method', $name)
                );
            }
        }
        return $parameters;
    }
}