<?php

namespace Yiisoft\Di;

use Psr\Container\ContainerInterface;
use Yiisoft\Di\Contracts\DeferredServiceProviderInterface;
use Yiisoft\Di\Contracts\ServiceProviderInterface;
use Yiisoft\Factory\Definitions\Reference;
use Yiisoft\Factory\Exceptions\CircularReferenceException;
use Yiisoft\Factory\Exceptions\InvalidConfigException;
use Yiisoft\Factory\Exceptions\NotFoundException;
use Yiisoft\Factory\Exceptions\NotInstantiableException;
use Yiisoft\Factory\Definitions\DefinitionInterface;
use Yiisoft\Factory\Definitions\Normalizer;
use Yiisoft\Factory\Definitions\ArrayDefinition;

/**
 * Container implements a [dependency injection](http://en.wikipedia.org/wiki/Dependency_injection) container.
 */
class Container extends AbstractContainerConfigurator implements ContainerInterface
{
    /**
     * @var DefinitionInterface[] object definitions indexed by their types
     */
    private array $definitions = [];

    private array $assignedCacheTags = [];

    private array $cacheTags = [];
    /**
     * @var array used to collect ids instantiated during build
     * to detect circular references
     */
    private array $building = [];

    private ?ContainerInterface $rootContainer = null;

    /**
     * @var object[]
     */
    protected $instances;

    /**
     * Container constructor.
     *
     * @param array $definitions
     * @param ServiceProviderInterface[] $providers
     *
     * @throws InvalidConfigException
     * @throws NotInstantiableException
     */
    public function __construct(
        array $definitions = [],
        array $providers = [],
        ContainerInterface $rootContainer = null
    ) {
        $this->setMultiple($definitions);
        $this->addProviders($providers);
        if ($rootContainer !== null) {
            $this->delegateLookup($rootContainer);
        }
    }

    /**
     * Returns a value indicating whether the container has the definition of the specified name.
     * @param string $id class name, interface name or alias name
     * @return bool whether the container is able to provide instance of class specified.
     * @see set()
     */
    public function has($id): bool
    {
        return isset($this->definitions[$id]) || class_exists($id);
    }

    /**
     * Returns an instance by either interface name or alias.
     *
     * Same instance of the class will be returned each time this method is called.
     *
     * @param string|Reference $id the interface or an alias name that was previously registered via [[set()]].
     * @param array $parameters parameters to set for the object obtained
     * @return object an instance of the requested interface.
     * @throws CircularReferenceException
     * @throws InvalidConfigException
     * @throws NotFoundException
     * @throws NotInstantiableException
     */
    public function get($id, array $parameters = [])
    {
        $id = $this->getId($id);
        $this->invalidateCache($id);
        if (!isset($this->instances[$id])) {
            $this->instances[$id] = $this->build($id, $parameters);
            $this->assignCacheTag($id);
        }

        return $this->instances[$id];
    }

    /**
     * Delegate service lookup to another container.
     * @param ContainerInterface $container
     */
    protected function delegateLookup(ContainerInterface $container): void
    {
        if ($this->rootContainer === null) {
            $this->rootContainer = new CompositeContainer();
        }

        $this->rootContainer->attach($container);
    }

    /**
     * Sets a definition to the container. Definition may be defined multiple ways.
     * @param string $id
     * @param mixed $definition
     * @throws InvalidConfigException
     * @see `Normalizer::normalize()`
     */
    protected function set(string $id, $definition): void
    {
        if ($this->hasCacheTag($definition)) {
            $this->cacheTags[$id] = $this->extractCacheTag($definition);
            $definition = $this->clearDefinitionCacheTag($definition);
        }
        $this->instances[$id] = null;
        $this->definitions[$id] = Normalizer::normalize($definition, $id);
    }

    /**
     * Sets multiple definitions at once.
     * @param array $config definitions indexed by their ids
     * @throws InvalidConfigException
     */
    protected function setMultiple(array $config): void
    {
        foreach ($config as $id => $definition) {
            $this->set($id, $definition);
        }
    }

