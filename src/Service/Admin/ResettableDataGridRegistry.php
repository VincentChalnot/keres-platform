<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Sidus\DataGridBundle\Model\DataGrid;
use Sidus\DataGridBundle\Registry\DataGridRegistry;
use Symfony\Contracts\Service\ResetInterface;

/**
 * sidus/datagrid-bundle's DataGridRegistry memoizes built DataGrid objects
 * by code and never rebuilds them — correct under classic per-request
 * PHP-FPM (the container, and thus the registry, is destroyed after every
 * request) but wrong under this platform's persistent FrankenPHP *worker*
 * mode, where the container survives many requests. On the second hit
 * against any admin datagrid, the stale cached DataGrid's QueryHandler has
 * already run once and trips its internal "pager already applied" guard.
 *
 * Decorates (composition, not a same-ID class override — the bundle's own
 * Resources/config/services.yaml PSR-4-globs and re-registers the vanilla
 * class under this exact ID during its own extension load, which would
 * silently clobber a same-ID override depending on load order) the
 * fully-configured vanilla registry, snapshots its pristine per-code
 * configuration via reflection at construction time (guaranteed to run
 * after every config/datagrid/*.yaml entry has been registered but before
 * any DataGrid has actually been built/consumed), and restores it via
 * Symfony's kernel.reset mechanism — see config/services.yaml.
 */
class ResettableDataGridRegistry extends DataGridRegistry implements ResetInterface
{
    private readonly \ReflectionProperty $dataGridsProperty;
    private readonly \ReflectionProperty $configurationsProperty;

    /** @var array<string, array<string, mixed>> */
    private readonly array $pristineConfigurations;

    public function __construct(
        private readonly DataGridRegistry $inner,
    ) {
        $reflection = new \ReflectionClass(DataGridRegistry::class);
        $this->dataGridsProperty = $reflection->getProperty('dataGrids');
        $this->configurationsProperty = $reflection->getProperty('dataGridConfigurations');
        $this->pristineConfigurations = $this->configurationsProperty->getValue($this->inner);
    }

    public function addRawDataGridConfiguration(string $code, array $configuration): void
    {
        $this->inner->addRawDataGridConfiguration($code, $configuration);
    }

    public function addDataGrid(DataGrid $dataGrid): void
    {
        $this->inner->addDataGrid($dataGrid);
    }

    public function getDataGrid(string $code): DataGrid
    {
        return $this->inner->getDataGrid($code);
    }

    public function hasDataGrid(string $code): bool
    {
        return $this->inner->hasDataGrid($code);
    }

    public function reset(): void
    {
        $this->dataGridsProperty->setValue($this->inner, []);
        $this->configurationsProperty->setValue($this->inner, $this->pristineConfigurations);
    }
}
