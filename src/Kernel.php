<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Overrides MicroKernelTrait's default (which only globs config/packages/)
     * to also import config/admin/*.yaml and config/datagrid/*.yaml: that's
     * where sidus/admin-bundle and sidus/datagrid-bundle expect their
     * per-entity configuration to live (see the Sidus*Bundle docs/demo app),
     * outside the config/packages/ convention.
     */
    private function configureContainer(ContainerConfigurator $container): void
    {
        $configDir = preg_replace('{/config$}', '/{config}', $this->getConfigDir());

        $container->import($configDir.'/{packages}/*.{php,yaml}');
        $container->import($configDir.'/{packages}/'.$this->environment.'/*.{php,yaml}');
        $container->import($configDir.'/{admin}/*.{php,yaml}');
        $container->import($configDir.'/{datagrid}/*.{php,yaml}');

        if (is_file($this->getConfigDir().'/services.yaml')) {
            $container->import($configDir.'/services.yaml');
            $container->import($configDir.'/{services}_'.$this->environment.'.yaml');
        } else {
            $container->import($configDir.'/{services}.php');
            $container->import($configDir.'/{services}_'.$this->environment.'.php');
        }
    }
}
