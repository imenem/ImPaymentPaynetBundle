<?php

namespace Im\PaymentPaynetBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This is the class that validates and merges configuration from your app/config files
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html#cookbook-bundles-extension-config-class}
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritDoc}
     */
    public function getConfigTreeBuilder()
    {
        return (new TreeBuilder())
                ->root('im_payment_paynet')
                    ->children()
                        ->scalarNode('endpoint_id')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('merchant_key')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('sandbox_gateway')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('production_gateway')->isRequired()->cannotBeEmpty()->end()
                        ->booleanNode('debug')->defaultValue('%kernel.debug%')->end()
                    ->end()
                ->end();
    }
}
