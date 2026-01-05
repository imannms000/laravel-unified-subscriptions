<?php

namespace Imannms000\LaravelUnifiedSubscriptions;

use Imannms000\LaravelUnifiedSubscriptions\Contracts\GatewayInterface;
use InvalidArgumentException;

class GatewayManager
{
    protected array $drivers = [];

    public function driver(string $name): GatewayInterface
    {
        if (! isset($this->drivers[$name])) {
            $this->drivers[$name] = $this->resolve($name);
        }

        return $this->drivers[$name];
    }

    protected function resolve(string $name): GatewayInterface
    {
        $config = config("subscription.gateways.{$name}");

        if (is_null($config)) {
            throw new InvalidArgumentException("Subscription gateway [{$name}] is not defined.");
        }

        $class = $config['driver'];

        if (! class_exists($class)) {
            throw new InvalidArgumentException("Gateway class [{$class}] does not exist.");
        }

        return new $class;
    }
}