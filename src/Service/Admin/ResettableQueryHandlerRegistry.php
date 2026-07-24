<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Sidus\FilterBundle\Query\Handler\Configuration\QueryHandlerConfigurationInterface;
use Sidus\FilterBundle\Query\Handler\QueryHandlerInterface;
use Sidus\FilterBundle\Registry\QueryHandlerRegistry;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Same per-worker state-leak fix as ResettableDataGridRegistry, for
 * sidus/filter-bundle's QueryHandlerRegistry (which DataGridRegistry
 * delegates to internally for the actual QueryHandler/pager objects, via a
 * synthetic '__sidus_datagrid.<code>' raw configuration re-added on every
 * DataGridRegistry::reset() + rebuild cycle).
 */
class ResettableQueryHandlerRegistry extends QueryHandlerRegistry implements ResetInterface
{
    private readonly \ReflectionProperty $queryHandlersProperty;
    private readonly \ReflectionProperty $queryHandlerConfigurationsProperty;
    private readonly \ReflectionProperty $rawConfigurationsProperty;

    /** @var array<string, array<string, mixed>> */
    private readonly array $pristineRawConfigurations;

    public function __construct(
        private readonly QueryHandlerRegistry $inner,
    ) {
        $reflection = new \ReflectionClass(QueryHandlerRegistry::class);
        $this->queryHandlersProperty = $reflection->getProperty('queryHandlers');
        $this->queryHandlerConfigurationsProperty = $reflection->getProperty('queryHandlerConfigurations');
        $this->rawConfigurationsProperty = $reflection->getProperty('rawQueryHandlerConfigurations');
        $this->pristineRawConfigurations = $this->rawConfigurationsProperty->getValue($this->inner);
    }

    public function addRawQueryHandlerConfiguration(string $code, array $configuration): void
    {
        $this->inner->addRawQueryHandlerConfiguration($code, $configuration);
    }

    public function addQueryHandlerConfiguration(QueryHandlerConfigurationInterface $queryHandlerConfiguration): void
    {
        $this->inner->addQueryHandlerConfiguration($queryHandlerConfiguration);
    }

    public function addQueryHandler(QueryHandlerInterface $queryHandler): void
    {
        $this->inner->addQueryHandler($queryHandler);
    }

    public function getQueryHandler(string $code): QueryHandlerInterface
    {
        return $this->inner->getQueryHandler($code);
    }

    public function hasQueryHandler(string $code): bool
    {
        return $this->inner->hasQueryHandler($code);
    }

    public function reset(): void
    {
        $this->queryHandlersProperty->setValue($this->inner, []);
        $this->queryHandlerConfigurationsProperty->setValue($this->inner, []);
        $this->rawConfigurationsProperty->setValue($this->inner, $this->pristineRawConfigurations);
    }
}