    /**
     * Creates new instance by either interface name or alias.
     *
     * @param string $id the interface or an alias name that was previously registered via [[set()]].
     * @param array $params
     * @return object new built instance of the specified class.
     * @throws CircularReferenceException
     * @throws InvalidConfigException
     * @throws NotFoundException
     * @internal
     */
    protected function build(string $id, array $params = [])
    {
        if (isset($this->building[$id])) {
            throw new CircularReferenceException(sprintf(
                'Circular reference to "%s" detected while building: %s',
                $id,
                implode(',', array_keys($this->building))
            ));
        }

        $this->building[$id] = 1;
        $object = $this->buildInternal($id, $params);
        unset($this->building[$id]);

        return $object;
    }

    protected function processDefinition($definition): void
    {
        if ($definition instanceof DeferredServiceProviderInterface) {
            $definition->register($this);
        }
    }

    private function extractCacheTag(array $definition)
    {
        return $definition['__cacheTag'];
    }

    private function hasCacheTag($definition): bool
    {
        if (is_array($definition) && isset($definition['__cacheTag'])) {
            if (!is_callable($definition['__cacheTag'])) {
                throw new \RuntimeException("Definition array key '__cacheTag' must be callable.");
            }

            return true;
        }

        return false;
    }

    private function clearDefinitionCacheTag(array $definition)
    {
        unset($definition['__cacheTag']);
        if (isset($definition['__definition'])) {
            $definition = $definition['__definition'];
        }

        return $definition;
    }

    private function getCacheTag(string $id): string
    {
        return ($this->cacheTags[$id])($this);
    }

    private function assignCacheTag(string $id): void
    {
        if (isset($this->cacheTags[$id])) {
            $this->assignedCacheTags[$id] = $this->getCacheTag($id);
        }
    }

    private function invalidateCache($id): void
    {
        if (isset($this->cacheTags[$id])) {
            $currentTag = $this->getCacheTag($id);
            $instance = $this->instances[$id];

            if (isset($this->assignedCacheTags[$id]) && $currentTag !== $this->assignedCacheTags[$id]) {
                if ($instance instanceof Resetable) {
                    $instance->reset();
                    $this->assignCacheTag($id);
                    return;
                }
                unset($this->instances[$id]);
            }
        }
    }

    private function getId($id): string
    {
        return is_string($id) ? $id : $id->getId();
    }

    /**
     * @param string $id
     * @param array $params
     *
     * @return mixed|object
     * @throws InvalidConfigException
     * @throws NotFoundException
     */
    private function buildInternal(string $id, array $params = [])
    {
        if (!isset($this->definitions[$id])) {
            return $this->buildPrimitive($id, $params);
        }
        $this->processDefinition($this->definitions[$id]);

        return $this->definitions[$id]->resolve($this->rootContainer ?? $this, $params);
    }

    /**
     * @param string $class
     * @param array $params
     *
     * @return mixed|object
     * @throws InvalidConfigException
     * @throws NotFoundException
     */
    private function buildPrimitive(string $class, array $params = [])
    {
        if (class_exists($class)) {
            $definition = new ArrayDefinition($class);

            return $definition->resolve($this->rootContainer ?? $this, $params);
        }

        throw new NotFoundException("No definition for $class");
    }

    private function addProviders(array $providers): void
    {
        foreach ($providers as $provider) {
            $this->addProvider($provider);
        }
    }

    /**
     * Adds service provider to the container. Unless service provider is deferred
     * it would be immediately registered.
     *
     * @param string|array $providerDefinition
     *
     * @throws InvalidConfigException
     * @throws NotInstantiableException
     * @see ServiceProviderInterface
     * @see DeferredServiceProviderInterface
     */
    private function addProvider($providerDefinition): void
    {
        $provider = $this->buildProvider($providerDefinition);

        if ($provider instanceof DeferredServiceProviderInterface) {
            foreach ($provider->provides() as $id) {
                $this->definitions[$id] = $provider;
            }
        } else {
            $provider->register($this);
        }
    }

    /**
     * Builds service provider by definition.
     *
     * @param string|array $providerDefinition class name or definition of provider.
     * @return ServiceProviderInterface instance of service provider;
     *
     * @throws InvalidConfigException
     */
    private function buildProvider($providerDefinition): ServiceProviderInterface
    {
        $provider = Normalizer::normalize($providerDefinition)->resolve($this);
        if (!($provider instanceof ServiceProviderInterface)) {
            throw new InvalidConfigException(
                'Service provider should be an instance of ' . ServiceProviderInterface::class
            );
        }

        return $provider;
    }
}
