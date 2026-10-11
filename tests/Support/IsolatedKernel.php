<?php
declare(strict_types=1);
namespace App\Tests\Support;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/** Never connects HTTP tests to the configured application database or SMTP. */
final class IsolatedKernel extends \App\Kernel
{
    use \Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait { configureContainer as private configureBaseContainer; }
    public function __construct(private readonly string $directory) { parent::__construct('test', true); }
    public function getProjectDir(): string { return dirname(__DIR__, 2); }
    public function getCacheDir(): string { return $this->directory.'/cache'; }
    public function getLogDir(): string { return $this->directory.'/logs'; }
    protected function configureContainer(ContainerConfigurator $container): void
    {
        $this->configureBaseContainer($container);
        $container->parameters()->set('env(MAILER_FROM_ADDRESS)', 'qa@example.invalid');
        $container->parameters()->set('env(MAILER_FROM_NAME)', 'QA local');
        $container->extension('doctrine', ['dbal' => ['url' => 'sqlite:///'.str_replace('\\', '/', $this->directory).'/qa.sqlite', 'dbname_suffix' => '']]);
        $container->extension('framework', ['mailer' => ['dsn' => 'null://null']]);
        $container->extension('framework', ['router' => ['default_uri' => 'http://localhost']]);
    }
}
